<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Auth;
use Vertex\Services\Gate;
use Vertex\Services\Mailer;
use Vertex\Services\Migrator;
use Vertex\Services\PermissionSync;

/**
 * Rebuilds a throwaway schema once per class. Refuses to touch any database not named *_test / *_phpunit.
 * Provides small factories so tests read as scenarios.
 */
abstract class DatabaseTestCase extends TestCase
{
    public const PASSWORD = 'correct-horse-battery-staple';

    public static function setUpBeforeClass(): void
    {
        static::guardTestDatabase();
        static::prepareSchema();
    }

    protected static function guardTestDatabase(): void
    {
        $name = (string) Config::get('database.database');
        if (!preg_match('/_(test|phpunit)$/', $name)) {
            self::markTestSkipped('Set DB_DATABASE to a throwaway database ending in _test or _phpunit in .env.testing.');
        }
        try {
            Database::connection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database unavailable: ' . $e->getMessage());
        }
    }

    protected static function dropAllTables(): void
    {
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected static function prepareSchema(): void
    {
        self::dropAllTables();
        (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate();
        PermissionSync::run();
    }

    protected function setUp(): void
    {
        $pdo = Database::connection();
        foreach (['stored_files', 'attachments'] as $t) { // remove files written by earlier tests
            foreach ($pdo->query("SELECT stored_path FROM {$t}")->fetchAll(\PDO::FETCH_COLUMN) as $rel) {
                if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{40}$#', (string) $rel)) {
                    @unlink(\Vertex\Services\FileStore::root() . '/' . $rel);
                }
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        // Every table is emptied except the catalogue that permissions:sync owns and the migration ledger, so new modules never need to be listed here.
        $keep = ['schema_migrations', 'permissions', 'roles', 'role_permissions', 'crm_stages', 'priority_pricing_rules'];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            if (!in_array($t, $keep, true)) {
                $pdo->exec('TRUNCATE TABLE `' . $t . '`');
            }
        }
        $pdo->exec('DELETE FROM roles WHERE is_system = 0'); // custom roles created by a test
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->exec('INSERT INTO business_profile (id, updated_at) VALUES (1, UTC_TIMESTAMP())');
        $_SESSION = [];
        Auth::reset();
        Gate::flush();
        Mailer::reset();
    }

    /** @param list<string> $roles */
    protected function makeUser(string $email, array $roles, string $status = 'active', string $password = self::PASSWORD, ?string $phone = '+91 90000 00000'): int
    {
        $id = (new UserRepository())->create('User ' . $email, $email, password_hash($password, PASSWORD_DEFAULT), $status, $roles, null, $phone, $status === 'active');
        if (in_array('client', $roles, true)) {
            $this->makeClientRecord($id, 'User ' . $email, $email);
        }

        return $id;
    }

    /** Creates the client profile row for a portal user. @return int client id */
    protected function makeClientRecord(int $userId, string $name, string $email, ?string $company = null): int
    {
        Database::run(
            "INSERT INTO clients (public_id, user_id, client_type, contact_name, email, company_name, company_norm, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [uuid_hex(), $userId, $company ? 'business' : 'individual', $name, $email, $company, $company ? mb_strtolower($company) : null],
        );

        return Database::insertId();
    }

    /** Creates a client WITHOUT a portal login. @return array{id:int,public_id:string} */
    protected function makeLooseClient(string $name, string $email, ?string $company = null, ?string $phone = null): array
    {
        $pub = uuid_hex();
        Database::run(
            "INSERT INTO clients (public_id, user_id, client_type, contact_name, email, phone, phone_digits, company_name, company_norm, status, created_at, updated_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$pub, $company ? 'business' : 'individual', $name, $email, $phone, $phone === null ? null : substr(preg_replace('/\D/', '', $phone), -10), $company, $company ? mb_strtolower($company) : null],
        );

        return ['id' => Database::insertId(), 'public_id' => $pub];
    }

    /** Creates a project owned by the given CLIENT user. @return array{id:int,public_id:string} */
    protected function makeProject(int $clientUserId, string $title = 'Test project'): array
    {
        $client = Database::one('SELECT id FROM clients WHERE user_id = ?', [$clientUserId]);
        $pub = uuid_hex();
        Database::run(
            "INSERT INTO projects (public_id, code, client_id, title, status, status_changed_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'in_progress', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$pub, \Vertex\Services\CrmReference::next('project'), $client['id'], $title],
        );

        return ['id' => Database::insertId(), 'public_id' => $pub];
    }

    protected function publicId(int $userId): string
    {
        return (string) Database::one('SELECT public_id FROM users WHERE id = ?', [$userId])['public_id'];
    }
}
