<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/** Builds and sends the account emails. Links are absolute and built from APP_URL, never from request headers. */
final class AccountMail
{
    public static function link(string $path): string
    {
        $base = (string) Config::get('app.url', '');
        if ($base === '') {
            throw new \RuntimeException('APP_URL must be set to send emails with links.');
        }

        return $base . '/' . ltrim($path, '/');
    }

    private static function app(): string
    {
        return (string) Config::get('app.name', 'Krazy Integrated Solutions');
    }

    public static function verify(string $to, string $name, string $token): bool
    {
        $ttl = (int) Config::get('auth.email_verify_ttl_minutes') / 60;

        return Mailer::send($to, 'Confirm your email address', "Hello {$name},\n\nConfirm your email address for " . self::app() . ":\n\n"
            . self::link('/verify-email/' . $token) . "\n\nThis link works once and expires in {$ttl} hours. If you did not create an account, ignore this email.\n");
    }

    public static function approved(string $to, string $name): bool
    {
        return Mailer::send($to, 'Your account was approved', "Hello {$name},\n\nYour " . self::app() . " client account was approved. You can sign in here:\n\n" . self::link('/login') . "\n");
    }

    public static function invite(string $to, string $name, string $token): bool
    {
        $ttl = (int) Config::get('auth.invite_ttl_minutes') / 60;

        return Mailer::send($to, 'You have been invited to ' . self::app(), "Hello {$name},\n\nAn account was created for you on " . self::app() . ". Set your password here:\n\n"
            . self::link('/invite/' . $token) . "\n\nThis link works once and expires in {$ttl} hours.\n");
    }

    public static function reset(string $to, string $name, string $token): bool
    {
        $ttl = (int) Config::get('auth.password_reset_ttl_minutes');

        return Mailer::send($to, 'Reset your password', "Hello {$name},\n\nSomeone asked to reset your " . self::app() . " password. If it was you, choose a new one here:\n\n"
            . self::link('/reset-password/' . $token) . "\n\nThis link works once and expires in {$ttl} minutes. If you did not ask for this, ignore this email; your password has not changed.\n");
    }

    public static function passwordChanged(string $to, string $name): bool
    {
        return Mailer::send($to, 'Your password was changed', "Hello {$name},\n\nYour " . self::app() . " password was just changed and all other sessions were signed out. If this was not you, reset your password immediately and contact an administrator.\n");
    }

    public static function alreadyRegistered(string $to): bool
    {
        return Mailer::send($to, 'You already have an account', 'Someone tried to register this email address on ' . self::app() . ". An account already exists. If it was you, sign in or use 'Forgot password'. If not, ignore this email.\n");
    }
}
