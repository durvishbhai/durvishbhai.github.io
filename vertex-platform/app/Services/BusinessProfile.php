<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * The business details printed on quotations and invoices, including the tax statement. This business is not GST-registered, so documents carry the
 * statement below instead of any tax lines. The profile is copied into an invoice when it is issued, so later edits never change an issued invoice.
 */
final class BusinessProfile
{
    /** @return array<string,mixed> */
    public static function get(): array
    {
        return Database::one('SELECT * FROM business_profile WHERE id = 1') ?? ['trade_name' => '', 'tax_statement' => 'Tax is not applicable.'];
    }

    public static function isComplete(): bool
    {
        $p = self::get();

        return trim((string) $p['trade_name']) !== '' && trim((string) $p['tax_statement']) !== '';
    }

    /** @param array<string,mixed> $in */
    public static function save(int $actorId, array $in): void
    {
        Gate::authorize('billing.configure', $actorId);
        $errors = [];
        $f = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $row = [
            'trade_name' => $f(static fn () => FieldRules::line($in['trade_name'] ?? '', 'Business name', 2, 160)),
            'legal_name' => $f(static fn () => FieldRules::line($in['legal_name'] ?? '', 'Legal name', 2, 160, false)),
            'address' => $f(static fn () => FieldRules::text($in['address'] ?? '', 'Address', 5, 1000, false)),
            'email' => $f(static fn () => FieldRules::email($in['email'] ?? '', false)),
            'phone' => $f(static fn () => FieldRules::phone($in['phone'] ?? '', false)),
            'website' => $f(static fn () => FieldRules::line($in['website'] ?? '', 'Website', 4, 190, false)),
            'tax_statement' => $f(static fn () => FieldRules::line($in['tax_statement'] ?? '', 'Tax statement', 5, 255)),
            'payment_instructions' => $f(static fn () => FieldRules::text($in['payment_instructions'] ?? '', 'Payment instructions', 5, 2000, false)),
            'invoice_footer' => $f(static fn () => FieldRules::line($in['invoice_footer'] ?? '', 'Invoice footer', 2, 500, false)),
        ];
        if ($row['website'] !== null && !preg_match('#^https?://[^\s<>"]+$#i', (string) $row['website'])) {
            $errors[] = 'The website must start with http:// or https://.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        Database::transaction(function () use ($actorId, $row): void {
            $before = self::get();
            Database::run('UPDATE business_profile SET trade_name = ?, legal_name = ?, address = ?, email = ?, phone = ?, website = ?, tax_statement = ?, payment_instructions = ?, invoice_footer = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = 1',
                [$row['trade_name'], $row['legal_name'], $row['address'], $row['email'], $row['phone'], $row['website'], $row['tax_statement'], $row['payment_instructions'], $row['invoice_footer'], $actorId]);
            Audit::record('billing.profile_changed', 'business_profile', 1, ['trade_name' => $before['trade_name'], 'tax_statement' => $before['tax_statement']], ['trade_name' => $row['trade_name'], 'tax_statement' => $row['tax_statement']], ['fields_changed' => true], $actorId);
        });
    }
}
