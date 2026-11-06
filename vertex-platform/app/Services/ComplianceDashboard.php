<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * The Owner's compliance checklist: the 20 requirements of the compliance project, each with a STATUS the Owner (or the person they delegate to) sets, a responsible
 * person, the next action and the date it was last reviewed. Beside every item the system shows facts it can actually check (is the document published, are the
 * placeholders filled, how many assets have no confirmed licence). There is deliberately NO score or percentage and NO "compliant" label: the statuses are
 * only Complete, Pending, Needs attention, Requires legal review and Not applicable. "Complete" is the Owner's own judgement and never a legal opinion.
 */
final class ComplianceDashboard
{
    public const STATUSES = ['pending' => 'Pending', 'complete' => 'Complete', 'needs_attention' => 'Needs attention', 'legal_review' => 'Requires legal review', 'not_applicable' => 'Not applicable'];

    /** key => [title, where to work on it] */
    public const ITEMS = [
        'privacy_policy' => ['Privacy Policy', '/admin/compliance/documents/privacy-policy'],
        'terms' => ['Terms and Conditions', '/admin/compliance/documents/terms-and-conditions'],
        'refund_cancellation' => ['Refund and Cancellation Policy', '/admin/compliance/documents/refund-policy'],
        'form_consent' => ['Consent on every form', '/admin/compliance/settings/consent'],
        'cookie_consent' => ['Cookie consent', '/admin/compliance/settings/cookies'],
        'third_party' => ['Third-party service disclosure', '/admin/compliance/integrations'],
        'data_minimisation' => ['Data minimisation', '/admin/compliance/settings/deletion'],
        'unsupported_claims' => ['No unsupported claims', '/admin/site'],
        'review_authenticity' => ['Review and testimonial authenticity', '/admin/site'],
        'data_visibility' => ['Data visibility', '/admin/compliance/settings/contact'],
        'accessibility' => ['Accessibility statement and checks', '/admin/compliance/documents/accessibility-statement'],
        'media_accessibility' => ['Media accessibility (captions, transcripts)', '/admin/compliance/settings/statements'],
        'image_alt' => ['Image alternative text', '/admin/site'],
        'contrast' => ['Colour contrast (WCAG 2.2 AA)', '/admin/compliance/settings/statements'],
        'keyboard' => ['Keyboard navigation and focus', '/admin/compliance/settings/statements'],
        'business_details' => ['Business details published', '/admin/compliance/settings/business'],
        'age_children' => ['Age and children', '/admin/compliance/settings/consent'],
        'email_unsubscribe' => ['Email unsubscribe', '/admin/compliance/settings/marketing'],
        'asset_licensing' => ['Asset and licence register', '/admin/compliance/assets'],
        'data_deletion' => ['Data deletion requests', '/admin/compliance/deletion'],
    ];

    /** One check line: ok true = looks fine, false = needs work, null = information only. @return array{ok:?bool,text:string} */
    private static function c(?bool $ok, string $text): array
    {
        return ['ok' => $ok, 'text' => $text];
    }

    private static function liveDoc(string $key): bool
    {
        return LegalDocuments::live($key) !== null;
    }

    private static function docLine(string $key, string $label): array
    {
        $live = LegalDocuments::live($key);

        return $live === null ? self::c(false, $label . ' is not published yet.') : self::c(true, $label . ' is published (version ' . $live['version_no'] . ').');
    }

    /** Facts the system can check for one item. @return list<array{ok:?bool,text:string}> */
    public static function checks(string $key): array
    {
        return match ($key) {
            'privacy_policy' => [self::docLine('privacy-policy', 'The Privacy Policy'), self::c(null, 'Needs review by an Indian lawyer before it is relied on.')],
            'terms' => [self::docLine('terms-and-conditions', 'The Terms and Conditions'), self::c(null, 'Needs review by an Indian lawyer: refund, liability, IP and jurisdiction clauses.')],
            'refund_cancellation' => [self::docLine('refund-policy', 'The Refund Policy'), self::docLine('cancellation-policy', 'The Cancellation Policy'), self::c(null, 'The refund percentages must be confirmed by you and by a lawyer.')],
            'form_consent' => [self::c(ComplianceSettings::get('consent_privacy_text') !== '', 'Privacy acknowledgement wording is set.'), self::c(true, 'Every public form and sign-up has a separate privacy box and age box (covered by automated tests).'),
                self::c(Database::one('SELECT COUNT(*) c FROM consent_records')['c'] > 0 ? true : null, (int) Database::one('SELECT COUNT(*) c FROM consent_records')['c'] . ' consent records stored so far.')],
            'cookie_consent' => [self::docLine('cookie-policy', 'The Cookie Policy'), self::c(true, 'Banner offers Accept all / Reject optional / Customise; optional scripts load only after consent (automated tests).'), self::c(null, 'Version ' . ComplianceSettings::get('cookie_version') . '.')],
            'third_party' => self::thirdParty(),
            'data_minimisation' => [self::c(null, 'Review which fields each form asks for; the audit is in docs/30-COMPLIANCE-REPORT.md. No date of birth or ID document is collected.')],
            'unsupported_claims' => [self::c(self::unconfirmedPublished() === 0 ? true : false, self::unconfirmedPublished() . ' published portfolio/client item(s) have no recorded permission.'), self::c(null, 'Read every page and remove any claim you cannot prove.')],
            'review_authenticity' => [self::c(true, 'The platform has no testimonial or star-rating feature and the seeded content has none.'), self::c(null, 'Only add a real review you can prove, with the reviewer\'s permission.')],
            'data_visibility' => [self::c(null, 'Public pages show only what is on the pages; the team and client dashboards are behind sign-in and permissions (route sweep tests).')],
            'accessibility' => [self::docLine('accessibility-statement', 'The Accessibility Statement'), self::c(ComplianceSettings::get('accessibility_reviewed') !== '', ComplianceSettings::get('accessibility_reviewed') !== '' ? 'Last reviewed: ' . ComplianceSettings::get('accessibility_reviewed') . '.' : 'No review date recorded.')],
            'media_accessibility' => [self::c(null, 'The site has no video or audio. If you add any, add captions and a transcript first.')],
            'image_alt' => [self::c(true, 'Every image in the templates has an alt attribute (automated test); decorative ones are empty on purpose. Alt text you type for uploaded pictures is up to you.')],
            'contrast' => [self::c(true, 'The text and link colours of both themes are checked against 4.5:1 by an automated test.'), self::c(null, 'A manual review with a real browser and a screen reader is still recommended.')],
            'keyboard' => [self::c(true, 'Skip link, visible focus, and banner/dialog focus handling are in place (automated checks).'), self::c(null, 'A manual keyboard and screen-reader pass is still recommended.')],
            'business_details' => self::businessDetails(),
            'age_children' => [self::c(true, 'Minimum age is ' . ComplianceSettings::int('min_age') . '; every public form asks for confirmation. No date of birth is collected.'), self::c(null, 'Your lawyer should confirm how to treat minors.')],
            'email_unsubscribe' => [self::c(null, Marketing::enabled() ? 'Marketing email is switched ON: every marketing email carries an unsubscribe link.' : 'Marketing email is OFF: nothing marketing is sent and no marketing box is shown.')],
            'asset_licensing' => self::assetChecks(),
            'data_deletion' => [self::c(true, 'The request page, email check and Owner review are in place.'), self::c(((int) Database::one("SELECT COUNT(*) c FROM deletion_requests WHERE status NOT IN ('completed','rejected','withdrawn')")['c']) === 0 ? true : false, (int) Database::one("SELECT COUNT(*) c FROM deletion_requests WHERE status NOT IN ('completed','rejected','withdrawn')")['c'] . ' open request(s).'),
                self::c(ComplianceSettings::get('retention_summary') !== '', 'Retention summary ' . (ComplianceSettings::get('retention_summary') !== '' ? 'is written.' : 'is empty: confirm the retention periods with your accountant or lawyer.'))],
            default => [],
        };
    }

    private static function thirdParty(): array
    {
        $rows = Integrations::all();
        $unreviewed = count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'active' && ($r['last_reviewed'] ?? null) === null));
        $warn = Integrations::warnings();

        return [self::c($warn === [], $warn === [] ? 'The register matches the technical switches.' : implode(' ', $warn)), self::c($unreviewed === 0, $unreviewed . ' service(s) in use have never been reviewed by you.')];
    }

    private static function businessDetails(): array
    {
        $miss = ComplianceSettings::missingFacts();
        $unapproved = count(array_filter(PendingActions::all(), static fn (array $p): bool => $p['type'] === 'approve' && str_contains($p['text'], 'NOT shown publicly')));

        return [self::c($miss === [], $miss === [] ? 'The required facts are filled in.' : 'Still empty: ' . implode(', ', $miss) . '.'),
            self::c($unapproved === 0, $unapproved . ' recorded fact(s) are not yet approved for public display (they stay internal until you approve them).'),
            self::c(null, 'The registration numbers were supplied by you; the system cannot verify them against the Udyam or Shop Act registers. Check them against your certificates.')];
    }

    private static function assetChecks(): array
    {
        $a = AssetRegister::summary();

        return [self::c($a['unverified'] === 0 && $a['replace'] === 0, $a['unverified'] . ' asset(s) without a confirmed licence, ' . $a['replace'] . ' marked to replace, ' . $a['confirmed'] . ' confirmed.')];
    }

    /** Published portfolio or client items whose permission to display was not recorded. */
    public static function unconfirmedPublished(): int
    {
        return (int) Database::one("SELECT (SELECT COUNT(*) FROM site_portfolio WHERE status = 'published' AND permission_confirmed = 0) + (SELECT COUNT(*) FROM site_clients WHERE status = 'published' AND permission_confirmed = 0) AS c")['c'];
    }

    /** @return list<array<string,mixed>> */
    public static function items(): array
    {
        $saved = [];
        foreach (Database::all('SELECT * FROM compliance_items') as $r) {
            $saved[$r['item_key']] = $r;
        }
        $out = [];
        $n = 0;
        foreach (self::ITEMS as $key => [$title, $link]) {
            $row = $saved[$key] ?? ['status' => 'pending', 'responsible' => null, 'next_action' => null, 'notes' => null, 'last_reviewed_at' => null, 'updated_at' => null];
            $out[] = $row + ['item_key' => $key, 'no' => ++$n, 'title' => $title, 'link' => $link, 'checks' => self::checks($key)];
        }

        return $out;
    }

    /** @return array<string,int> count per status */
    public static function counts(): array
    {
        $c = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (self::items() as $i) {
            $c[$i['status']]++;
        }

        return $c;
    }

    /** @param array<string,mixed> $in */
    public static function update(int $actorId, string $key, array $in): void
    {
        Gate::authorize('compliance.manage', $actorId);
        if (!isset(self::ITEMS[$key])) {
            throw new HttpException(404);
        }
        $status = (string) ($in['status'] ?? '');
        if (!isset(self::STATUSES[$status])) {
            throw new ValidationException(['Choose a status.']);
        }
        $resp = mb_substr(trim((string) ($in['responsible'] ?? '')), 0, 120);
        $next = mb_substr(trim((string) ($in['next_action'] ?? '')), 0, 255);
        $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 4000);
        $old = Database::one('SELECT status FROM compliance_items WHERE item_key = ?', [$key]);
        Database::transaction(function () use ($actorId, $key, $status, $resp, $next, $notes, $old): void {
            Database::run(
                'INSERT INTO compliance_items (item_key, status, responsible, next_action, notes, last_reviewed_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), responsible = VALUES(responsible), next_action = VALUES(next_action), notes = VALUES(notes), last_reviewed_at = UTC_TIMESTAMP(), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
                [$key, $status, $resp === '' ? null : $resp, $next === '' ? null : $next, $notes === '' ? null : $notes, $actorId],
            );
            Audit::record('compliance.item_updated', 'compliance_item', null, ['status' => $old['status'] ?? 'pending'], ['status' => $status], ['item' => $key], $actorId);
        });
    }
}
