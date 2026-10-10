<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Services\Auth;
use Vertex\Services\Gate;
use Vertex\Services\Migrator;
use Vertex\Services\PermissionSync;

/** Rebuilds a throwaway schema once per class. Refuses to touch any database not named *_test / *_phpunit. */
abstract class DatabaseTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
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
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate();
        PermissionSync::run();
    }

    protected function setUp(): void
    {
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['user_roles', 'users', 'login_attempts', 'audit_logs'] as $t) {
            $pdo->exec("TRUNCATE TABLE {$t}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $_SESSION = [];
        Auth::reset();
        Gate::flush();
    }
}
