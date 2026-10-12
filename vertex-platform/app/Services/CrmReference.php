<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * Human-readable, unique, race-free reference numbers per scope and calendar year:
 *   enquiry -> ENQ-2026-000123      project -> VIS-2026-0042   deliverable -> DLV-2026-0007   revision -> REV-2026-00012   change request -> CHG-2026-00003
 * Uses a single atomic upsert (LAST_INSERT_ID trick), so concurrent requests never get the same number. Call inside the
 * transaction that creates the record so a rollback does not leave a visible gap in practice.
 */
final class CrmReference
{
    private const FORMAT = [
        'enquiry' => ['ENQ', 6],
        'project' => ['VIS', 4],
        'deliverable' => ['DLV', 4],
        'revision' => ['REV', 5],
        'change' => ['CHG', 5],
        'bug' => ['BUG', 5],
        'ticket' => ['TKT', 5],
        'partner' => ['PRT', 4],
        'partner_app' => ['PAP', 5],
        'partner_ref' => ['REF', 5],
        'commission' => ['COM', 5],
        'payout' => ['PYO', 5],
        'client_payment' => ['RCP', 5],
        'renewal' => ['RNW', 5],
    ];

    public static function next(string $scope, ?int $year = null): string
    {
        [$prefix, $width] = self::FORMAT[$scope] ?? throw new \InvalidArgumentException("Unknown reference scope '{$scope}'.");
        $year ??= (int) AppTime::now()->format('Y');
        Database::run(
            'INSERT INTO reference_sequences (scope, year, last_value) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)',
            [$scope, $year],
        );
        $n = Database::insertId();

        return sprintf('%s-%d-%0' . $width . 'd', $prefix, $year, $n);
    }
}
