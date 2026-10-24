<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/**
 * Client self-registration, as the Owner chose it in Integrations & settings → Feature switches:
 *   off       only the team creates client accounts
 *   email     the client confirms their email address, then can sign in (needs working email)
 *   approval  the account waits until the team approves it (works without email); this is the default until the Owner chooses
 * REGISTRATION_ENABLED=true in .env still means "email" for sites that were set up before the screen had this option.
 */
final class Registration
{
    public const MODES = ['off' => 'Off: only the team creates client accounts', 'email' => 'On: the client confirms their email address first (needs working email)', 'approval' => 'On: the team approves each new account (works without email)'];

    /** The mode that was chosen (or the default, "approval", when nothing was chosen), before the email requirement is applied. */
    public static function configured(): string
    {
        $m = (string) Config::get('auth.registration_mode', '');
        if (!isset(self::MODES[$m])) {
            $m = Config::get('auth.registration_enabled') ? 'email' : 'approval';
        }

        return $m;
    }

    /** The mode that is really in force: "email" needs working email, otherwise registration is off. */
    public static function mode(): string
    {
        $m = self::configured();
        if ($m === 'email' && !Mailer::enabled()) {
            return 'off';
        }

        return $m;
    }

    public static function enabled(): bool
    {
        return self::mode() !== 'off';
    }
}
