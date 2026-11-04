<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * The Owner's single list of what is still open. Four kinds, each with a clear label:
 *   Information needed       a fact only the Owner can give is still empty
 *   Approval needed          a fact is filled in but is NOT public until the Owner approves it
 *   Requires Legal Review    a clause or document that an Indian lawyer must review before publication
 *   Needs your verification  something the Owner said they will check themselves (client permissions, asset licences, claims)
 * Nothing is hidden and nothing is invented: every row is computed from the real state.
 */
final class PendingActions
{
    public const TYPES = ['info' => 'Information needed', 'approve' => 'Approval needed', 'legal' => 'Requires Legal Review', 'verify' => 'Needs your verification'];

    /** @return list<array{type:string,text:string,link:string}> */
    public static function all(): array
    {
        $out = [];
        $add = static function (string $type, string $text, string $link) use (&$out): void {
            $out[] = ['type' => $type, 'text' => $text, 'link' => $link];
        };
        $g = static fn (string $k): string => trim(ComplianceSettings::get($k));

        // facts only the Owner can give
        foreach (['grievance_name' => 'Grievance contact: name or title (only the email was given)', 'public_address' => 'Business address (none was given; none is shown, and none is invented)'] as $k => $label) {
            if ($g($k) === '') {
                $add('info', $label, '/admin/compliance/settings/' . ($k === 'public_address' ? 'business' : 'contact'));
            }
        }
        if ($g('grievance_days') === '') {
            $add('info', 'Grievance acknowledgement target in days (optional: leave empty if you do not want to promise a time)', '/admin/compliance/settings/contact');
        }
        if ($g('retention_years') === '') {
            $add('info', 'Record retention: no number of years has been set and none is invented; the texts say "the periods required by applicable law". Ask your accountant or lawyer whether to state a number.', '/admin/compliance/settings/deletion');
        }
        if (Marketing::enabled()) {
            $add('legal', 'Marketing email is switched on: confirm the consent and unsubscribe wording with a lawyer.', '/admin/compliance/settings/marketing');
        }

        // filled in, but not public until approved
        $facts = ['proprietor' => ['proprietor_name', 'Legal proprietor\'s name'], 'operator' => ['operator_name', 'Operational representative\'s name'], 'udyam' => ['udyam_number', 'Udyam Registration Number (URN)'], 'shop_act' => ['shop_act_number', 'Shop Act number'],
            'email' => ['contact_email', 'Official business email'], 'whatsapp' => ['whatsapp', 'Official WhatsApp number'], 'grievance' => ['grievance_email', 'Grievance contact email']];
        foreach ($facts as $fact => [$key, $label]) {
            if ($g($key) !== '' && !ComplianceSettings::approved($fact)) {
                $add('approve', $label . ' is recorded but is NOT shown publicly until you approve it.', '/admin/compliance/settings/disclosure');
            }
        }

        // legal review
        if ($g('jurisdiction') !== '') {
            $add('legal', 'Governing law (' . ($g('governing_law') !== '' ? $g('governing_law') : 'not set') . ') and place of disputes (' . $g('jurisdiction') . ') are proposals only: a lawyer must confirm them before the Terms are published.', '/admin/compliance/documents/terms-and-conditions');
        }
        foreach (LegalDocuments::documents() as $d) {
            if ($d['flags'] === []) {
                continue;
            }
            $work = $d['draft'];
            $live = $d['live'];
            $reviewed = $work !== null && LegalDocuments::reviewFor((int) $d['id'], (string) $work['body_html']) !== null;
            $reviewedLive = $live !== null && LegalDocuments::reviewFor((int) $d['id'], (string) $live['body_html']) !== null;
            if ($live === null && !$reviewed) {
                $add('legal', $d['title'] . ': ' . count($d['flags']) . ' clause(s) need an Indian lawyer\'s review. It stays a draft and cannot be published until a review is recorded.', '/admin/compliance/documents/' . $d['doc_key']);
            } elseif ($live !== null && !$reviewedLive) {
                $add('legal', $d['title'] . ' is live without a recorded lawyer review.', '/admin/compliance/documents/' . $d['doc_key']);
            }
        }
        foreach (LegalDocuments::documents() as $d) {
            if ($d['live'] === null && ($d['flags'] === [] || LegalDocuments::reviewFor((int) $d['id'], (string) ($d['draft']['body_html'] ?? '')) !== null)) {
                $add('approve', $d['title'] . ' is a draft. It is published only when you press Publish.', '/admin/compliance/documents/' . $d['doc_key']);
            }
        }

        // the Owner's own checks
        $n = ComplianceDashboard::unconfirmedPublished();
        if ($n > 0) {
            $add('verify', $n . ' published portfolio or client item(s) have no recorded permission.', '/admin/site/portfolio');
        }
        $a = AssetRegister::summary();
        if ($a['unverified'] + $a['replace'] > 0) {
            $add('verify', ($a['unverified'] + $a['replace']) . ' asset(s) have no confirmed licence.', '/admin/compliance/assets');
        }
        $add('verify', 'Read every public page for claims you cannot prove, and confirm each Third-Party Services entry.', '/admin/compliance/integrations');
        $open = (int) Database::one("SELECT COUNT(*) c FROM deletion_requests WHERE status NOT IN ('completed','rejected','withdrawn')")['c'];
        if ($open > 0) {
            $add('verify', $open . ' data deletion request(s) are open.', '/admin/compliance/deletion');
        }
        foreach (Integrations::warnings() as $w) {
            $add('verify', $w, '/admin/compliance/integrations');
        }

        return $out;
    }
}
