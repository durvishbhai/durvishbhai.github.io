<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;
use Vertex\Core\ValidationException;
use Vertex\Repositories\UserRepository;

/**
 * Every account mutation goes through here. Controllers never write users/roles directly.
 *
 * Privilege rules enforced server-side (not by the UI):
 *  - An actor may only act on a user whose highest role level is STRICTLY lower than the actor's own.
 *    => nobody can touch Super Admins (level 100) through the UI, Administrators cannot touch each other.
 *  - Nobody can change their own roles/status/permissions (self-service is limited to name, phone, timezone, password).
 *  - A role may be assigned only if it is `assignable_in_ui` and its level is strictly below the actor's.
 *    => Administrators cannot mint Administrators; Super Admin is console-only.
 *  - Role changes need `users.assign_roles`; account lifecycle needs `users.deactivate` / `users.archive`.
 *  - Request data is never mass-assigned: each method reads an explicit allow-list of fields.
 */
final class UserService
{
    // ------------------------------------------------------------------ self-service (no extra permission)

    /**
     * Public registration: always creates a CLIENT, status pending until the email is verified.
     * Returns nothing about whether the email already existed (anti-enumeration).
     *
     * @param array<string,mixed> $in
     */
    public static function register(array $in, string $ip): void
    {
        $mode = Registration::mode();
        if ($mode === 'off') {
            throw new HttpException(404);
        }
        $name = self::name($in['name'] ?? '');
        $email = self::email($in['email'] ?? '');
        $phone = self::phone($in['phone'] ?? '', true);
        $password = (string) ($in['password'] ?? '');
        $errors = PasswordPolicy::check($password, $email, $name);
        if (!hash_equals($password, (string) ($in['password_confirmation'] ?? ''))) {
            $errors[] = 'Password confirmation does not match.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!RateLimiter::hit('register_ip', $ip)) {
            throw new HttpException(429);
        }

        $repo = new UserRepository();
        if ($repo->findByEmail($email) !== null) {
            if (Mailer::enabled()) {
                AccountMail::alreadyRegistered($email);
            }
            Audit::record('auth.register.duplicate', 'user', null, [], [], ['email' => $email], null, $ip);

            return;
        }

        $token = Database::transaction(function () use ($repo, $name, $email, $phone, $password, $ip, $mode): ?string {
            $id = $repo->create($name, $email, password_hash($password, PASSWORD_DEFAULT), 'pending', ['client'], null, $phone);
            Database::run('UPDATE users SET self_registered = 1 WHERE id = ?', [$id]);
            self::ensureClientProfile($id);
            Audit::record('user.registered', 'user', $id, [], ['name' => $name, 'email' => $email, 'roles' => ['client'], 'status' => 'pending'], ['mode' => $mode], $id, $ip);
            if ($mode === 'approval') {
                $pub = (string) Database::one('SELECT public_id FROM users WHERE id = ?', [$id])['public_id'];
                Notifier::send(Notifier::withPermission('users.edit'), 'user.registration_pending', 'New client account waiting for approval', $name . ' (' . $email . ') registered. Open the account to approve it.', '/admin/users/' . $pub, null, 'user', $id, 'reg:' . $id . ':pending');

                return null;
            }

            return TokenService::issue($id, TokenService::VERIFY, (int) Config::get('auth.email_verify_ttl_minutes'), $ip);
        });
        if ($token !== null) {
            AccountMail::verify($email, $name, $token);
        }
    }

    /**
     * Approves a client who registered through the public form while self-registration needs the team's approval. Only self-registered accounts that are still
     * pending can be approved this way (an invited account becomes active when its owner sets a password).
     */
    public static function approveRegistration(int $actorId, int $targetId): void
    {
        $target = self::guardTarget($actorId, $targetId, 'users.edit');
        if ($target['status'] !== 'pending' || (int) ($target['self_registered'] ?? 0) !== 1) {
            throw new ValidationException(['Only a self-registered account that is waiting for approval can be approved here.']);
        }
        Database::transaction(function () use ($actorId, $targetId, $target): void {
            Database::run("UPDATE users SET status = 'active', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'", [$targetId]);
            Audit::record('user.registration_approved', 'user', $targetId, ['status' => 'pending'], ['status' => 'active'], [], $actorId);
            Notifier::send([$targetId], 'user.registration_approved', 'Your account was approved', 'You can now sign in to your client account.', '/dashboard', null, 'user', $targetId, 'reg:' . $targetId . ':approved');
        });
        if (Mailer::enabled()) {
            AccountMail::approved((string) $target['email'], (string) $target['name']);
        }
    }

    public static function resendVerification(string $email, string $ip): void
    {
        if (!Mailer::enabled()) {
            throw new HttpException(404);
        }
        $email = mb_strtolower(trim($email));
        if (!RateLimiter::hit('verify_resend_ip', $ip) || !RateLimiter::hit('verify_resend_email', $email)) {
            return; // silent: same response as success
        }
        $user = (new UserRepository())->findByEmail($email);
        if ($user !== null && $user['status'] === 'pending' && $user['email_verified_at'] === null) {
            AccountMail::verify($email, (string) $user['name'], TokenService::issue((int) $user['id'], TokenService::VERIFY, (int) Config::get('auth.email_verify_ttl_minutes'), $ip));
        }
    }

    /** @return bool true when the token was valid and the email is now verified */
    public static function verifyEmail(string $rawToken, string $ip): bool
    {
        $t = TokenService::peek($rawToken, [TokenService::VERIFY]);
        if ($t === null || !TokenService::consume($t['id'])) {
            return false;
        }
        Database::transaction(function () use ($t, $ip): void {
            Database::run(
                "UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()),
                        status = IF(status = 'pending', 'active', status), updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$t['user_id']],
            );
            Audit::record('user.email_verified', 'user', $t['user_id'], [], [], [], $t['user_id'], $ip);
        });

        return true;
    }

    /** Always returns normally: callers show one neutral message whether or not the account exists. */
    public static function requestPasswordReset(string $email, string $ip): void
    {
        if (!Mailer::enabled()) {
            throw new HttpException(404);
        }
        $email = mb_strtolower(trim($email));
        if (!RateLimiter::hit('password_reset_ip', $ip) || !RateLimiter::hit('password_reset_email', $email)) {
            return;
        }
        $user = (new UserRepository())->findByEmail($email);
        if ($user === null || $user['status'] !== 'active') {
            return;
        }
        $token = TokenService::issue((int) $user['id'], TokenService::RESET, (int) Config::get('auth.password_reset_ttl_minutes'), $ip);
        Audit::record('auth.password_reset.requested', 'user', (int) $user['id'], [], [], [], null, $ip);
        AccountMail::reset($email, (string) $user['name'], $token);
    }

    /**
     * Completes a password reset or an invitation with a new password.
     *
     * @throws ValidationException weak password
     * @return bool false when the token is invalid/expired/used
     */
    public static function completePasswordToken(string $rawToken, string $password, string $confirmation, string $ip): bool
    {
        if (!RateLimiter::hit('token_ip', $ip)) {
            throw new HttpException(429);
        }
        $t = TokenService::peek($rawToken, [TokenService::RESET, TokenService::INVITE]);
        if ($t === null) {
            return false;
        }
        $repo = new UserRepository();
        $user = $repo->findById($t['user_id']);
        if ($user === null || in_array($user['status'], ['deactivated', 'archived'], true)) {
            return false;
        }
        $errors = PasswordPolicy::check($password, (string) $user['email'], (string) $user['name']);
        if (!hash_equals($password, $confirmation)) {
            $errors[] = 'Password confirmation does not match.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!TokenService::consume($t['id'])) {
            return false;
        }
        Database::transaction(function () use ($repo, $user, $t, $password, $ip): void {
            $repo->setPassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
            Database::run("UPDATE users SET status = IF(status = 'pending', 'active', status) WHERE id = ?", [$user['id']]);
            Audit::record(
                $t['purpose'] === TokenService::INVITE ? 'user.invite_accepted' : 'auth.password_reset.completed',
                'user', (int) $user['id'], [], [], [], (int) $user['id'], $ip,
            );
        });
        if (Mailer::enabled()) {
            AccountMail::passwordChanged((string) $user['email'], (string) $user['name']);
        }

        return true;
    }

    /**
     * Self-service profile edit. ONLY name, phone and timezone are read; any other submitted field
     * (role, roles, status, email, permissions ...) is ignored by construction.
     *
     * @param array<string,mixed> $in
     */
    public static function updateOwnProfile(int $userId, array $in): void
    {
        $repo = new UserRepository();
        $user = $repo->findById($userId) ?? throw new HttpException(404);
        $name = self::name($in['name'] ?? '');
        $phone = self::phone($in['phone'] ?? '', self::phoneRequiredFor($userId));
        $tz = self::timezone($in['timezone'] ?? '');
        Database::transaction(function () use ($userId, $user, $name, $phone, $tz): void {
            Database::run('UPDATE users SET name = ?, phone = ?, timezone = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$name, $phone, $tz, $userId]);
            Audit::record('user.profile_updated', 'user', $userId,
                ['name' => $user['name'], 'phone' => $user['phone'], 'timezone' => $user['timezone']],
                ['name' => $name, 'phone' => $phone, 'timezone' => $tz], [], $userId);
        });
    }

    /** Changes the signed-in user's password. Other sessions are signed out; the caller keeps theirs via Auth::refresh(). */
    public static function changeOwnPassword(int $userId, string $current, string $new, string $confirmation): void
    {
        $repo = new UserRepository();
        $user = $repo->findById($userId) ?? throw new HttpException(404);
        if (!Auth::verifyPassword($userId, $current)) {
            throw new ValidationException(['Your current password is incorrect.']);
        }
        $errors = PasswordPolicy::check($new, (string) $user['email'], (string) $user['name']);
        if (!hash_equals($new, $confirmation)) {
            $errors[] = 'Password confirmation does not match.';
        }
        if (hash_equals($current, $new)) {
            $errors[] = 'Choose a password different from the current one.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        Database::transaction(function () use ($repo, $userId, $new): void {
            $repo->setPassword($userId, password_hash($new, PASSWORD_DEFAULT));
            Audit::record('user.password_changed', 'user', $userId, [], [], [], $userId);
        });
        if (Mailer::enabled()) {
            AccountMail::passwordChanged((string) $user['email'], (string) $user['name']);
        }
    }

    // ------------------------------------------------------------------ administrative actions

    /**
     * Creates an account on behalf of an admin. The account starts 'pending' with an unusable random password and an
     * invitation link; the person sets their own password. Admins never see or choose passwords.
     *
     * @param array<string,mixed> $in name, email, phone, timezone?, roles[]
     * @return array{user_id:int,invite_sent:bool}
     */
    public static function adminCreate(int $actorId, array $in): array
    {
        Gate::authorize('users.create', $actorId);
        Gate::authorize('users.assign_roles', $actorId);

        $name = self::name($in['name'] ?? '');
        $email = self::email($in['email'] ?? '');
        $roles = self::roleList($in['roles'] ?? []);
        if ($roles === []) {
            throw new ValidationException(['Select at least one role.']);
        }
        self::assertAssignable($actorId, $roles);
        $phone = self::phone($in['phone'] ?? '', self::phoneRequiredForSlugs($roles));

        $repo = new UserRepository();
        if ($repo->findByEmail($email) !== null) {
            throw new ValidationException(['A user with that email address already exists.']);
        }

        $link = (int) ($in['link_client_id'] ?? 0); // set only by ClientService::invite(), never from request data
        $id = Database::transaction(function () use ($repo, $actorId, $name, $email, $phone, $roles, $link): int {
            $id = $repo->create($name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'pending', $roles, $actorId, $phone);
            if ($link > 0) {
                // Attach this login to an EXISTING client record instead of creating a second one.
                $n = Database::run('UPDATE clients SET user_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND user_id IS NULL', [$id, $link])->rowCount();
                if ($n !== 1) {
                    throw new ValidationException(['That client record already has a portal login.']);
                }
            } elseif (in_array('client', $roles, true)) {
                self::ensureClientProfile($id);
            }
            Audit::record('user.created', 'user', $id, [], ['name' => $name, 'email' => $email, 'phone' => $phone, 'roles' => $roles, 'status' => 'pending'], [], $actorId);

            return $id;
        });

        return ['user_id' => $id, 'invite_sent' => self::sendInvite($id)];
    }

    /** @param array<string,mixed> $in name, email, phone, timezone */
    public static function adminUpdate(int $actorId, int $targetId, array $in): void
    {
        $target = self::guardTarget($actorId, $targetId, 'users.edit');
        $name = self::name($in['name'] ?? '');
        $email = self::email($in['email'] ?? '');
        $roles = array_column((new UserRepository())->rolesOf($targetId), 'slug');
        $phone = self::phone($in['phone'] ?? '', self::phoneRequiredForSlugs($roles));
        $tz = self::timezone($in['timezone'] ?? '');

        $repo = new UserRepository();
        $other = $repo->findByEmail($email);
        if ($other !== null && (int) $other['id'] !== $targetId) {
            throw new ValidationException(['A user with that email address already exists.']);
        }
        $emailChanged = $email !== $target['email'];

        Database::transaction(function () use ($actorId, $targetId, $target, $name, $email, $phone, $tz, $emailChanged): void {
            Database::run(
                'UPDATE users SET name = ?, email = ?, phone = ?, timezone = ?,
                        email_verified_at = IF(?, NULL, email_verified_at), session_version = session_version + IF(?, 1, 0),
                        updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$name, $email, $phone, $tz, $emailChanged ? 1 : 0, $emailChanged ? 1 : 0, $targetId],
            );
            Audit::record('user.updated', 'user', $targetId,
                ['name' => $target['name'], 'email' => $target['email'], 'phone' => $target['phone'], 'timezone' => $target['timezone']],
                ['name' => $name, 'email' => $email, 'phone' => $phone, 'timezone' => $tz],
                $emailChanged ? ['email_changed' => true, 'email_verification_reset' => true] : [], $actorId);
        });
    }

    /** @param list<string> $roleSlugs */
    public static function setRoles(int $actorId, int $targetId, array $roleSlugs): void
    {
        Gate::authorize('users.assign_roles', $actorId);
        self::guardTarget($actorId, $targetId, 'users.assign_roles');
        $roles = self::roleList($roleSlugs);
        if ($roles === []) {
            throw new ValidationException(['A user must keep at least one role.']);
        }
        self::assertAssignable($actorId, $roles);

        $repo = new UserRepository();
        $before = array_column($repo->rolesOf($targetId), 'slug');
        sort($before);
        $after = $roles;
        sort($after);
        if ($before === $after) {
            return;
        }
        $phone = (string) ($repo->findById($targetId)['phone'] ?? '');
        if ($phone === '' && self::phoneRequiredForSlugs($roles)) {
            throw new ValidationException(['The selected roles require a phone number. Add one to the profile first.']);
        }

        Database::transaction(function () use ($repo, $actorId, $targetId, $roles, $before, $after): void {
            $repo->replaceRoles($targetId, $roles, $actorId);
            if (in_array('client', $roles, true)) {
                self::ensureClientProfile($targetId);
            }
            Audit::record('user.roles_changed', 'user', $targetId, ['roles' => $before], ['roles' => $after], [], $actorId);
        });
        Gate::flush();
    }

    public static function deactivate(int $actorId, int $targetId, string $reason): void
    {
        $target = self::guardTarget($actorId, $targetId, 'users.deactivate');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new ValidationException(['Give a reason (max 255 characters).']);
        }
        if (!in_array($target['status'], ['active', 'pending'], true)) {
            throw new ValidationException(['Only active or pending accounts can be deactivated.']);
        }
        Database::transaction(function () use ($actorId, $targetId, $target, $reason): void {
            Database::run(
                "UPDATE users SET status = 'deactivated', deactivated_at = UTC_TIMESTAMP(), deactivated_by = ?, deactivation_reason = ?,
                        session_version = session_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$actorId, $reason, $targetId],
            );
            // Pending reset/invite tokens must not outlive the account's access.
            Database::run('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$targetId]);
            Audit::record('user.deactivated', 'user', $targetId, ['status' => $target['status']], ['status' => 'deactivated'], ['reason' => $reason], $actorId);
        });
    }

    public static function reactivate(int $actorId, int $targetId): void
    {
        $target = self::guardTarget($actorId, $targetId, 'users.deactivate');
        if ($target['status'] !== 'deactivated') {
            throw new ValidationException(['Only deactivated accounts can be reactivated.']);
        }
        $new = $target['email_verified_at'] !== null ? 'active' : 'pending';
        Database::transaction(function () use ($actorId, $targetId, $new): void {
            Database::run(
                'UPDATE users SET status = ?, deactivated_at = NULL, deactivated_by = NULL, deactivation_reason = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$new, $targetId],
            );
            Audit::record('user.reactivated', 'user', $targetId, ['status' => 'deactivated'], ['status' => $new], [], $actorId);
        });
    }

    public static function archive(int $actorId, int $targetId): void
    {
        $target = self::guardTarget($actorId, $targetId, 'users.archive');
        if ($target['status'] === 'archived') {
            return;
        }
        Database::transaction(function () use ($actorId, $targetId, $target): void {
            Database::run("UPDATE users SET status = 'archived', session_version = session_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$targetId]);
            Database::run('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$targetId]);
            Audit::record('user.archived', 'user', $targetId, ['status' => $target['status']], ['status' => 'archived'], [], $actorId);
        });
    }

    /** Admin-triggered reset email (the admin never learns or sets the password). */
    public static function adminSendReset(int $actorId, int $targetId): bool
    {
        $target = self::guardTarget($actorId, $targetId, 'users.reset_password');
        if ($target['status'] !== 'active' || !Mailer::enabled()) {
            return false;
        }
        $token = TokenService::issue($targetId, TokenService::RESET, (int) Config::get('auth.password_reset_ttl_minutes'));
        Audit::record('user.password_reset_sent', 'user', $targetId, [], [], [], $actorId);

        return AccountMail::reset((string) $target['email'], (string) $target['name'], $token);
    }

    /** Re-sends the invitation for a still-pending, never-activated account. */
    public static function adminResendInvite(int $actorId, int $targetId): bool
    {
        $target = self::guardTarget($actorId, $targetId, 'users.create');
        if ($target['status'] !== 'pending') {
            throw new ValidationException(['Only pending accounts can be re-invited.']);
        }
        Audit::record('user.invite_resent', 'user', $targetId, [], [], [], $actorId);

        return self::sendInvite($targetId);
    }

    // ------------------------------------------------------------------ guards and helpers

    /**
     * Loads the target and enforces: permission, no self-service via admin screens, strictly-lower level.
     *
     * @return array<string,mixed>
     */
    public static function guardTarget(int $actorId, int $targetId, string $permission): array
    {
        Gate::authorize($permission, $actorId);
        if ($actorId === $targetId) {
            throw new HttpException(403, 'You cannot perform this action on your own account.');
        }
        $target = (new UserRepository())->findById($targetId) ?? throw new HttpException(404);
        if (Gate::level($targetId) >= Gate::level($actorId)) {
            self::denied($actorId, $targetId, $permission);
            throw new HttpException(403);
        }

        return $target;
    }

    /** Roles the actor may hand out: assignable in UI and strictly below the actor's level. @return list<string> */
    public static function assignableRoleSlugs(int $actorId): array
    {
        $level = Gate::level($actorId);
        $out = [];
        foreach (RoleService::definitions() as $slug => $def) {
            if ($def['assignable_in_ui'] && $def['level'] < $level) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /** @param list<string> $roles */
    private static function assertAssignable(int $actorId, array $roles): void
    {
        $allowed = self::assignableRoleSlugs($actorId);
        foreach ($roles as $r) {
            if (!in_array($r, $allowed, true)) {
                self::denied($actorId, 0, "assign role {$r}");
                throw new HttpException(403, 'You are not allowed to assign that role.');
            }
        }
    }

    /** @return list<string> */
    private static function roleList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $r) {
            if (is_string($r) && preg_match('/^[a-z_]{2,40}$/', $r)) {
                $out[$r] = $r;
            }
        }
        $known = array_keys(RoleService::definitions());
        foreach ($out as $r) {
            if (!in_array($r, $known, true)) {
                throw new ValidationException(['Unknown role selected.']);
            }
        }

        return array_values($out);
    }

    private static function sendInvite(int $userId): bool
    {
        if (!Mailer::enabled()) {
            return false;
        }
        $user = (new UserRepository())->findById($userId);
        $token = TokenService::issue($userId, TokenService::INVITE, (int) Config::get('auth.invite_ttl_minutes'));

        return AccountMail::invite((string) $user['email'], (string) $user['name'], $token);
    }

    private static function ensureClientProfile(int $userId): void
    {
        // One client record per portal user. Contact details are copied from the user so the record is complete.
        Database::run(
            "INSERT IGNORE INTO clients (public_id, user_id, client_type, contact_name, email, phone, phone_digits, status, created_at, updated_at)
             SELECT ?, u.id, 'individual', u.name, u.email, u.phone, RIGHT(REGEXP_REPLACE(COALESCE(u.phone, ''), '[^0-9]', ''), 10), 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()
               FROM users u WHERE u.id = ?",
            [uuid_hex(), $userId],
        );
    }

    private static function name(mixed $v): string
    {
        $v = is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v) ?? '') : '';
        if ($v === '' || mb_strlen($v) > 120 || preg_match('/[<>]/', $v)) {
            throw new ValidationException(['Enter a full name (max 120 characters, no < or >).']);
        }

        return $v;
    }

    private static function email(mixed $v): string
    {
        $v = is_string($v) ? mb_strtolower(trim($v)) : '';
        if ($v === '' || mb_strlen($v) > 190 || !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['Enter a valid email address.']);
        }

        return $v;
    }

    private static function phone(mixed $v, bool $required): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') {
            if ($required) {
                throw new ValidationException(['A phone number is required.']);
            }

            return null;
        }
        if (!preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $v)) {
            throw new ValidationException(['Enter a valid phone number (digits, spaces, + ( ) -).']);
        }

        return $v;
    }

    private static function timezone(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') {
            return null;
        }
        if (!in_array($v, \DateTimeZone::listIdentifiers(), true)) {
            throw new ValidationException(['Choose a valid time zone.']);
        }

        return $v;
    }

    /** @param list<string> $slugs */
    private static function phoneRequiredForSlugs(array $slugs): bool
    {
        foreach ($slugs as $s) {
            if (RoleService::definitions()[$s]['phone_required'] ?? false) {
                return true;
            }
        }

        return false;
    }

    private static function phoneRequiredFor(int $userId): bool
    {
        return self::phoneRequiredForSlugs(Gate::roleSlugs($userId));
    }

    private static function denied(int $actor, int $target, string $what): void
    {
        Logger::security('Privilege check denied', ['actor' => $actor, 'target' => $target, 'action' => $what]);
    }
}
