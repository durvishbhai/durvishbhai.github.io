<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\AppTime;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\LegalDocuments;

/** For tests that publish a legal document: approves every fact for public display and records a (fictional) lawyer review of the current draft, as the Owner must do for real. */
trait LegalTestSupport
{
    protected function approveFacts(): void
    {
        ComplianceSettings::save($this->owner, 'disclosure', array_fill_keys(['approve_proprietor', 'approve_operator', 'approve_udyam', 'approve_shop_act', 'approve_email', 'approve_whatsapp', 'approve_grievance'], '1'));
    }

    protected function reviewDraft(string $key): void
    {
        $d = LegalDocuments::document($key);
        $draft = Database::one("SELECT * FROM legal_document_versions WHERE document_id = ? AND status IN ('draft','scheduled') ORDER BY version_no DESC LIMIT 1", [$d['id']]);
        if ($draft !== null && $d['flags'] !== [] && LegalDocuments::reviewFor((int) $d['id'], (string) $draft['body_html']) === null) {
            LegalDocuments::recordReview($this->owner, $key, 'Test Lawyer (fictional)', AppTime::today(), '');
        }
    }

    /** Publishes as the Owner would after approving the facts and recording the review. @param array<string,mixed> $opts */
    protected function pub(string $key, array $opts = []): int
    {
        $this->approveFacts();
        $this->reviewDraft($key);

        return LegalDocuments::publish($this->owner, $key, $opts);
    }
}
