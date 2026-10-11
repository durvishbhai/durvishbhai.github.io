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
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['project_assignments', 'projects', 'referrals', 'clients', 'auth_tokens', 'rate_limits', 'user_roles', 'users', 'login_attempts', 'audit_logs'] as $t) {
            $pdo->exec("TRUNCATE TABLE {$t}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
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
            Database::run('INSERT INTO clients (public_id, user_id, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [uuid_hex(), $id]);
        }

        return $id;
    }

    /** Creates a project owned by the given CLIENT user. @return array{id:int,public_id:string} */
    protected function makeProject(int $clientUserId, string $title = 'Test project'): array
    {
        $client = Database::one('SELECT id FROM clients WHERE user_id = ?', [$clientUserId]);
        $pub = uuid_hex();
        Database::run(
            "INSERT INTO projects (public_id, client_id, title, status, created_at, updated_at) VALUES (?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$pub, $client['id'], $title],
        );

        return ['id' => Database::insertId(), 'public_id' => $pub];
    }

    protected function publicId(int $userId): string
    {
        return (string) Database::one('SELECT public_id FROM users WHERE id = ?', [$userId])['public_id'];
    }
}
