<?php

declare(strict_types=1);

namespace Vertex\Services;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use Vertex\Core\Config;
use Vertex\Core\Logger;

/**
 * Plain-text transactional email. Drivers (config/mail.php): smtp | log (dev only) | array (tests) | none.
 * `enabled()` tells flows whether email-dependent features (registration, verification, reset, invites) can run.
 * Message bodies contain single-use links, so the `log` driver is refused in production.
 */
final class Mailer
{
    /** @var list<array{to:string,subject:string,body:string}> captured by the `array` driver (tests only) */
    public static array $sent = [];

    public static function driver(): string
    {
        $d = (string) Config::get('mail.driver', 'none');
        if ((string) Config::get('app.url', '') === '') {
            return 'none'; // every email carries absolute links built from APP_URL, never from request headers
        }
        $env = (string) Config::get('app.env', 'production');
        if ($d === 'array' && $env !== 'testing') {
            return 'none';
        }
        if ($d === 'log' && $env === 'production') {
            return 'none';
        }
        if ($d === 'smtp' && (Config::get('mail.host') === '' || Config::get('mail.from_address') === '')) {
            return 'none';
        }

        return in_array($d, ['smtp', 'log', 'array'], true) ? $d : 'none';
    }

    /** What went wrong with the last SMTP attempt (server answers can appear here, never the password); shown to the Owner by the "send a test email" button. */
    public static string $lastError = '';

    public static function enabled(): bool
    {
        return self::driver() !== 'none';
    }

    public static function send(string $to, string $subject, string $body): bool
    {
        $subject = (string) preg_replace('/[\r\n]+/', ' ', $subject); // header-injection guard
        switch (self::driver()) {
            case 'array':
                self::$sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

                return true;
            case 'log':
                Logger::log('mail', 'info', "TO={$to} SUBJECT={$subject}\n{$body}");

                return true;
            case 'smtp':
                return self::smtp($to, $subject, $body);
            default:
                return false;
        }
    }

    private static function smtp(string $to, string $subject, string $body): bool
    {
        $c = Config::get('mail');
        self::$lastError = '';
        // First as configured: From = the From address (it may be an alias such as noreply@), envelope sender = the mailbox that signs in. Many hosts (Hostinger
        // included) refuse a message whose envelope sender is an alias; if the host still refuses, try once more with the mailbox itself as From and the
        // alias as Reply-To, so the email is delivered rather than lost.
        $mailbox = filter_var((string) $c['username'], FILTER_VALIDATE_EMAIL) ? (string) $c['username'] : '';
        $attempts = [[(string) $c['from_address'], '']];
        if ($mailbox !== '' && strcasecmp($mailbox, (string) $c['from_address']) !== 0) {
            $attempts[] = [$mailbox, (string) $c['from_address']];
        }
        foreach ($attempts as $n => [$from, $replyTo]) {
            $m = new PHPMailer(true);
            try {
                $m->isSMTP();
                $m->Host = $c['host'];
                $m->Port = $c['port'];
                $enc = $c['encryption'];
                $m->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($enc === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
                $m->SMTPAutoTLS = $enc === 'tls';
                $m->Timeout = 20;
                if ($c['username'] !== '') {
                    $m->SMTPAuth = true;
                    $m->Username = $c['username'];
                    $m->Password = $c['password'];
                }
                $m->CharSet = 'UTF-8';
                if ($mailbox !== '') {
                    $m->Sender = $mailbox;
                }
                $m->setFrom($from, $c['from_name']);
                if ($replyTo !== '') {
                    $m->addReplyTo($replyTo, $c['from_name']);
                }
                $m->addAddress($to);
                $m->Subject = $subject;
                $m->Body = $body;
                $m->isHTML(false);
                $m->send();
                if ($n > 0) {
                    Logger::error('Mail was delivered only after falling back to the mailbox as the From address: the host does not allow the From alias ' . $c['from_address']);
                }

                return true;
            } catch (MailException $e) {
                // The exception text can include server responses but never our password; recipient is not logged.
                self::$lastError = $e->getMessage();
                Logger::error('Mail send failed: ' . $e->getMessage());
            }
        }

        return false;
    }

    public static function reset(): void
    {
        self::$sent = [];
    }
}
