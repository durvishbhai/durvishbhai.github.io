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
                Database::run(
                    "INSERT IGNORE INTO clients (public_id, user_id, client_type, contact_name, email, phone, phone_digits, company_name, company_norm, status, created_at, updated_at)
                     VALUES (?, ?, 'business', ?, ?, ?, RIGHT(REGEXP_REPLACE(?, '[^0-9]', ''), 10), ?, LOWER(?), 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    [uuid_hex(), $ids[$local], $name, $email, $phone, (string) $phone, $name . ' Studio (fictional)', $name . ' Studio (fictional)'],
                );
            }
            Audit::record('user.created', 'user', $ids[$local], [], ['email' => $email, 'roles' => $roles, 'status' => 'active'], ['via' => 'dev-seeder'], null, 'cli');
            $created++;
        }

        $projects = 0;
        $client = static fn (string $k): int => (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$ids[$k]])['id'];
        foreach ([['client1', 'Fictional Brand Website', 'in_progress'], ['client1', 'Fictional Launch Video', 'in_progress'], ['client2', 'Fictional Shop Redesign', 'in_progress']] as [$owner, $title, $status]) {
            if (Database::one('SELECT 1 AS x FROM projects WHERE title = ?', [$title]) !== null) {
                continue;
            }
            Database::run(
                'INSERT INTO projects (public_id, code, client_id, title, status, created_by, status_changed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [uuid_hex(), CrmReference::next('project'), $client($owner), $title, $status, $ids['admin']],
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

        self::seedDelivery($ids);

        return ['password' => $password, 'created' => $created, 'projects' => $projects];
    }

    /**
     * Fictional CRM enquiries, milestones and tasks so the pipeline, board and deadline pages have something to show.
     * Idempotent: skipped when the demo data already exists. Every name is marked fictional; dates are relative to today.
     *
     * @param array<string,int> $ids user ids by seed key
     */
    private static function seedDelivery(array $ids): void
    {
        if (Database::one("SELECT 1 AS x FROM enquiries WHERE contact_name LIKE '%(fictional)%'") === null) {
            foreach ([
                ['Nina Prospect (fictional)', 'nina.prospect@example.test', 'Fictional Bakery Co', 'website_development', 'A brochure website with an online ordering form for a fictional bakery.', '25k_100k'],
                ['Omar Lead (fictional)', 'omar.lead@example.test', 'Fictional Fitness Studio', 'video_editing', 'Weekly short-form video editing for a fictional fitness studio.', 'under_25k'],
                ['Rhea Inquirer (fictional)', 'rhea.inquirer@example.test', null, 'domain_hosting', 'Move a fictional personal portfolio to new hosting.', 'not_sure'],
            ] as [$name, $email, $company, $service, $desc, $budget]) {
                EnquiryService::create($ids['manager'], 'manual', [
                    'contact_name' => $name, 'email' => $email, 'company_name' => $company, 'service' => $service, 'description' => $desc, 'budget_range' => $budget, 'priority' => 'normal',
                ]);
            }
        }
        $site = Database::one("SELECT * FROM projects WHERE title = 'Fictional Brand Website'");
        if ($site === null || Database::one('SELECT 1 AS x FROM tasks WHERE project_id = ?', [$site['id']]) !== null) {
            return;
        }
        $project = ProjectPolicy::find((string) $site['public_id']);
        $pub = static fn (string $k): string => (string) Database::one('SELECT public_id FROM users WHERE id = ?', [$ids[$k]])['public_id'];
        $day = static fn (int $offset): string => AppTime::todayPlus($offset);
        Database::run('UPDATE projects SET target_date = ?, start_date = ? WHERE id = ?', [$day(21), $day(-14), $site['id']]);
        $m1 = MilestoneService::create($ids['admin'], $project, ['title' => 'Design approved', 'target_date' => $day(-2), 'visibility' => 'client']);
        $m2 = MilestoneService::create($ids['admin'], $project, ['title' => 'Staging site ready', 'target_date' => $day(10), 'visibility' => 'client']);
        $tasks = [];
        foreach ([
            ['Wireframes for all pages', 'completed', 'developer', -10, 'normal', $m1['public_id']],
            ['Build homepage', 'in_progress', 'developer', 3, 'high', $m2['public_id']],
            ['Contact form with validation', 'to_do', 'developer', 6, 'normal', $m2['public_id']],
            ['Write launch checklist', 'backlog', null, null, 'low', ''],
            ['Fix navigation on mobile', 'blocked', 'developer', -1, 'urgent', $m2['public_id']],
            ['Review hero section', 'in_review', 'developer', 2, 'normal', $m2['public_id']],
        ] as [$title, $status, $who, $due, $prio, $ms]) {
            $t = TaskService::create($ids['admin'], $project, [
                'title' => $title, 'priority' => $prio, 'milestone' => $ms, 'due_date' => $due === null ? '' : $day($due),
                'assignee' => $who === null ? '' : $pub($who), 'status' => 'to_do', 'review_required' => '1',
            ]);
            $tasks[$title] = $t['id'];
            // Demo rows are placed directly in their status; real changes always go through TaskService::transition().
            Database::run('UPDATE tasks SET status = ?, completed_at = IF(? = \'completed\', UTC_TIMESTAMP(), NULL), review_status = IF(? = \'in_review\', \'pending\', IF(? = \'completed\', \'approved\', \'not_required\')) WHERE id = ?', [$status, $status, $status, $status, $t['id']]);
        }
        Database::run('INSERT INTO task_dependencies (task_id, depends_on_task_id, created_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$tasks['Contact form with validation'], $tasks['Build homepage'], $ids['admin']]);
    }
}
