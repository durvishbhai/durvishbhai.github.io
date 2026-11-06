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

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private static function spec(string $subject, string $heading, string $name, string $category, string $tone, array $extra): array
    {
        return ['subject' => $subject, 'heading' => $heading, 'name' => $name, 'category' => $category, 'tone' => $tone] + $extra;
    }

    public static function verify(string $to, string $name, string $token): bool
    {
        $ttl = (int) (Config::get('auth.email_verify_ttl_minutes') / 60);

        return Mailer::sendTemplate($to, self::spec('Confirm your email address for ' . self::app(), 'Confirm your email address', $name, 'Account', 'info', [
            'paragraphs' => ['Welcome to ' . self::app() . '. Please confirm that this is your email address to finish creating your account.'],
            'button' => ['label' => 'Confirm email address', 'url' => self::link('/verify-email/' . $token)],
            'notes' => ["This link works once and expires in {$ttl} hours.", 'If you did not create an account, you can ignore this email: nothing will happen without this confirmation.'],
        ]));
    }

    public static function approved(string $to, string $name): bool
    {
        return Mailer::sendTemplate($to, self::spec('Your ' . self::app() . ' account has been approved', 'Your account has been approved', $name, 'Account', 'success', [
            'paragraphs' => ['Your ' . self::app() . ' client account has been approved by our team. You can now sign in to follow your projects, invoices and payments.'],
            'button' => ['label' => 'Sign in', 'url' => self::link('/login')],
        ]));
    }

    public static function invite(string $to, string $name, string $token): bool
    {
        $ttl = (int) (Config::get('auth.invite_ttl_minutes') / 60);

        return Mailer::sendTemplate($to, self::spec('You have been invited to ' . self::app(), 'You have been invited', $name, 'Account', 'info', [
            'paragraphs' => ['An account was created for you on ' . self::app() . '. Choose your password to activate it.'],
            'button' => ['label' => 'Set your password', 'url' => self::link('/invite/' . $token)],
            'notes' => ["This link works once and expires in {$ttl} hours.", 'If you were not expecting this invitation, you can ignore this email.'],
        ]));
    }

    public static function reset(string $to, string $name, string $token): bool
    {
        $ttl = (int) Config::get('auth.password_reset_ttl_minutes');

        return Mailer::sendTemplate($to, self::spec('Reset your ' . self::app() . ' account password', 'Reset your password', $name, 'Account security', 'info', [
            'paragraphs' => ['We received a request to reset the password of your ' . self::app() . ' account. If it was you, choose a new password with the button below.'],
            'button' => ['label' => 'Choose a new password', 'url' => self::link('/reset-password/' . $token)],
            'notes' => ["This link works once and expires in {$ttl} minutes.", 'If you did not ask for this, ignore this email: your password has not changed. We never send your password by email.'],
        ]));
    }

    public static function passwordChanged(string $to, string $name): bool
    {
        return Mailer::sendTemplate($to, self::spec('Your KIS Account Password Has Been Changed', 'Your password was changed successfully.', $name, 'Account security', 'success', [
            'paragraphs' => ['The password for your ' . self::app() . ' account was changed successfully. For your security, all other active sessions have been signed out.', 'If you made this change, no further action is required.'],
            'notes' => ['If you did not change your password, reset it immediately with the button below and contact us. Someone else may have access to your account.'],
            'button' => ['label' => 'Secure My Account', 'url' => self::link('/forgot-password')],
        ]));
    }

    public static function alreadyRegistered(string $to): bool
    {
        return Mailer::sendTemplate($to, self::spec('You already have a ' . self::app() . ' account', 'You already have an account', '', 'Account', 'info', [
            'paragraphs' => ['Someone tried to register this email address on ' . self::app() . ', but an account already exists. If it was you, sign in, or use "Forgot password" if you cannot remember your password.'],
            'button' => ['label' => 'Sign in', 'url' => self::link('/login')],
            'notes' => ['If it was not you, ignore this email. Your account is safe and nothing was changed.'],
        ]));
    }

    /** A sign-in alert: sent after a successful sign-in so the person can spot one they did not make. */
    public static function signedIn(string $to, string $name, string $when, string $ip, string $browser, string $method): bool
    {
        return Mailer::sendTemplate($to, self::spec('New sign-in to your ' . self::app() . ' account', 'You signed in to your account', $name, 'Account security', 'info', [
            'paragraphs' => ['We noticed a sign-in to your ' . self::app() . ' account. If this was you, there is nothing to do.'],
            'details' => ['When' => $when, 'Sign-in method' => $method, 'Device' => $browser, 'IP address' => $ip],
            'notes' => ['If you do not recognise this sign-in, reset your password now and contact us.'],
            'button' => ['label' => 'Secure My Account', 'url' => self::link('/forgot-password')],
        ]));
    }

    /** The team deactivated or reactivated the account. */
    public static function status(string $to, string $name, bool $active): bool
    {
        return Mailer::sendTemplate($to, self::spec('Your ' . self::app() . ' account was ' . ($active ? 'reactivated' : 'deactivated'), $active ? 'Your account was reactivated' : 'Your account was deactivated', $name, 'Account', $active ? 'success' : 'warning', [
            'paragraphs' => [$active ? 'Your ' . self::app() . ' account was reactivated by our team. You can sign in again.' : 'Your ' . self::app() . ' account was deactivated by our team, so you cannot sign in for now.'],
            'notes' => [$active ? 'If you did not expect this, please contact us.' : 'If you think this is a mistake, please contact us.'],
            'button' => $active ? ['label' => 'Sign in', 'url' => self::link('/login')] : null,
        ]));
    }
}
