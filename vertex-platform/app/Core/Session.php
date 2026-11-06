<?php

declare(strict_types=1);

namespace Vertex\Core;

/**
 * Hardened PHP session wrapper: strict mode, HttpOnly + SameSite=Lax cookie, Secure when on HTTPS,
 * idle + absolute timeouts, user-agent binding, ID regeneration helper for privilege changes.
 */
final class Session
{
    private static bool $started = false;

    public static function start(Request $request): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }
        $cfg = Config::get('session', []);
        $path = VERTEX_ROOT . '/storage/sessions';
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (((int) ($cfg['absolute_minutes'] ?? 720)) * 60));
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }
        session_name((string) ($cfg['name'] ?? 'vertex_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $request->isSecure() || !empty($cfg['force_secure']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        $now = time();
        $uaHash = hash('sha256', $request->userAgent());
        if (isset($_SESSION['_meta'])) {
            $m = $_SESSION['_meta'];
            $idle = $cfg['idle_seconds'] ?? ((int) ($cfg['idle_minutes'] ?? 60)) * 60;
            $abs = ((int) ($cfg['absolute_minutes'] ?? 720)) * 60;
            if ($now - $m['last'] > $idle || $now - $m['created'] > $abs || !hash_equals($m['ua'], $uaHash)) {
                self::destroy();
                session_start();
                $_SESSION = [];
            }
        }
        $_SESSION['_meta'] = [
            'created' => $_SESSION['_meta']['created'] ?? $now,
            'last' => $now,
            'ua' => $uaHash,
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $v;
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function pullFlash(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);

        return $v;
    }

    /** Call on login/logout/privilege change to prevent session fixation. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            $p = session_get_cookie_params();
            if (!headers_sent()) {
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            }
            session_destroy();
        }
        self::$started = false;
    }
}
