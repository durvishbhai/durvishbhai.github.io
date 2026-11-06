<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Response;
use Vertex\Core\ValidationException;

/**
 * Manual UPI payments. The business shows its own bank QR and UPI ID; the client pays from their UPI app, then sends the transaction ID and a screenshot as
 * proof; the team checks the bank and approves it (ClientPaymentService::claim -> verify), and only then is the payment successful.
 *
 * It is the alternative to Razorpay, never an addition: while Razorpay online payments are on, manual UPI is not offered and claims are refused.
 */
final class ManualUpi
{
    /** @return array<string,mixed> */
    public static function settings(): array
    {
        return Database::one('SELECT * FROM manual_upi_settings WHERE id = 1') ?? ['enabled' => 0, 'upi_id' => null, 'payee_name' => null, 'instructions' => null, 'require_proof' => 1, 'qr_file_id' => null, 'updated_at' => null];
    }

    /** Razorpay online payments are switched on (the switch itself: manual UPI never runs next to it, even while Razorpay is only half set up). */
    public static function razorpayOn(): bool
    {
        return (bool) Config::get('razorpay.enabled');
    }

    /** Switched on in the settings, with a UPI ID to pay to, and Razorpay is not in use. */
    public static function active(): bool
    {
        $s = self::settings();

        return (int) $s['enabled'] === 1 && trim((string) ($s['upi_id'] ?? '')) !== '' && !self::razorpayOn();
    }

    /** What the admin screen says about the current state. @return array{active:bool,reason:string} */
    public static function status(): array
    {
        $s = self::settings();
        if ((int) $s['enabled'] !== 1) {
            return ['active' => false, 'reason' => 'Manual UPI is switched off.'];
        }
        if (trim((string) ($s['upi_id'] ?? '')) === '') {
            return ['active' => false, 'reason' => 'Add the UPI ID clients should pay to.'];
        }
        if (self::razorpayOn()) {
            return ['active' => false, 'reason' => 'Razorpay online payments are switched on, so manual UPI is not used. Switch Razorpay off (Integrations & settings → Razorpay) to use manual UPI.'];
        }

        return ['active' => true, 'reason' => 'Clients see your QR and UPI ID on their invoices, pay, and send the transaction ID and a screenshot for your approval.'];
    }

    /**
     * @param array<string,mixed> $in enabled, upi_id, payee_name, instructions, require_proof
     * @param array<string,mixed>|null $qrUpload one $_FILES entry, or null to keep the current QR
     */
    public static function save(int $actorId, array $in, ?array $qrUpload, bool $removeQr = false): void
    {
        Gate::authorize('billing.configure', $actorId);
        $enabled = ($in['enabled'] ?? '') === '1';
        $upi = trim((string) ($in['upi_id'] ?? ''));
        $errors = [];
        if ($upi !== '' && !preg_match('/^[A-Za-z0-9._\-]{2,60}@[A-Za-z][A-Za-z0-9.\-]{1,40}$/', $upi)) {
            $errors[] = 'The UPI ID looks wrong. It should look like name@bank.';
        }
        if ($enabled && $upi === '') {
            $errors[] = 'Enter the UPI ID before switching manual UPI on.';
        }
        if ($enabled && self::razorpayOn()) {
            $errors[] = 'Razorpay online payments are switched on. Manual UPI and Razorpay are alternatives: switch Razorpay off first (Integrations & settings → Razorpay), then switch manual UPI on.';
        }
        $payee = null;
        $note = null;
        try {
            $payee = FieldRules::line($in['payee_name'] ?? '', 'Name shown to the client', 2, 120, false);
            $note = FieldRules::line($in['instructions'] ?? '', 'Instructions', 2, 600, false);
        } catch (ValidationException $e) {
            array_push($errors, ...$e->errors);
        }
        $meta = null;
        if ($qrUpload !== null && (int) ($qrUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $meta = self::acceptImage($qrUpload, false);
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        if ($errors !== []) {
            if ($meta !== null) {
                FileStore::discard($meta);
            }
            throw new ValidationException($errors);
        }
        try {
            Database::transaction(function () use ($actorId, $enabled, $upi, $payee, $note, $in, $meta, $removeQr): void {
                $before = self::settings();
                $fileId = $before['qr_file_id'];
                if ($meta !== null) {
                    $fileId = FileStore::insert($meta, $actorId);
                } elseif ($removeQr) {
                    $fileId = null;
                }
                Database::run('INSERT INTO manual_upi_settings (id, enabled, upi_id, payee_name, instructions, require_proof, qr_file_id, updated_by, updated_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                               ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), upi_id = VALUES(upi_id), payee_name = VALUES(payee_name), instructions = VALUES(instructions), require_proof = VALUES(require_proof), qr_file_id = VALUES(qr_file_id), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
                    [$enabled ? 1 : 0, $upi === '' ? null : $upi, $payee, $note, ($in['require_proof'] ?? '') === '1' ? 1 : 0, $fileId, $actorId]);
                Audit::record('payments.manual_upi_saved', 'manual_upi', 1, ['enabled' => (int) $before['enabled'], 'upi_id' => $before['upi_id']], ['enabled' => $enabled ? 1 : 0, 'upi_id' => $upi], ['qr_replaced' => $meta !== null, 'qr_removed' => $removeQr && $meta === null], $actorId);
            });
        } catch (\Throwable $e) {
            if ($meta !== null) {
                FileStore::discard($meta);
            }
            throw $e;
        }
    }

    /**
     * Accepts a picture (and, for payment proof, a PDF). The file store checks the real type; this keeps the list to what makes sense here.
     *
     * @param array<string,mixed> $file one $_FILES entry
     * @return array<string,mixed> FileStore::accept result
     */
    public static function acceptImage(array $file, bool $allowPdf): array
    {
        $meta = FileStore::accept($file, 'payment');
        $ext = strtolower((string) pathinfo((string) $meta['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowPdf ? ['png', 'jpg', 'jpeg', 'webp', 'pdf'] : ['png', 'jpg', 'jpeg', 'webp'], true)) {
            FileStore::discard($meta);
            throw new ValidationException([$allowPdf ? 'Upload a screenshot (PNG, JPG or WEBP) or a PDF as the proof of payment.' : 'Upload the QR code as a PNG, JPG or WEBP picture.']);
        }

        return $meta;
    }

    /** The QR picture to show inline in the page (only while manual UPI is in use). */
    public static function qrResponse(): Response
    {
        $s = self::settings();
        if (!self::active() || $s['qr_file_id'] === null) {
            throw new HttpException(404);
        }
        $f = FileStore::find((int) $s['qr_file_id']) ?? throw new HttpException(404);

        return self::inline($f);
    }

    /** The QR picture for the admin preview (even before it is switched on). */
    public static function qrPreview(): Response
    {
        $s = self::settings();
        $f = $s['qr_file_id'] === null ? null : FileStore::find((int) $s['qr_file_id']);

        return self::inline($f ?? throw new HttpException(404));
    }

    /** @param array<string,mixed> $f */
    private static function inline(array $f): Response
    {
        $mime = (string) $f['mime'];
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new HttpException(404);
        }

        return new Response(200, (string) file_get_contents(FileStore::path($f)), ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }
}
