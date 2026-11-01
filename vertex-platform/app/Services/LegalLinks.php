<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/** The footer's Legal column: every legal document and every compliance page. These links are always shown (a document still being finalised says so on its page). */
final class LegalLinks
{
    /** @return list<array{label:string,url:string}> */
    public static function footer(): array
    {
        $out = [];
        try {
            LegalDocuments::ensureSeeded();
            foreach (Database::all('SELECT doc_key, title FROM legal_documents WHERE in_footer = 1 ORDER BY sort_order, id') as $d) {
                $out[] = ['label' => (string) $d['title'], 'url' => '/' . $d['doc_key']];
            }
        } catch (\Throwable) {
        }
        foreach ([['Cookie Settings', '/cookie-settings'], ['Data Deletion Request', '/data-deletion'], ['Third-Party Services', '/third-party-services'], ['Legal Business Information', '/legal-business-information'], ['Contact & Grievance', '/contact-and-grievance']] as [$l, $u]) {
            $out[] = ['label' => $l, 'url' => $u];
        }

        return $out;
    }
}
