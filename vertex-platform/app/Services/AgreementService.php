<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Module 25: agreement templates and the document vault.
 *
 *  - Templates are versioned plain text with placeholders ({{client_name}}, {{company_name}}, {{project_title}}, {{date}} ...). An agreement copies the
 *    rendered text and its SHA-256 when it is created, so later template edits never change an agreement that was sent or signed.
 *  - ONLINE: sent to the client's portal; the client signs by typing their full name, ticking the declaration and entering their password; the company
 *    countersigns the same way (owner/administrator). Each signature records who, when, from which address/browser and the hash of the exact text signed.
 *    It is complete ("executed") only when BOTH parties have signed. This is an in-platform electronic signature, not a third-party e-sign service.
 *  - PHYSICAL: the signed paper copy is scanned and uploaded (PDF/image, new versions keep the old ones); staff record the signing date and signatories
 *    and mark it executed.
 *  - Files live in the private store; clients only ever see their own agreements, and never drafts.
 */
final class AgreementService
{
    public const PLACEHOLDERS = ['client_name', 'client_company', 'client_email', 'company_name', 'project_title', 'project_code', 'project_amount', 'date'];

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            "SELECT a.*, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name, c.contact_name, c.public_id AS client_public_id, c.user_id AS client_user_id,
                    p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, t.name AS template_name
               FROM agreements a JOIN clients c ON c.id = a.client_id LEFT JOIN projects p ON p.id = a.project_id LEFT JOIN agreement_templates t ON t.id = a.template_id WHERE a.public_id = ?",
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> */
    public static function requireStaff(int $userId, string $publicId): array
    {
        $a = Gate::can('agreements.view', $userId) ? self::find($publicId) : null;

        return $a ?? throw new HttpException(404);
    }

    /** The client's own agreement; drafts are never visible to the client. @return array<string,mixed> */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $a = Gate::can('agreements.view_own', $userId) ? self::find($publicId) : null;
        if ($a === null || $a['client_user_id'] === null || (int) $a['client_user_id'] !== $userId || $a['status'] === 'draft') {
            throw new HttpException(404);
        }

        return $a;
    }

    // ------------------------------------------------------------------------------------------------ templates

    /** @return list<array<string,mixed>> */
    public static function templates(): array
    {
        return Database::all("SELECT t.*, v.body FROM agreement_templates t JOIN agreement_template_versions v ON v.template_id = t.id AND v.version_no = t.current_version ORDER BY t.status, t.name");
    }

    /** @return array<string,mixed>|null */
    public static function template(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one('SELECT t.*, v.body FROM agreement_templates t JOIN agreement_template_versions v ON v.template_id = t.id AND v.version_no = t.current_version WHERE t.public_id = ?', [$publicId]) : null;
    }

    public static function saveTemplate(int $actorId, ?array $existing, string $name, string $body): string
    {
        Gate::authorize('agreements.manage', $actorId);
        $name = (string) FieldRules::line($name, 'Template name', 3, 160);
        $body = (string) FieldRules::text($body, 'Agreement text', 20, 60000);
        if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $body, $m)) {
            $bad = array_diff($m[1], self::PLACEHOLDERS);
            if ($bad !== []) {
                throw new ValidationException(['Unknown placeholder(s): ' . implode(', ', array_map(static fn (string $b): string => '{{' . $b . '}}', $bad)) . '. Allowed: ' . implode(', ', array_map(static fn (string $b): string => '{{' . $b . '}}', self::PLACEHOLDERS)) . '.']);
            }
        }

        return Database::transaction(function () use ($actorId, $existing, $name, $body): string {
            if ($existing === null) {
                if (Database::one('SELECT 1 AS x FROM agreement_templates WHERE name = ?', [$name]) !== null) {
                    throw new ValidationException(['A template with that name already exists.']);
                }
                $pub = uuid_hex();
                Database::run('INSERT INTO agreement_templates (public_id, name, current_version, created_by, created_at, updated_at) VALUES (?, ?, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$pub, $name, $actorId]);
                $id = Database::insertId();
                Database::run('INSERT INTO agreement_template_versions (template_id, version_no, body, changed_by, created_at) VALUES (?, 1, ?, ?, UTC_TIMESTAMP())', [$id, $body, $actorId]);
                Audit::record('agreement_template.created', 'agreement_template', $id, [], ['name' => $name], [], $actorId);

                return $pub;
            }
            $cur = Database::one('SELECT * FROM agreement_templates WHERE id = ? FOR UPDATE', [$existing['id']]);
            $old = Database::one('SELECT body FROM agreement_template_versions WHERE template_id = ? AND version_no = ?', [$cur['id'], $cur['current_version']]);
            if ($old['body'] === $body && $cur['name'] === $name) {
                return (string) $cur['public_id'];
            }
            $next = (int) $cur['current_version'] + ($old['body'] === $body ? 0 : 1);
            if ($next !== (int) $cur['current_version']) {
                Database::run('INSERT INTO agreement_template_versions (template_id, version_no, body, changed_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$cur['id'], $next, $body, $actorId]);
            }
            Database::run('UPDATE agreement_templates SET name = ?, current_version = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$name, $next, $cur['id']]);
            Audit::record('agreement_template.updated', 'agreement_template', (int) $cur['id'], ['version' => (int) $cur['current_version']], ['version' => $next], [], $actorId);

            return (string) $cur['public_id'];
        });
    }

    public static function retireTemplate(int $actorId, array $template, bool $retire): void
    {
        Gate::authorize('agreements.manage', $actorId);
        Database::run('UPDATE agreement_templates SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$retire ? 'retired' : 'active', $template['id']]);
        Audit::record($retire ? 'agreement_template.retired' : 'agreement_template.reactivated', 'agreement_template', (int) $template['id'], [], [], [], $actorId);
    }

    /** @param array<string,mixed> $client @param array<string,mixed>|null $project */
    public static function render(string $body, array $client, ?array $project): string
    {
        $biz = BusinessProfile::get();
        $map = [
            'client_name' => (string) $client['contact_name'], 'client_company' => (string) ($client['company_name'] ?? ''), 'client_email' => (string) $client['email'],
            'company_name' => (string) ($biz['legal_name'] ?: $biz['trade_name']), 'project_title' => (string) ($project['title'] ?? ''), 'project_code' => (string) ($project['code'] ?? ''),
            'project_amount' => $project !== null && $project['approved_amount_minor'] !== null ? 'Rs. ' . FieldRules::formatMinor((int) $project['approved_amount_minor']) : '', 'date' => local_date(AppTime::today()),
        ];

        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn (array $m): string => $map[$m[1]] ?? '', $body);
    }

    // ------------------------------------------------------------------------------------------------ agreements

    /** @param array<string,mixed> $in title, mode, template, project, body, expires_on */
    public static function create(int $actorId, string $clientPublicId, array $in): array
    {
        Gate::authorize('agreements.manage', $actorId);
        $client = ClientPolicy::find($clientPublicId);
        if ($client === null || $client['archived_at'] !== null) {
            throw new ValidationException(['Choose an existing client.']);
        }
        $title = (string) FieldRules::line($in['title'] ?? '', 'Title', 3, 200);
        $mode = FieldRules::choice($in['mode'] ?? '', ['online' => 'Online signature', 'physical' => 'Signed on paper'], 'signing method');
        $expires = FieldRules::date($in['expires_on'] ?? '', 'Expiry date', false, AppTime::today());
        $project = null;
        if (trim((string) ($in['project'] ?? '')) !== '') {
            $project = ProjectPolicy::find(trim((string) $in['project']));
            if ($project === null || (int) $project['client_id'] !== (int) $client['id']) {
                throw new ValidationException(["Choose one of this client's projects."]);
            }
        }
        $body = null;
        $tplId = null;
        $tplVer = null;
        $sha = null;
        $tpl = trim((string) ($in['template'] ?? '')) !== '' ? self::template(trim((string) $in['template'])) : null;
        if (trim((string) ($in['template'] ?? '')) !== '' && ($tpl === null || $tpl['status'] !== 'active')) {
            throw new ValidationException(['Choose an active template.']);
        }
        if ($mode === 'online') {
            $raw = $tpl !== null ? (string) $tpl['body'] : (string) FieldRules::text($in['body'] ?? '', 'Agreement text', 20, 60000);
            $body = self::render($raw, $client, $project);
            $sha = hash('sha256', $body);
            $tplId = $tpl['id'] ?? null;
            $tplVer = $tpl['current_version'] ?? null;
        }

        return Database::transaction(function () use ($actorId, $client, $project, $title, $mode, $expires, $body, $sha, $tplId, $tplVer): array {
            $pub = uuid_hex();
            $ref = CrmReference::next('agreement');
            Database::run(
                "INSERT INTO agreements (public_id, reference, client_id, project_id, template_id, template_version, title, mode, body, body_sha256, status, expires_on, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $ref, $client['id'], $project['id'] ?? null, $tplId, $tplVer, $title, $mode, $body, $sha, $expires, $actorId],
            );
            $id = Database::insertId();
            self::event($id, 'created', $mode === 'online' ? 'Online agreement drafted.' : 'Paper agreement recorded.', $actorId);
            Audit::record('agreement.created', 'agreement', $id, [], ['reference' => $ref, 'mode' => $mode], [], $actorId);

            return ['public_id' => $pub, 'reference' => $ref];
        });
    }

    public static function send(int $actorId, array $a): void
    {
        Gate::authorize('agreements.manage', $actorId);
        if ($a['mode'] !== 'online') {
            throw new ValidationException(['Paper agreements are not sent through the portal. Upload the signed scan instead.']);
        }
        Database::transaction(function () use ($actorId, $a): void {
            $cur = Database::one('SELECT * FROM agreements WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($cur['status'] !== 'draft') {
                throw new ValidationException(['Only a draft can be sent.']);
            }
            Database::run("UPDATE agreements SET status = 'sent', sent_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$a['id']]);
            self::event((int) $a['id'], 'sent', 'Sent to the client for signature.', $actorId);
            Audit::record('agreement.sent', 'agreement', (int) $a['id'], ['status' => 'draft'], ['status' => 'sent'], [], $actorId);
            if ($a['client_user_id']) {
                Notifier::send([(int) $a['client_user_id']], 'agreement.sent', 'Please review and sign: ' . $a['title'], $a['reference'], '/my/agreements/' . $a['public_id'], $a['project_public_id'] ? (int) Database::one('SELECT id FROM projects WHERE public_id = ?', [$a['project_public_id']])['id'] : null, 'agreement', (int) $a['id'], 'agr:' . $a['id'] . ':sent');
            }
        });
    }

    /** @param 'company'|'client' $party */
    public static function sign(int $userId, array $a, string $party, string $typedName, string $password, bool $declared, string $ip, string $ua): void
    {
        if ($party === 'client') {
            Gate::authorize('agreements.sign', $userId);
            if ($a['client_user_id'] === null || (int) $a['client_user_id'] !== $userId) {
                throw new HttpException(404);
            }
        } else {
            Gate::authorize('agreements.sign_company', $userId);
        }
        $typedName = (string) FieldRules::line($typedName, 'Your full name', 3, 160);
        if (!$declared) {
            throw new ValidationException(['Tick the box to confirm you have read the agreement and agree to sign it.']);
        }
        if (!Auth::verifyPassword($userId, $password)) {
            throw new ValidationException(['Your password was missing or incorrect. The agreement was not signed.']);
        }
        Database::transaction(function () use ($userId, $a, $party, $typedName, $ip, $ua): void {
            $cur = Database::one('SELECT * FROM agreements WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($cur['mode'] !== 'online' || !in_array($cur['status'], ['sent', 'partially_signed'], true)) {
                throw new ValidationException(['This agreement is not waiting for a signature.']);
            }
            if ($cur['expires_on'] !== null && AppTime::today() > $cur['expires_on']) {
                throw new ValidationException(['This agreement has expired. Ask for a new one.']);
            }
            if (hash('sha256', (string) $cur['body']) !== $cur['body_sha256']) {
                throw new ValidationException(['The agreement text does not match its fingerprint. Signing is blocked; contact an administrator.']);
            }
            if (Database::one('SELECT 1 AS x FROM agreement_signatures WHERE agreement_id = ? AND party = ?', [$cur['id'], $party]) !== null) {
                throw new ValidationException(['This party has already signed.']);
            }
            Database::run('INSERT INTO agreement_signatures (agreement_id, party, signer_user_id, signer_name, body_sha256, method, signed_at, ip, user_agent) VALUES (?, ?, ?, ?, ?, \'online\', UTC_TIMESTAMP(), ?, ?)',
                [$cur['id'], $party, $userId, $typedName, $cur['body_sha256'], mb_substr($ip, 0, 45), mb_substr($ua, 0, 255)]);
            $both = (int) Database::one('SELECT COUNT(*) AS n FROM agreement_signatures WHERE agreement_id = ?', [$cur['id']])['n'] === 2;
            Database::run('UPDATE agreements SET status = ?, executed_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$both ? 'executed' : 'partially_signed', $both ? gmdate('Y-m-d H:i:s') : null, $cur['id']]);
            self::event((int) $cur['id'], 'signed_' . $party, $typedName . ' signed.', $userId);
            Audit::record('agreement.signed', 'agreement', (int) $cur['id'], [], ['party' => $party, 'executed' => $both], ['body_sha256' => $cur['body_sha256']], $userId);
            if ($both) {
                self::event((int) $cur['id'], 'executed', 'Both parties have signed.', null);
            }
            $notify = $party === 'client' ? Notifier::withPermission('agreements.manage') : ($a['client_user_id'] ? [(int) $a['client_user_id']] : []);
            Notifier::send($notify, $both ? 'agreement.executed' : 'agreement.signed', ($both ? 'Agreement signed by both parties: ' : 'Signed by the ' . $party . ': ') . $a['title'], $a['reference'], ($party === 'client' ? '/agreements/' : '/my/agreements/') . $a['public_id'], null, 'agreement', (int) $a['id'], 'agr:' . $a['id'] . ':' . $party, $userId);
        });
    }

    /** Uploads a scanned signed copy (a new version each time). @param array<string,mixed> $file */
    public static function uploadScan(int $actorId, array $a, array $file, string $note, bool $requireHttpUpload = true): void
    {
        Gate::authorize('agreements.upload', $actorId);
        if (in_array($a['status'], ['cancelled', 'expired'], true)) {
            throw new ValidationException(['This agreement is closed.']);
        }
        $note = (string) (FieldRules::line($note, 'Note', 2, 255, false) ?? '');
        $meta = FileStore::accept($file, 'agreement', $requireHttpUpload);
        if (!in_array(strtolower((string) pathinfo($meta['name'], PATHINFO_EXTENSION)), ['pdf', 'png', 'jpg', 'jpeg'], true)) {
            FileStore::discard($meta);
            throw new ValidationException(['Upload the signed copy as a PDF or a photo/scan (PNG or JPG).']);
        }
        try {
            Database::transaction(function () use ($actorId, $a, $meta, $note): void {
                Database::one('SELECT id FROM agreements WHERE id = ? FOR UPDATE', [$a['id']]);
                $fid = FileStore::insert($meta, $actorId);
                $v = (int) Database::one('SELECT COALESCE(MAX(version_no), 0) AS v FROM agreement_files WHERE agreement_id = ?', [$a['id']])['v'] + 1;
                Database::run('INSERT INTO agreement_files (agreement_id, version_no, stored_file_id, kind, note, uploaded_by, created_at) VALUES (?, ?, ?, \'signed_scan\', ?, ?, UTC_TIMESTAMP())', [$a['id'], $v, $fid, $note === '' ? null : $note, $actorId]);
                self::event((int) $a['id'], 'file_uploaded', 'Version ' . $v . ' of the signed copy uploaded (' . $meta['name'] . ').', $actorId);
                Audit::record('agreement.file_uploaded', 'agreement', (int) $a['id'], [], ['version' => $v, 'sha256' => $meta['sha256']], [], $actorId);
            });
        } catch (\Throwable $e) {
            FileStore::discard($meta);
            throw $e;
        }
    }

    /** Marks a paper agreement executed once a signed scan exists. */
    public static function markPhysicalExecuted(int $actorId, array $a, string $signedOn, string $clientSigner, string $companySigner, string $note): void
    {
        Gate::authorize('agreements.sign_company', $actorId);
        if ($a['mode'] !== 'physical') {
            throw new ValidationException(['Only a paper agreement is marked executed this way.']);
        }
        $signedOn = (string) FieldRules::date($signedOn, 'Date signed', true);
        if ($signedOn > AppTime::today()) {
            throw new ValidationException(['The signing date cannot be in the future.']);
        }
        $clientSigner = (string) FieldRules::line($clientSigner, 'Client signatory', 3, 160);
        $companySigner = (string) FieldRules::line($companySigner, 'Company signatory', 3, 160);
        $note = (string) (FieldRules::line($note, 'Note', 2, 500, false) ?? '');
        Database::transaction(function () use ($actorId, $a, $signedOn, $clientSigner, $companySigner, $note): void {
            $cur = Database::one('SELECT * FROM agreements WHERE id = ? FOR UPDATE', [$a['id']]);
            if (!in_array($cur['status'], ['draft', 'sent', 'partially_signed'], true)) {
                throw new ValidationException(['This agreement is already closed.']);
            }
            if ((int) Database::one('SELECT COUNT(*) AS n FROM agreement_files WHERE agreement_id = ?', [$cur['id']])['n'] < 1) {
                throw new ValidationException(['Upload the signed scan first: an agreement is only executed when the signed copy is in the vault.']);
            }
            $sha = (string) Database::one('SELECT sf.sha256 FROM agreement_files af JOIN stored_files sf ON sf.id = af.stored_file_id WHERE af.agreement_id = ? ORDER BY af.version_no DESC LIMIT 1', [$cur['id']])['sha256'];
            foreach (['company' => $companySigner, 'client' => $clientSigner] as $party => $name) {
                Database::run('INSERT INTO agreement_signatures (agreement_id, party, signer_user_id, signer_name, body_sha256, method, signed_at) VALUES (?, ?, NULL, ?, ?, \'physical\', ?)', [$cur['id'], $party, $name, $sha, $signedOn . ' 00:00:00']);
            }
            Database::run("UPDATE agreements SET status = 'executed', executed_at = UTC_TIMESTAMP(), physical_signed_on = ?, physical_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$signedOn, $note === '' ? null : $note, $cur['id']]);
            self::event((int) $cur['id'], 'executed', 'Marked executed: signed on paper on ' . $signedOn . '.', $actorId);
            Audit::record('agreement.executed', 'agreement', (int) $cur['id'], ['status' => $cur['status']], ['status' => 'executed'], ['signed_on' => $signedOn], $actorId);
            if ($a['client_user_id']) {
                Notifier::send([(int) $a['client_user_id']], 'agreement.executed', 'Your signed agreement is in your documents: ' . $a['title'], $a['reference'], '/my/agreements/' . $a['public_id'], null, 'agreement', (int) $a['id'], 'agr:' . $a['id'] . ':executed');
            }
        });
    }

    public static function cancel(int $actorId, array $a, string $reason): void
    {
        Gate::authorize('agreements.manage', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 5, 255);
        Database::transaction(function () use ($actorId, $a, $reason): void {
            $cur = Database::one('SELECT * FROM agreements WHERE id = ? FOR UPDATE', [$a['id']]);
            if (in_array($cur['status'], ['executed', 'cancelled'], true)) {
                throw new ValidationException(['An executed or cancelled agreement cannot be cancelled.']);
            }
            Database::run("UPDATE agreements SET status = 'cancelled', cancelled_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$reason, $a['id']]);
            self::event((int) $a['id'], 'cancelled', $reason, $actorId);
            Audit::record('agreement.cancelled', 'agreement', (int) $a['id'], ['status' => $cur['status']], ['status' => 'cancelled'], ['reason_recorded' => true], $actorId);
        });
    }

    /** Daily: online agreements past their expiry date stop being signable. */
    public static function expireDue(): int
    {
        return Database::run("UPDATE agreements SET status = 'expired', updated_at = UTC_TIMESTAMP() WHERE status IN ('sent','partially_signed') AND expires_on IS NOT NULL AND expires_on < ?", [AppTime::today()])->rowCount();
    }

    // ------------------------------------------------------------------------------------------------ reading

    private static function event(int $agreementId, string $event, string $detail, ?int $actorId): void
    {
        Database::run('INSERT INTO agreement_events (agreement_id, event, detail, actor_id, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$agreementId, $event, mb_substr($detail, 0, 500), $actorId]);
    }

    /** @return list<array<string,mixed>> */
    public static function signatures(array $a): array
    {
        return Database::all('SELECT party, signer_name, method, signed_at, ip, body_sha256 FROM agreement_signatures WHERE agreement_id = ? ORDER BY signed_at, id', [$a['id']]);
    }

    /** @return list<array<string,mixed>> */
    public static function files(array $a): array
    {
        return Database::all('SELECT af.version_no, af.note, af.kind, af.created_at, sf.public_id AS file_public_id, sf.original_name, sf.size_bytes, sf.sha256, u.name AS uploaded_by_name FROM agreement_files af JOIN stored_files sf ON sf.id = af.stored_file_id LEFT JOIN users u ON u.id = af.uploaded_by WHERE af.agreement_id = ? ORDER BY af.version_no DESC', [$a['id']]);
    }

    /** @return list<array<string,mixed>> */
    public static function events(array $a): array
    {
        return Database::all('SELECT e.event, e.detail, e.created_at, u.name AS actor FROM agreement_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.agreement_id = ? ORDER BY e.id', [$a['id']]);
    }

    /** The download response for a vault file the caller may open. @param bool $latestOnly clients get the latest version only */
    public static function download(array $a, int $version, bool $latestOnly): \Vertex\Core\Response
    {
        $row = Database::one('SELECT af.version_no, sf.* FROM agreement_files af JOIN stored_files sf ON sf.id = af.stored_file_id WHERE af.agreement_id = ? AND af.version_no = ?', [$a['id'], $version]) ?? throw new HttpException(404);
        if ($latestOnly) {
            $max = (int) Database::one('SELECT MAX(version_no) AS v FROM agreement_files WHERE agreement_id = ?', [$a['id']])['v'];
            if ($version !== $max || $a['status'] !== 'executed') {
                throw new HttpException(404);
            }
        }

        return FileStore::send($row);
    }

    /** @param array<string,mixed> $f @return array{rows:list<array<string,mixed>>,total:int} */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        Gate::authorize('agreements.view', $userId);
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(a.reference LIKE ? OR a.title LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if (in_array($f['status'] ?? '', ['draft', 'sent', 'partially_signed', 'executed', 'cancelled', 'expired'], true)) {
            $w[] = 'a.status = ?';
            $p[] = $f['status'];
        }
        $cond = implode(' AND ', $w);
        $perPage = max(1, min(100, $perPage));
        $total = (int) Database::one("SELECT COUNT(*) AS n FROM agreements a JOIN clients c ON c.id = a.client_id WHERE {$cond}", $p)['n'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT a.public_id, a.reference, a.title, a.mode, a.status, a.sent_at, a.executed_at, a.expires_on, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name FROM agreements a JOIN clients c ON c.id = a.client_id WHERE {$cond} ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public static function forClient(int $userId): array
    {
        return Database::all("SELECT a.public_id, a.reference, a.title, a.mode, a.status, a.sent_at, a.executed_at, a.expires_on FROM agreements a JOIN clients c ON c.id = a.client_id WHERE c.user_id = ? AND a.status <> 'draft' ORDER BY a.id DESC", [$userId]);
    }
}
