<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Client records (individuals and businesses). A client may exist WITHOUT a portal login; a login is attached only by an
 * explicit invitation. Records are never deleted: they are deactivated, archived or merged (history kept).
 *
 * Duplicate avoidance is advisory + explicit: a probable match (same email or phone, or same normalised company name)
 * blocks creation until the staff member either links the existing record or confirms "this is a different client".
 * Nothing is ever merged automatically, and never on name similarity alone.
 */
final class ClientService
{
    /**
     * @return array{strong:list<array<string,mixed>>,weak:list<array<string,mixed>>}
     */
    public static function findDuplicates(?string $email, ?string $phoneDigits, ?string $companyNorm, ?int $excludeId = null): array
    {
        $strong = [];
        $conds = [];
        $params = [];
        if ($email !== null && $email !== '') {
            $conds[] = 'c.email = ?';
            $params[] = $email;
        }
        if ($phoneDigits !== null && strlen($phoneDigits) >= 7) {
            $conds[] = 'c.phone_digits = ?';
            $params[] = $phoneDigits;
        }
        $excl = $excludeId !== null ? ' AND c.id <> ' . (int) $excludeId : '';
        if ($conds !== []) {
            $strong = Database::all(
                "SELECT c.id, c.public_id, c.contact_name, c.company_name, c.email, c.status FROM clients c
                  WHERE (" . implode(' OR ', $conds) . ") AND c.archived_at IS NULL{$excl} ORDER BY c.id LIMIT 5",
                $params,
            );
        }
        $weak = [];
        if ($companyNorm !== null) {
            $strongIds = array_column($strong, 'id');
            $weak = array_values(array_filter(
                Database::all(
                    "SELECT c.id, c.public_id, c.contact_name, c.company_name, c.email, c.status FROM clients c
                      WHERE c.company_norm = ? AND c.archived_at IS NULL{$excl} ORDER BY c.id LIMIT 5",
                    [$companyNorm],
                ),
                static fn (array $r): bool => !in_array($r['id'], $strongIds, true),
            ));
        }

        return ['strong' => $strong, 'weak' => $weak];
    }

    /**
     * @param array<string,mixed> $in client_type, contact_name, email, phone, company_name, website, tax_id, address, confirm_new
     * @return array{id:int,public_id:string}
     */
    public static function create(int $actorId, array $in, ?int $fromEnquiryId = null): array
    {
        Gate::authorize('clients.create', $actorId);
        $d = self::validate($in);
        $dups = self::findDuplicates($d['email'], $d['phone_digits'], $d['company_norm']);
        if ($dups['strong'] !== [] && empty($in['confirm_new'])) {
            $names = implode(', ', array_map(static fn (array $r): string => $r['contact_name'] . ' <' . $r['email'] . '>', $dups['strong']));
            throw new ValidationException(["A client with the same email or phone already exists ({$names}). Open that record, or tick \"This is a different client\" to create a new one anyway."]);
        }

        return Database::transaction(function () use ($actorId, $d, $fromEnquiryId, $dups): array {
            $pub = uuid_hex();
            Database::run(
                "INSERT INTO clients (public_id, user_id, client_type, contact_name, email, phone, phone_digits, company_name, company_norm, website, tax_id, address, status, created_by, created_at, updated_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $d['client_type'], $d['contact_name'], $d['email'], $d['phone'], $d['phone_digits'], $d['company_name'], $d['company_norm'], $d['website'], $d['tax_id'], $d['address'], $actorId],
            );
            $id = Database::insertId();
            Audit::record('client.created', 'client', $id, [], ['client_type' => $d['client_type'], 'has_company' => $d['company_name'] !== null], [
                'from_enquiry' => $fromEnquiryId, 'confirmed_possible_duplicate' => $dups['strong'] !== [],
            ], $actorId);

            return ['id' => $id, 'public_id' => $pub];
        });
    }

    /** @param array<string,mixed> $in */
    public static function update(int $actorId, array $client, array $in): void
    {
        ClientPolicy::authorizeStaff($actorId, $client, 'clients.edit');
        $d = self::validate($in);
        $changed = [];
        foreach (['client_type', 'contact_name', 'email', 'phone', 'company_name', 'website', 'tax_id', 'address'] as $f) {
            if ((string) $d[$f] !== (string) $client[$f]) {
                $changed[] = $f;
            }
        }
        if ($changed === []) {
            return;
        }
        Database::transaction(function () use ($actorId, $client, $d, $changed): void {
            Database::run(
                'UPDATE clients SET client_type = ?, contact_name = ?, email = ?, phone = ?, phone_digits = ?, company_name = ?, company_norm = ?, website = ?, tax_id = ?, address = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$d['client_type'], $d['contact_name'], $d['email'], $d['phone'], $d['phone_digits'], $d['company_name'], $d['company_norm'], $d['website'], $d['tax_id'], $d['address'], $client['id']],
            );
            // Names of changed fields only: the audit trail must not become a second copy of personal data.
            Audit::record('client.updated', 'client', (int) $client['id'], [], [], ['changed_fields' => $changed], $actorId);
        });
    }

    public static function setStatus(int $actorId, array $client, string $status): void
    {
        ClientPolicy::authorizeStaff($actorId, $client, 'clients.edit');
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new ValidationException(['Choose active or inactive.']);
        }
        if ($client['status'] === 'archived') {
            throw new ValidationException(['Archived clients must be restored by an administrator first.']);
        }
        if ($client['status'] === $status) {
            return;
        }
        Database::transaction(function () use ($actorId, $client, $status): void {
            Database::run('UPDATE clients SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $client['id']]);
            Audit::record('client.status_changed', 'client', (int) $client['id'], ['status' => $client['status']], ['status' => $status], [], $actorId);
        });
    }

    /** Archive: refused while the client still has open projects (history is never lost; nothing is deleted). */
    public static function archive(int $actorId, array $client): void
    {
        Gate::authorize('clients.archive', $actorId);
        if (ClientPolicy::level($actorId, $client) !== 'all') {
            throw new HttpException(403);
        }
        $open = (int) Database::one(
            "SELECT COUNT(*) AS c FROM projects WHERE client_id = ? AND archived_at IS NULL AND status NOT IN ('completed','cancelled')",
            [$client['id']],
        )['c'];
        if ($open > 0) {
            throw new ValidationException(["This client still has {$open} open project(s). Complete or cancel them before archiving."]);
        }
        Database::transaction(function () use ($actorId, $client): void {
            Database::run("UPDATE clients SET status = 'archived', archived_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$client['id']]);
            Audit::record('client.archived', 'client', (int) $client['id'], ['status' => $client['status']], ['status' => 'archived'], [], $actorId);
        });
    }

    public static function addNote(int $actorId, array $client, string $body): void
    {
        ClientPolicy::authorizeStaff($actorId, $client, 'clients.notes_add');
        $body = (string) FieldRules::text($body, 'Note', 2, 4000);
        Database::transaction(function () use ($actorId, $client, $body): void {
            Database::run('INSERT INTO client_notes (client_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$client['id'], $actorId, $body]);
            Audit::record('client.note_added', 'client', (int) $client['id'], [], [], ['length' => mb_strlen($body)], $actorId);
        });
    }

    /** @return list<array<string,mixed>> restricted notes; empty unless the user may read them */
    public static function notes(int $userId, array $client): array
    {
        if (!ClientPolicy::canStaff($userId, $client, 'clients.notes_view')) {
            return [];
        }

        return Database::all(
            'SELECT n.id, n.body, n.created_at, u.name AS author_name FROM client_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.client_id = ? ORDER BY n.id DESC',
            [$client['id']],
        );
    }

    /**
     * Merge a duplicate client (source) INTO another (target). Explicit, admin-only, audited, nothing deleted:
     * enquiries, projects and notes are re-pointed; the source is archived with merged_into_client_id. Refused when the
     * source has a portal login (account takeover risk: merging logins needs a separate, deliberate process).
     */
    public static function merge(int $actorId, int $sourceId, int $targetId): void
    {
        Gate::authorize('clients.merge', $actorId);
        if ($sourceId === $targetId) {
            throw new ValidationException(['Choose two different clients.']);
        }
        Database::transaction(function () use ($actorId, $sourceId, $targetId): void {
            $rows = Database::all('SELECT * FROM clients WHERE id IN (?, ?) ORDER BY id FOR UPDATE', [$sourceId, $targetId]);
            $byId = [];
            foreach ($rows as $r) {
                $byId[(int) $r['id']] = $r;
            }
            if (!isset($byId[$sourceId], $byId[$targetId])) {
                throw new HttpException(404);
            }
            [$src, $dst] = [$byId[$sourceId], $byId[$targetId]];
            if ($src['user_id'] !== null) {
                throw new ValidationException(['The record to merge away has a portal login. Merge is only allowed for records without a login.']);
            }
            if ($src['archived_at'] !== null || $dst['archived_at'] !== null || $src['merged_into_client_id'] !== null || $dst['merged_into_client_id'] !== null) {
                throw new ValidationException(['Archived or already merged clients cannot be merged.']);
            }
            $enq = Database::run('UPDATE enquiries SET client_id = ? WHERE client_id = ?', [$targetId, $sourceId])->rowCount();
            $proj = Database::run('UPDATE projects SET client_id = ? WHERE client_id = ?', [$targetId, $sourceId])->rowCount();
            $notes = Database::run('UPDATE client_notes SET client_id = ? WHERE client_id = ?', [$targetId, $sourceId])->rowCount();
            Database::run(
                'INSERT INTO client_notes (client_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
                [$targetId, $actorId, "Merged duplicate client record \"{$src['contact_name']}\" <{$src['email']}> into this record ({$enq} enquiries, {$proj} projects, {$notes} notes moved)."],
            );
            Database::run(
                "UPDATE clients SET status = 'archived', archived_at = UTC_TIMESTAMP(), merged_into_client_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$targetId, $sourceId],
            );
            Audit::record('client.merged', 'client', $targetId, [], [], ['merged_client_id' => $sourceId, 'enquiries_moved' => $enq, 'projects_moved' => $proj, 'notes_moved' => $notes], $actorId);
        });
    }

    /**
     * Invite the client to the portal: creates the portal login through UserService (so all of its permission and
     * hierarchy rules apply: the actor needs users.create AND users.assign_roles too) and links it to this record.
     *
     * @return array{user_id:int,invite_sent:bool}
     */
    public static function invite(int $actorId, array $client): array
    {
        Gate::authorize('clients.invite', $actorId);
        if (ClientPolicy::level($actorId, $client) !== 'all') {
            throw new HttpException(403);
        }
        if ($client['user_id'] !== null) {
            throw new ValidationException(['This client already has a portal login.']);
        }
        if ($client['status'] !== 'active') {
            throw new ValidationException(['Only active clients can be invited.']);
        }

        return UserService::adminCreate($actorId, [
            'name' => $client['contact_name'], 'email' => $client['email'], 'phone' => $client['phone'] ?? '',
            'roles' => ['client'], 'link_client_id' => (int) $client['id'],
        ]);
    }

    // ------------------------------------------------------------------ read side (all restricted by ClientPolicy)

    /**
     * @param array{q?:string,type?:string,status?:string,archived?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page, int $perPage): array
    {
        $scope = ClientPolicy::scopeSql($userId);
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        [$sql, $params] = $scope;
        $where = [$sql];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $digits = FieldRules::phoneDigits($q);
            $where[] = '(c.contact_name LIKE ? OR c.email LIKE ? OR c.company_name LIKE ?' . ($digits !== null ? ' OR c.phone_digits = ?' : '') . ')';
            array_push($params, $like, $like, $like);
            if ($digits !== null) {
                $params[] = $digits;
            }
        }
        if (in_array($f['type'] ?? '', ['individual', 'business'], true)) {
            $where[] = 'c.client_type = ?';
            $params[] = $f['type'];
        }
        if (in_array($f['status'] ?? '', ['active', 'inactive'], true)) {
            $where[] = 'c.status = ?';
            $params[] = $f['status'];
        }
        // Archived clients are hidden by default and listed only on request, and only to those who can see everything.
        $where[] = !empty($f['archived']) && Gate::can('clients.view_all', $userId) ? "c.status = 'archived'" : "(c.archived_at IS NULL AND c.status <> 'archived')";
        $w = implode(' AND ', $where);
        $total = (int) Database::one("SELECT COUNT(*) AS n FROM clients c WHERE {$w}", $params)['n'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            "SELECT c.public_id, c.client_type, c.contact_name, c.company_name, c.email, c.phone, c.status, c.user_id, c.created_at, c.merged_into_client_id
               FROM clients c WHERE {$w} ORDER BY c.contact_name, c.id LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** Projects of this client that the viewer may see (same scope rules as the project list). @return list<array<string,mixed>> */
    public static function projectsFor(int $userId, array $client): array
    {
        return ProjectPolicy::visibleTo($userId, ['client' => (string) $client['public_id']], 1, 100)['rows'];
    }

    /** Enquiries linked to this client that the viewer may see. @return list<array<string,mixed>> */
    public static function enquiriesFor(int $userId, array $client): array
    {
        $scope = EnquiryPolicy::scopeSql($userId);
        if ($scope === null || ClientPolicy::level($userId, $client) === 'own') {
            return [];
        }
        [$sql, $params] = $scope;

        return Database::all(
            "SELECT e.public_id, e.reference, e.service, e.created_at, s.name AS stage_name FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id
              WHERE e.client_id = ? AND ({$sql}) ORDER BY e.id DESC",
            array_merge([$client['id']], $params),
        );
    }

    /** Creation/modification history (what changed, who, when; no personal data values). @return list<array<string,mixed>> */
    public static function history(int $userId, array $client): array
    {
        if (!ClientPolicy::canStaff($userId, $client, 'clients.edit')) {
            return [];
        }

        return Database::all(
            "SELECT a.created_at, a.action, a.meta_json, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
              WHERE a.entity_type = 'client' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 50",
            [$client['id']],
        );
    }

    /** @return list<array<string,mixed>> active clients usable as a merge target */
    public static function mergeTargets(int $excludeId): array
    {
        return Database::all(
            "SELECT public_id, contact_name, company_name, email FROM clients WHERE id <> ? AND status <> 'archived' AND archived_at IS NULL ORDER BY contact_name LIMIT 300",
            [$excludeId],
        );
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private static function validate(array $in): array
    {
        $type = FieldRules::choice($in['client_type'] ?? 'individual', ['individual' => 'Individual', 'business' => 'Business'], 'client type');
        $company = FieldRules::line($in['company_name'] ?? '', 'Company name', 2, 160, $type === 'business');
        $phone = FieldRules::phone($in['phone'] ?? '');
        $website = FieldRules::line($in['website'] ?? '', 'Website', 3, 190, false);
        if ($website !== null && !preg_match('#^(https?://)?[A-Za-z0-9][A-Za-z0-9.\-]*\.[A-Za-z]{2,}(/\S*)?$#', $website)) {
            throw new ValidationException(['Enter a valid website address.']);
        }

        return [
            'client_type' => $type,
            'contact_name' => FieldRules::line($in['contact_name'] ?? '', 'Contact name', 2, 120),
            'email' => FieldRules::email($in['email'] ?? ''),
            'phone' => $phone,
            'phone_digits' => FieldRules::phoneDigits($phone),
            'company_name' => $company,
            'company_norm' => FieldRules::companyNorm($company),
            'website' => $website,
            'tax_id' => FieldRules::line($in['tax_id'] ?? '', 'Tax / GST number', 5, 40, false),
            'address' => FieldRules::line($in['address'] ?? '', 'Address', 5, 500, false),
        ];
    }
}
