<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * CSV import for clients and CRM enquiries. Every row goes through the same validation and duplicate checks as the on-screen forms; a bad row is
 * reported with its line number and never stops the others. "Check only" validates without saving. The file's SHA-256, counts and the first errors
 * are logged (data_imports + audit); the rows themselves are the records created.
 */
final class DataImport
{
    public const MAX_BYTES = 1_000_000;
    public const MAX_ROWS = 1000;

    /** @return array<string,array{label:string,perm:string,columns:list<string>,required:list<string>}> */
    public static function types(): array
    {
        return [
            'clients' => ['label' => 'Clients', 'perm' => 'clients.create', 'columns' => ['contact_name', 'email', 'phone', 'company_name', 'client_type', 'website', 'tax_id', 'address'], 'required' => ['contact_name', 'email']],
            'enquiries' => ['label' => 'CRM enquiries (leads)', 'perm' => 'enquiries.create', 'columns' => ['contact_name', 'email', 'phone', 'company_name', 'service', 'description', 'budget_range', 'priority', 'expected_deadline'], 'required' => ['contact_name', 'email', 'description']],
        ];
    }

    /** @return array<string,string> */
    public static function available(int $userId): array
    {
        $out = [];
        if (Gate::can('data.import', $userId)) {
            foreach (self::types() as $k => $t) {
                if (Gate::can($t['perm'], $userId)) {
                    $out[$k] = $t['label'];
                }
            }
        }

        return $out;
    }

    public static function template(string $type): string
    {
        $def = self::types()[$type] ?? throw new HttpException(404);
        $sample = $type === 'clients'
            ? ['Asha Verma', 'asha@example.com', '+91 98765 43210', 'Verma Traders', 'business', '', '', '']
            : ['Ravi Kumar', 'ravi@example.com', '+91 91234 56780', 'Kumar Foods', 'website_development', 'We need a website with an online menu and ordering.', '', 'normal', ''];

        return Csv::build($def['columns'], [$sample]);
    }

    /**
     * @return array{total:int,imported:int,failed:int,duplicates:int,errors:list<string>,checkOnly:bool}
     */
    public static function run(int $actorId, string $type, string $filename, string $content, bool $checkOnly): array
    {
        $def = self::types()[$type] ?? throw new HttpException(404);
        Gate::authorize('data.import', $actorId);
        Gate::authorize($def['perm'], $actorId);
        if (strlen($content) > self::MAX_BYTES) {
            throw new ValidationException(['The file is too large (1 MB at most).']);
        }
        try {
            $csv = Csv::parse($content, self::MAX_ROWS);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException([$e->getMessage()]);
        }
        $missing = array_diff($def['required'], $csv['headers']);
        if ($missing !== []) {
            throw new ValidationException(['Missing column(s): ' . implode(', ', $missing) . '. Download the template to see the expected columns.']);
        }
        $unknown = array_diff($csv['headers'], $def['columns']);
        if ($unknown !== []) {
            throw new ValidationException(['Unknown column(s): ' . implode(', ', $unknown) . '. They would be ignored, so nothing was imported.']);
        }

        $imported = 0;
        $dups = 0;
        $errors = [];
        $seen = [];
        foreach ($csv['rows'] as $i => $row) {
            $line = $i + 2;
            $key = mb_strtolower($row['email'] ?? '');
            try {
                if ($key !== '' && isset($seen[$key])) {
                    throw new ValidationException(["Same email as line {$seen[$key]} in this file."]);
                }
                $seen[$key] = $line;
                if ($type === 'clients') {
                    self::clientRow($actorId, $row, $checkOnly);
                } else {
                    self::enquiryRow($actorId, $row, $checkOnly);
                }
                $imported++;
            } catch (ValidationException $e) {
                $msg = implode(' ', $e->errors);
                if (str_contains($msg, 'already exists')) {
                    $dups++;
                }
                $errors[] = "Line {$line}: {$msg}";
            }
        }
        $failed = count($csv['rows']) - $imported;
        $summary = ['total' => count($csv['rows']), 'imported' => $imported, 'failed' => $failed, 'duplicates' => $dups, 'errors' => $errors, 'checkOnly' => $checkOnly];
        if (!$checkOnly) {
            Database::transaction(function () use ($actorId, $type, $filename, $content, $summary): void {
                Database::run(
                    'INSERT INTO data_imports (public_id, kind, filename, file_sha256, total_rows, imported_rows, failed_rows, error_summary, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [uuid_hex(), $type, mb_substr(preg_replace('/[^\w. -]/u', '_', $filename) ?? 'import.csv', 0, 190), hash('sha256', $content), $summary['total'], $summary['imported'], $summary['failed'], $summary['errors'] === [] ? null : implode("\n", array_slice($summary['errors'], 0, 50)), $actorId],
                );
                Audit::record('data.imported', null, null, [], [], ['type' => $type, 'rows' => $summary['total'], 'imported' => $summary['imported'], 'failed' => $summary['failed']], $actorId);
            });
        }

        return $summary;
    }

    /** @param array<string,string> $row */
    private static function clientRow(int $actorId, array $row, bool $checkOnly): void
    {
        $in = $row + ['client_type' => '', 'phone' => '', 'company_name' => '', 'website' => '', 'tax_id' => '', 'address' => ''];
        $in['client_type'] = strtolower($in['client_type']) === 'business' || ($in['client_type'] === '' && $in['company_name'] !== '') ? 'business' : (strtolower($in['client_type']) === 'individual' || $in['client_type'] === '' ? 'individual' : $in['client_type']);
        if ($checkOnly) {
            $d = ClientService::validate($in);
            $dups = ClientService::findDuplicates($d['email'], $d['phone_digits'], $d['company_norm']);
            if ($dups['strong'] !== []) {
                throw new ValidationException(['A client with this email or phone already exists.']);
            }

            return;
        }
        ClientService::create($actorId, $in);
    }

    /** @param array<string,string> $row */
    private static function enquiryRow(int $actorId, array $row, bool $checkOnly): void
    {
        $services = (array) Config::get('workflow.services');
        $budgets = (array) Config::get('workflow.budget_ranges');
        $in = $row + ['phone' => '', 'company_name' => '', 'service' => '', 'budget_range' => '', 'priority' => '', 'expected_deadline' => ''];
        $in['service'] = self::codeFor($in['service'], $services);
        $in['budget_range'] = self::codeFor($in['budget_range'], $budgets);
        $in['priority'] = strtolower($in['priority']) ?: 'normal';
        if ($checkOnly) {
            Gate::authorize('enquiries.create', $actorId);
            EnquiryService::validate($in, 'manual');

            return;
        }
        EnquiryService::create($actorId, 'manual', $in);
    }

    /** Accepts the code ("website_development") or the label ("Website development"). @param array<string,mixed> $map */
    private static function codeFor(string $value, array $map): string
    {
        if ($value === '' || isset($map[$value])) {
            return $value;
        }
        foreach ($map as $code => $label) {
            $label = is_array($label) ? (string) ($label['label'] ?? $label['name'] ?? '') : (string) $label;
            if (mb_strtolower($label) === mb_strtolower($value)) {
                return (string) $code;
            }
        }

        return $value;
    }
}
