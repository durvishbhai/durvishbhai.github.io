<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Versioned legal documents (privacy policy, terms, refund, cancellation, cookie, accessibility ...). Only the Owner (`compliance.manage`) can change them.
 *
 *   draft      the single working copy: saved freely, never shown to the public
 *   scheduled  will go live when `publish_at` passes (it is switched on the first time anyone looks after that moment: no cron needed)
 *   published  the live version (exactly one per document); effective_date is shown, publisher and time are recorded
 *   superseded the previous live versions, kept forever and restorable (a restore makes a NEW draft)
 *   withdrawn  taken offline; only allowed for documents that are not mandatory
 *
 * Text is stored as sanitised HTML with {{tokens}} (business name, proprietor, contact ...) that are filled in when shown, so a change to the business identity updates
 * every policy. A document cannot go live while it has lost a required section or while a fact it needs (proprietor, representative, email, WhatsApp) is still empty.
 */
final class LegalDocuments
{
    // ------------------------------------------------------------------------------------------------ tokens

    /** @return array<string,array{label:string,value:string,required:bool}> */
    public static function tokenMap(): array
    {
        $s = static fn (string $k): string => trim(ComplianceSettings::get($k));
        $email = ComplianceSettings::email();
        $gRaw = $s('grievance_email') !== '' ? ($s('grievance_name') !== '' ? $s('grievance_name') . ', ' : '') . $s('grievance_email') : $email;
        $grievance = ComplianceSettings::approved('grievance') || $s('grievance_email') === '' ? $gRaw : $email; // an unapproved grievance address is not shown: the official email (or nothing) is used
        $retention = ComplianceSettings::int('retention_years');
        $address = ComplianceSettings::bool('show_address') ? $s('public_address') : '';
        $days = $s('grievance_days');

        return [
            'brand' => ['label' => 'Brand name', 'value' => $s('brand_name'), 'required' => false],
            'abbr' => ['label' => 'Abbreviation', 'value' => $s('abbreviation'), 'required' => false],
            'legal_enterprise' => ['label' => 'Legal enterprise name', 'value' => $s('legal_enterprise_name'), 'required' => false],
            'structure' => ['label' => 'Legal structure', 'value' => $s('legal_structure'), 'required' => false],
            'business_units' => ['label' => 'Business units', 'value' => $s('business_units'), 'required' => false],
            'business_disclosure' => ['label' => 'Business disclosure', 'value' => ComplianceSettings::disclosure(), 'required' => false],
            'proprietor' => ['label' => 'Legal proprietor: full name', 'value' => ComplianceSettings::publicValue('proprietor_name', 'proprietor'), 'raw' => $s('proprietor_name'), 'required' => true],
            'operator' => ['label' => 'Operational representative: full name', 'value' => ComplianceSettings::publicValue('operator_name', 'operator'), 'raw' => $s('operator_name'), 'required' => true],
            'operator_role' => ['label' => 'Operational representative: role', 'value' => $s('operator_role'), 'required' => false],
            'email' => ['label' => 'Official business email', 'value' => $email, 'raw' => $s('contact_email'), 'required' => true],
            'whatsapp' => ['label' => 'Official WhatsApp number', 'value' => ComplianceSettings::whatsapp(), 'raw' => $s('whatsapp'), 'required' => true],
            'grievance_contact' => ['label' => 'Grievance / privacy contact', 'value' => $grievance, 'raw' => $gRaw, 'required' => true],
            'governing_law' => ['label' => 'Governing law', 'value' => $s('governing_law'), 'required' => false],
            'jurisdiction' => ['label' => 'Courts / place for disputes', 'value' => $s('jurisdiction'), 'required' => true],
            'grievance_days' => ['label' => 'Grievance acknowledgement target (days)', 'value' => $days, 'required' => false],
            'gst_status' => ['label' => 'GST status', 'value' => $s('gst_status'), 'required' => false],
            'address' => ['label' => 'Business address (only if published)', 'value' => $address, 'required' => false],
            'min_age' => ['label' => 'Minimum age', 'value' => (string) ComplianceSettings::int('min_age'), 'required' => false],
            'retention_years' => ['label' => 'Record retention (years)', 'value' => $retention > 0 ? (string) $retention : '', 'required' => false],
            'deletion_days' => ['label' => 'Deletion response target (days)', 'value' => (string) ComplianceSettings::int('deletion_response_days'), 'required' => false],
            'revision_days' => ['label' => 'Revision window (days)', 'value' => '14', 'required' => false],
            'advance_percent' => ['label' => 'Standard advance (%)', 'value' => '50', 'required' => false],
        ];
    }

    /** @return list<string> names of the tokens used in $html */
    public static function tokensIn(string $html): array
    {
        return preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $html, $m) ? array_values(array_unique($m[1])) : [];
    }

    /** Fills the tokens. A missing required fact becomes a visible, clearly marked placeholder (never invented text). @param array<string,string> $extra */
    public static function fill(string $html, array $extra = []): string
    {
        $map = self::tokenMap();

        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($map, $extra): string {
            $k = $m[1];
            if (isset($extra[$k])) {
                return $extra[$k]; // trusted HTML from the caller (a link)
            }
            if (!isset($map[$k])) {
                return '';
            }
            $v = $map[$k]['value'];
            if ($v === '') {
                return $map[$k]['required'] ? '<mark class="legal-missing">[Owner to add: ' . htmlspecialchars($map[$k]['label'], ENT_QUOTES) . ']</mark>' : '';
            }

            return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $html);
    }

    /** Required facts used by $html that the Owner has not filled in. @return list<string> labels */
    public static function missingIn(string $html): array
    {
        $map = self::tokenMap();
        $out = [];
        foreach (self::tokensIn($html) as $k) {
            if (isset($map[$k]) && $map[$k]['required'] && $map[$k]['value'] === '') {
                $out[] = $map[$k]['label'] . (($map[$k]['raw'] ?? '') !== '' && ($map[$k]['raw'] ?? '') !== ($map[$k]['value']) ? ' (it is filled in, but you have not approved it for public display: Compliance & legal → Public disclosure approvals)' : '');
            }
        }

        return $out;
    }

    public static function isDocument(string $key): bool
    {
        try {
            return Database::one('SELECT 1 AS x FROM legal_documents WHERE doc_key = ?', [$key]) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------------------------------------ seeding

    public static function ensureSeeded(): void
    {
        try {
            if (Database::one('SELECT 1 AS x FROM legal_documents LIMIT 1') !== null) {
                return;
            }
            Database::transaction(static function (): void {
                $old = [];
                try {
                    foreach (Database::all('SELECT slug, title, body, status, effective_date, published_at FROM site_policies') as $r) {
                        $old[(string) $r['slug']] = $r;
                    }
                } catch (\Throwable) {
                }
                $n = 0;
                foreach (LegalDefaults::documents() as $d) {
                    Database::run(
                        'INSERT INTO legal_documents (doc_key, title, kind, requires_acceptance, unpublishable, in_footer, sort_order, required_sections, review_flags, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                        [$d['key'], $d['title'], $d['kind'], $d['acceptance'] ? 1 : 0, $d['mandatory'] ? 0 : 1, $d['footer'] ? 1 : 0, ++$n * 10, json_encode($d['sections']), json_encode($d['flags'])],
                    );
                    $id = Database::insertId();
                    $legacy = $old[$d['key']] ?? ($d['key'] === 'refund-policy' ? ($old['refund-and-cancellation-policy'] ?? null) : null);
                    $v = 1;
                    // a policy the website team had already PUBLISHED keeps being the live text; the new draft becomes version 2 for review
                    if ($legacy !== null && $legacy['status'] === 'published' && !str_contains((string) $legacy['body'], '[ADMIN TO COMPLETE')) {
                        Database::run("INSERT INTO legal_document_versions (document_id, version_no, title, body_html, status, change_summary, effective_date, published_at, created_at, updated_at) VALUES (?, 1, ?, ?, 'published', 'Imported from the previous website policy', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $legacy['title'], HtmlSanitizer::clean(CmsText::render((string) $legacy['body'])), $legacy['effective_date'], $legacy['published_at'] ?? gmdate('Y-m-d H:i:s')]);
                        $v = 2;
                    }
                    Database::run("INSERT INTO legal_document_versions (document_id, version_no, title, body_html, status, change_summary, created_at, updated_at) VALUES (?, ?, ?, ?, 'draft', 'Starting draft: review, complete the Owner facts, then publish', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $v, $d['title'], HtmlSanitizer::clean($d['body'])]);
                }
            });
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('Legal documents could not be seeded: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------------------------------ reading

    /** Switches on scheduled versions whose time has come. */
    public static function sync(): void
    {
        try {
            $due = Database::all("SELECT v.id, v.document_id FROM legal_document_versions v WHERE v.status = 'scheduled' AND v.publish_at <= UTC_TIMESTAMP() ORDER BY v.publish_at");
        } catch (\Throwable) {
            return;
        }
        foreach ($due as $d) {
            Database::transaction(static function () use ($d): void {
                Database::run('SELECT id FROM legal_documents WHERE id = ? FOR UPDATE', [$d['document_id']]);
                $v = Database::one("SELECT * FROM legal_document_versions WHERE id = ? AND status = 'scheduled' FOR UPDATE", [$d['id']]);
                if ($v === null) {
                    return;
                }
                Database::run("UPDATE legal_document_versions SET status = 'superseded', updated_at = UTC_TIMESTAMP() WHERE document_id = ? AND status = 'published'", [$d['document_id']]);
                Database::run("UPDATE legal_document_versions SET status = 'published', published_at = publish_at, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$d['id']]);
                Audit::record('legal.version_went_live', 'legal_document', (int) $d['document_id'], [], ['version' => $v['version_no']], ['scheduled' => true], $v['published_by'] === null ? null : (int) $v['published_by']);
                self::afterPublish((int) $d['id']);
            });
        }
    }

    /** @return list<array<string,mixed>> */
    public static function documents(): array
    {
        self::ensureSeeded();
        self::sync();
        $rows = Database::all('SELECT * FROM legal_documents ORDER BY sort_order, id');
        foreach ($rows as &$r) {
            $r['live'] = self::liveVersion((int) $r['id']);
            $r['draft'] = Database::one("SELECT * FROM legal_document_versions WHERE document_id = ? AND status IN ('draft','scheduled') ORDER BY version_no DESC LIMIT 1", [$r['id']]);
            $r['flags'] = json_decode((string) $r['review_flags'], true) ?: [];
            $r['sections'] = json_decode((string) $r['required_sections'], true) ?: [];
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function document(string $key): ?array
    {
        self::ensureSeeded();
        $d = Database::one('SELECT * FROM legal_documents WHERE doc_key = ?', [$key]);
        if ($d === null) {
            return null;
        }
        $d['sections'] = json_decode((string) $d['required_sections'], true) ?: [];
        $d['flags'] = json_decode((string) $d['review_flags'], true) ?: [];

        return $d;
    }

    /** @return array<string,mixed>|null */
    private static function liveVersion(int $docId): ?array
    {
        return Database::one("SELECT * FROM legal_document_versions WHERE document_id = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1", [$docId]);
    }

    /** The live version of a document for the public (null when none is published). @return array<string,mixed>|null */
    public static function live(string $key): ?array
    {
        self::ensureSeeded();
        self::sync();
        $d = Database::one('SELECT id, doc_key, title, kind, requires_acceptance, in_footer FROM legal_documents WHERE doc_key = ?', [$key]);
        if ($d === null) {
            return null;
        }
        $v = self::liveVersion((int) $d['id']);

        return $v === null ? null : $v + ['doc_key' => $d['doc_key'], 'doc_title' => $d['title'], 'requires_acceptance' => $d['requires_acceptance'], 'kind' => $d['kind']];
    }

    /** @return list<array<string,mixed>> */
    public static function versions(string $key): array
    {
        $d = self::document($key) ?? throw new HttpException(404);

        return Database::all(
            'SELECT v.id, v.version_no, v.title, v.status, v.is_material, v.change_summary, v.effective_date, v.publish_at, v.published_at, v.created_at, pu.name AS published_by_name, cu.name AS created_by_name
               FROM legal_document_versions v LEFT JOIN users pu ON pu.id = v.published_by LEFT JOIN users cu ON cu.id = v.created_by WHERE v.document_id = ? ORDER BY v.version_no DESC',
            [$d['id']],
        );
    }

    /** @return array<string,mixed>|null */
    public static function version(string $key, int $no): ?array
    {
        $d = self::document($key) ?? throw new HttpException(404);

        return Database::one('SELECT * FROM legal_document_versions WHERE document_id = ? AND version_no = ?', [$d['id'], $no]);
    }

    /** @return list<array{id:string,text:string}> headings of an HTML body, for a table of contents */
    public static function toc(string $html): array
    {
        $out = [];
        if (preg_match_all('/<h2 id="([a-z0-9-]+)">(.*?)<\/h2>/s', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = ['id' => $x[1], 'text' => trim(strip_tags($x[2]))];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ writing (Owner only)

    private static function authorize(int $actorId): void
    {
        Gate::authorize('compliance.manage', $actorId);
    }

    /** @param array<string,mixed> $in title, body_html, change_summary?, is_material?, effective_date?, acceptance_days? */
    public static function saveDraft(int $actorId, string $key, array $in): int
    {
        self::authorize($actorId);
        $d = self::document($key) ?? throw new HttpException(404);
        $title = trim((string) ($in['title'] ?? ''));
        $body = HtmlSanitizer::clean((string) ($in['body_html'] ?? ''));
        $summary = trim((string) ($in['change_summary'] ?? ''));
        $effective = trim((string) ($in['effective_date'] ?? ''));
        $days = trim((string) ($in['acceptance_days'] ?? ''));
        $errors = [];
        if ($title === '' || mb_strlen($title) > 160) {
            $errors[] = 'Give the document a title of up to 160 characters.';
        }
        if ($body === '' || mb_strlen($body) > 400000) {
            $errors[] = 'The text is empty or far too long.';
        }
        if (mb_strlen($summary) > 500 || preg_match('/[<>]/', $summary)) {
            $errors[] = 'The change summary is too long or contains < or >.';
        }
        if ($effective !== '' && !DeliveryService::validDate($effective)) {
            $errors[] = 'The effective date must look like 2026-12-31.';
        }
        if ($days !== '' && (!ctype_digit($days) || (int) $days > 365)) {
            $errors[] = 'Days to accept must be a whole number up to 365.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return (int) Database::transaction(function () use ($actorId, $d, $title, $body, $summary, $effective, $days, $in): int {
            Database::run('SELECT id FROM legal_documents WHERE id = ? FOR UPDATE', [$d['id']]);
            $draft = Database::one("SELECT id, version_no, status FROM legal_document_versions WHERE document_id = ? AND status IN ('draft','scheduled') ORDER BY version_no DESC LIMIT 1 FOR UPDATE", [$d['id']]);
            if ($draft !== null && $draft['status'] === 'scheduled') {
                throw new ValidationException(['A version is scheduled. Cancel the schedule before editing it.']);
            }
            $material = !empty($in['is_material']) && $in['is_material'] !== '0' ? 1 : 0;
            $params = [$title, $body, $summary === '' ? null : $summary, $material, $effective === '' ? null : $effective, $days === '' ? null : (int) $days];
            if ($draft === null) {
                $no = (int) Database::one('SELECT COALESCE(MAX(version_no), 0) + 1 AS n FROM legal_document_versions WHERE document_id = ?', [$d['id']])['n'];
                Database::run("INSERT INTO legal_document_versions (document_id, version_no, title, body_html, status, change_summary, is_material, effective_date, acceptance_days, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", array_merge([$d['id'], $no], $params, [$actorId]));
                $vid = Database::insertId();
            } else {
                $vid = (int) $draft['id'];
                $no = (int) $draft['version_no'];
                Database::run('UPDATE legal_document_versions SET title = ?, body_html = ?, change_summary = ?, is_material = ?, effective_date = ?, acceptance_days = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', array_merge($params, [$vid]));
            }
            Audit::record('legal.draft_saved', 'legal_document', (int) $d['id'], [], ['key' => $d['doc_key'], 'version' => $no], [], $actorId);

            return $vid;
        });
    }

    /** What stops a version from going live (empty list = it can). @return list<string> */
    /** The recorded lawyer review that matches this exact text, if any. @return array<string,mixed>|null */
    public static function reviewFor(int $documentId, string $bodyHtml): ?array
    {
        return Database::one('SELECT * FROM legal_reviews WHERE document_id = ? AND body_hash = ? ORDER BY id DESC LIMIT 1', [$documentId, hash('sha256', $bodyHtml)]);
    }

    /**
     * The Owner records that an Indian lawyer reviewed the CURRENT draft text. The record is tied to a hash of that text: any later edit makes it void, so a document can
     * never be published in a form the lawyer did not see. The system does not judge the review: it only keeps the Owner's record.
     */
    public static function recordReview(int $actorId, string $key, string $reviewer, string $date, string $note): void
    {
        self::authorize($actorId);
        $d = self::document($key) ?? throw new HttpException(404);
        $v = Database::one("SELECT * FROM legal_document_versions WHERE document_id = ? AND status IN ('draft','scheduled') ORDER BY version_no DESC LIMIT 1", [$d['id']]) ?? throw new ValidationException(['There is no draft to record a review for.']);
        $reviewer = trim($reviewer);
        $note = trim($note);
        if ($reviewer === '' || mb_strlen($reviewer) > 160) {
            throw new ValidationException(['Enter the name (and firm) of the lawyer who reviewed this text.']);
        }
        if (!DeliveryService::validDate($date) || $date > AppTime::today()) {
            throw new ValidationException(['Enter the date of the review (not in the future).']);
        }
        if (mb_strlen($note) > 500) {
            throw new ValidationException(['The note is too long (500 characters at most).']);
        }
        Database::transaction(function () use ($actorId, $d, $v, $reviewer, $date, $note): void {
            Database::run('INSERT INTO legal_reviews (document_id, version_no, body_hash, reviewer, reviewed_on, note, recorded_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$d['id'], $v['version_no'], hash('sha256', (string) $v['body_html']), $reviewer, $date, $note === '' ? null : $note, $actorId]);
            Audit::record('legal.review_recorded', 'legal_document', (int) $d['id'], [], [], ['doc' => $d['doc_key'], 'version' => (int) $v['version_no'], 'reviewer' => $reviewer], $actorId);
        });
    }

    /** All reviews recorded for a document. @return list<array<string,mixed>> */
    public static function reviews(int $documentId): array
    {
        return Database::all('SELECT * FROM legal_reviews WHERE document_id = ? ORDER BY id DESC', [$documentId]);
    }

    public static function blockers(array $doc, array $version): array
    {
        $b = [];
        $ids = HtmlSanitizer::headingIds((string) $version['body_html']);
        foreach ($doc['sections'] as $sec) {
            if (!in_array($sec['id'], $ids, true)) {
                $b[] = 'The required section "' . $sec['title'] . '" is missing. It cannot be removed from this document.';
            }
        }
        foreach (self::missingIn((string) $version['body_html']) as $label) {
            $b[] = 'A fact this document needs is not available yet: ' . $label . '.';
        }
        if (($doc['flags'] ?? []) !== [] && self::reviewFor((int) $doc['id'], (string) $version['body_html']) === null) {
            $b[] = 'Requires Legal Review: this document contains clauses that an Indian lawyer must review. Record the review (lawyer, date) for this exact text before publishing. Editing the text afterwards cancels the review.';
        }
        if ((int) $version['is_material'] === 1 && trim((string) $version['change_summary']) === '') {
            $b[] = 'A material change needs a plain-language summary of what changed (it is sent to clients).';
        }

        return $b;
    }

    /** @param array<string,mixed> $opts effective_date?, is_material?, change_summary?, acceptance_days? */
    public static function publish(int $actorId, string $key, array $opts = []): int
    {
        self::authorize($actorId);
        $d = self::document($key) ?? throw new HttpException(404);
        $vid = (int) Database::transaction(function () use ($actorId, $d, $opts): int {
            Database::run('SELECT id FROM legal_documents WHERE id = ? FOR UPDATE', [$d['id']]);
            $v = Database::one("SELECT * FROM legal_document_versions WHERE document_id = ? AND status = 'draft' ORDER BY version_no DESC LIMIT 1 FOR UPDATE", [$d['id']]) ?? throw new ValidationException(['There is no draft to publish.']);
            foreach (['effective_date', 'change_summary', 'acceptance_days'] as $k) {
                if (array_key_exists($k, $opts) && $opts[$k] !== null && trim((string) $opts[$k]) !== '') {
                    $v[$k] = trim((string) $opts[$k]);
                }
            }
            if (array_key_exists('is_material', $opts)) {
                $v['is_material'] = !empty($opts['is_material']) && $opts['is_material'] !== '0' ? 1 : 0;
            }
            $blockers = self::blockers($d, $v);
            if ($blockers !== []) {
                throw new ValidationException($blockers);
            }
            Database::run("UPDATE legal_document_versions SET status = 'superseded', updated_at = UTC_TIMESTAMP() WHERE document_id = ? AND status = 'published'", [$d['id']]);
            Database::run("UPDATE legal_document_versions SET status = 'published', published_at = UTC_TIMESTAMP(), published_by = ?, is_material = ?, change_summary = ?, effective_date = ?, acceptance_days = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$actorId, $v['is_material'], $v['change_summary'] ?? null, ($v['effective_date'] ?? '') === '' ? AppTime::today() : $v['effective_date'], ($v['acceptance_days'] ?? '') === '' ? null : (int) $v['acceptance_days'], $v['id']]);
            Audit::record('legal.published', 'legal_document', (int) $d['id'], [], ['key' => $d['doc_key'], 'version' => $v['version_no'], 'material' => (int) $v['is_material']], [], $actorId);

            return (int) $v['id'];
        });
        self::afterPublish($vid);

        return $vid;
    }

    /** @param array<string,mixed> $opts */
    public static function schedule(int $actorId, string $key, string $localDateTime, array $opts = []): void
    {
        self::authorize($actorId);
        $d = self::document($key) ?? throw new HttpException(404);
        $at = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', trim($localDateTime), AppTime::zone());
        if ($at === false) {
            throw new ValidationException(['Choose the date and time to publish.']);
        }
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));
        if ($utc <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new ValidationException(['The publication time must be in the future.']);
        }
        Database::transaction(function () use ($actorId, $d, $utc, $opts): void {
            Database::run('SELECT id FROM legal_documents WHERE id = ? FOR UPDATE', [$d['id']]);
            $v = Database::one("SELECT * FROM legal_document_versions WHERE document_id = ? AND status = 'draft' ORDER BY version_no DESC LIMIT 1 FOR UPDATE", [$d['id']]) ?? throw new ValidationException(['There is no draft to schedule.']);
            foreach (['effective_date', 'change_summary', 'acceptance_days'] as $k) {
                if (!empty($opts[$k])) {
                    $v[$k] = trim((string) $opts[$k]);
                }
            }
            if (array_key_exists('is_material', $opts)) {
                $v['is_material'] = !empty($opts['is_material']) && $opts['is_material'] !== '0' ? 1 : 0;
            }
            $blockers = self::blockers($d, $v);
            if ($blockers !== []) {
                throw new ValidationException($blockers);
            }
            Database::run("UPDATE legal_document_versions SET status = 'scheduled', publish_at = ?, published_by = ?, is_material = ?, change_summary = ?, effective_date = ?, acceptance_days = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$utc->format('Y-m-d H:i:s'), $actorId, $v['is_material'], $v['change_summary'] ?? null, ($v['effective_date'] ?? '') === '' ? null : $v['effective_date'], ($v['acceptance_days'] ?? '') === '' ? null : (int) $v['acceptance_days'], $v['id']]);
            Audit::record('legal.scheduled', 'legal_document', (int) $d['id'], [], ['key' => $d['doc_key'], 'version' => $v['version_no'], 'at' => $utc->format('c')], [], $actorId);
        });
    }

    public static function cancelSchedule(int $actorId, string $key): void
    {
        self::authorize($actorId);
        $d = self::document($key) ?? throw new HttpException(404);
        $n = Database::run("UPDATE legal_document_versions SET status = 'draft', publish_at = NULL, updated_at = UTC_TIMESTAMP() WHERE document_id = ? AND status = 'scheduled'", [$d['id']])->rowCount();
        if ($n === 0) {
            throw new ValidationException(['Nothing is scheduled.']);
        }
        Audit::record('legal.schedule_cancelled', 'legal_document', (int) $d['id'], [], ['key' => $d['doc_key']], [], $actorId);
    }

    public static function discardDraft(int $actorId, string $key): void
    {
        self::authorize($actorId);
        $d = self::document($key) ?? throw new HttpException(404);
        $n = Database::run("DELETE FROM legal_document_versions WHERE document_id = ? AND status = 'draft'", [$d['id']])->rowCount();
        if ($n === 0) {
            throw new ValidationException(['There is no draft to discard.']);
        }
        Audit::record('legal.draft_discarded', 'legal_document', (int) $d['id'], [], ['key' => $d['doc_key']], [], $actorId);
    }

    /** Takes a document offline. Mandatory documents cannot be withdrawn. */
    public static function unpublish(int $actorId, string $key, string $reason): void
    {
        self::authorize($actorId);
        $d = self::document($key) ?? throw new HttpException(404);
        if ((int) $d['unpublishable'] !== 1) {
            throw new ValidationException(['"' . $d['title'] . '" is a mandatory document and cannot be taken offline. Publish a corrected version instead.']);
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 300) {
            throw new ValidationException(['Give the reason (up to 300 characters).']);
        }
        $n = Database::run("UPDATE legal_document_versions SET status = 'withdrawn', change_summary = CONCAT(COALESCE(change_summary, ''), ' [withdrawn: ', ?, ']'), updated_at = UTC_TIMESTAMP() WHERE document_id = ? AND status = 'published'", [$reason, $d['id']])->rowCount();
        if ($n === 0) {
            throw new ValidationException(['This document is not published.']);
        }
        Audit::record('legal.unpublished', 'legal_document', (int) $d['id'], [], ['key' => $d['doc_key'], 'reason' => $reason], [], $actorId);
    }

    /** Copies an earlier version into the working draft (replacing the current draft). Nothing goes live until it is published. */
    public static function restore(int $actorId, string $key, int $versionNo): void
    {
        self::authorize($actorId);
        $d = self::document($key) ?? throw new HttpException(404);
        $old = self::version($key, $versionNo) ?? throw new HttpException(404);
        self::saveDraft($actorId, $key, ['title' => $old['title'], 'body_html' => $old['body_html'], 'change_summary' => 'Restored from version ' . $versionNo, 'is_material' => 0, 'effective_date' => '', 'acceptance_days' => '']);
        Audit::record('legal.restored', 'legal_document', (int) $d['id'], [], ['key' => $d['doc_key'], 'from' => $versionNo], [], $actorId);
    }

    /** Hook after a version went live. */
    private static function afterPublish(int $versionId): void
    {
        if (class_exists(PolicyAcceptance::class)) {
            PolicyAcceptance::announce($versionId);
        }
    }
}
