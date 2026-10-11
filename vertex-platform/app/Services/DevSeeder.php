<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Repositories\UserRepository;

/**
 * Development/test data: FICTIONAL people on the reserved example.test domain only.
 * Refuses to run unless APP_ENV is local or testing. The shared password is generated per run (or taken from the
 * SEED_PASSWORD environment variable) and printed once; it is never stored in the repository.
 */
final class DevSeeder
{
    /** email local-part => [name, roles, phone] */
    private const USERS = [
        'owner' => ['Olivia Owner', ['super_admin'], null],
        'admin' => ['Adam Administrator', ['administrator'], null],
        'manager' => ['Maya Manager', ['manager'], null],
        'finance' => ['Farid Finance', ['finance_staff'], null],
        'support' => ['Sana Support', ['support_staff'], null],
        'freelancer' => ['Felix Freelancer', ['freelancer'], '+91 90000 00001'],
        'editor' => ['Elena Editor', ['video_editor'], '+91 90000 00002'],
        'developer' => ['Dev Developer', ['website_developer'], '+91 90000 00003'],
        'partner' => ['Priya Partner', ['referral_partner'], '+91 90000 00004'],
        'client1' => ['Clara Client', ['client'], '+91 90000 00005'],
        'client2' => ['Carlos Client', ['client'], '+91 90000 00006'],
    ];

    /** @return array{password:string,created:int,projects:int} */
    public static function run(?string $password = null): array
    {
        $env = (string) Config::get('app.env');
        if (!in_array($env, ['local', 'testing'], true)) {
            throw new \RuntimeException("Refusing to seed development data when APP_ENV={$env}. Use APP_ENV=local.");
        }
        $password ??= (string) (getenv('SEED_PASSWORD') ?: bin2hex(random_bytes(9)) . 'Aa1!');
        PermissionSync::run();

        $repo = new UserRepository();
        $created = 0;
        $ids = [];
        foreach (self::USERS as $local => [$name, $roles, $phone]) {
            $email = "{$local}@example.test";
            $existing = $repo->findByEmail($email);
            if ($existing !== null) {
                $ids[$local] = (int) $existing['id'];
                continue;
            }
            $ids[$local] = $repo->create($name, $email, password_hash($password, PASSWORD_DEFAULT), 'active', $roles, null, $phone, true);
            if (in_array('client', $roles, true)) {
                Database::run('INSERT IGNORE INTO clients (public_id, user_id, company_name, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [uuid_hex(), $ids[$local], $name . ' Studio (fictional)']);
            }
            Audit::record('user.created', 'user', $ids[$local], [], ['email' => $email, 'roles' => $roles, 'status' => 'active'], ['via' => 'dev-seeder'], null, 'cli');
            $created++;
        }

        $projects = 0;
        $client = static fn (string $k): int => (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$ids[$k]])['id'];
        foreach ([['client1', 'Fictional Brand Website', 'active'], ['client1', 'Fictional Launch Video', 'active'], ['client2', 'Fictional Shop Redesign', 'active']] as [$owner, $title, $status]) {
            if (Database::one('SELECT 1 AS x FROM projects WHERE title = ?', [$title]) !== null) {
                continue;
            }
            Database::run(
                'INSERT INTO projects (public_id, client_id, title, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [uuid_hex(), $client($owner), $title, $status, $ids['admin']],
            );
            $projects++;
        }
        $pid = static fn (string $title): int => (int) Database::one('SELECT id FROM projects WHERE title = ?', [$title])['id'];
        foreach ([
            ['Fictional Brand Website', 'manager', 'lead'], ['Fictional Brand Website', 'developer', 'member'],
            ['Fictional Launch Video', 'manager', 'lead'], ['Fictional Launch Video', 'editor', 'member'],
            ['Fictional Shop Redesign', 'freelancer', 'member'],
        ] as [$title, $who, $role]) {
            ProjectAssignmentService::assign($ids['admin'], $pid($title), $ids[$who], $role);
        }

        if (Database::one('SELECT 1 AS x FROM referrals WHERE referred_name = ?', ['Fictional Lead Ltd']) === null) {
            Database::run(
                "INSERT INTO referrals (public_id, partner_user_id, referred_name, status, created_at, updated_at) VALUES (?, ?, 'Fictional Lead Ltd', 'lead', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [uuid_hex(), $ids['partner']],
            );
        }

        return ['password' => $password, 'created' => $created, 'projects' => $projects];
    }
}
