<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Logger;

/**
 * Phone and browser notifications: every in-app notification is also pushed to the devices its person allowed notifications on (Notifier hands each new row here,
 * exactly as it does to EmailChannel). Sent after the response; a failure is logged and never reaches the workflow. A device that has been uninstalled or has
 * revoked permission is forgotten automatically.
 */
final class PushChannel
{
    private const MAX_PER_USER = 10;
    /** @var list<int> */
    private static array $queue = [];
    private static bool $hooked = false;

    public static function enabled(): bool
    {
        return (bool) Config::get('notifications.push', true);
    }

    public static function enqueue(int $notificationId): void
    {
        if ($notificationId <= 0 || !self::enabled()) {
            return;
        }
        self::$queue[] = $notificationId;
        if (!self::$hooked && PHP_SAPI !== 'cli') {
            self::$hooked = true;
            EmailChannel::afterResponse(static fn () => self::flush());
        }
    }

    /** @return int messages delivered */
    public static function flush(): int
    {
        $ids = array_values(array_unique(self::$queue));
        self::$queue = [];
        $sent = 0;
        foreach ($ids as $id) {
            try {
                $sent += self::deliver($id);
            } catch (\Throwable $e) {
                Logger::error('Push delivery failed', ['notification_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** @return int number of devices reached */
    public static function deliver(int $id): int
    {
        $n = Database::one('SELECT n.id, n.user_id, n.event_type, n.title, n.body, n.link FROM notifications n JOIN users u ON u.id = n.user_id AND u.status = \'active\' WHERE n.id = ?', [$id]);
        if ($n === null) {
            return 0;
        }
        $link = (string) ($n['link'] ?? '');
        $payload = self::payload((string) $n['title'], (string) ($n['body'] ?? ''), $link !== '' && $link[0] === '/' ? $link : '/notifications', 'n-' . $n['id']);

        return self::sendToUser((int) $n['user_id'], $payload, in_array($n['event_type'], ['payment.confirmed', 'payment.failed', 'payment.not_confirmed', 'payment.requested'], true) ? 'high' : 'normal');
    }

    /** The message the phone shows. @return string JSON */
    public static function payload(string $title, string $body, string $url, string $tag): string
    {
        return (string) json_encode([
            'title' => mb_substr($title, 0, 100), 'body' => mb_substr($body, 0, 160), 'url' => $url, 'tag' => $tag,
            'icon' => asset('img/pwa-192.png'), 'badge' => asset('img/pwa-badge.png'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function sendToUser(int $userId, string $payloadJson, string $urgency = 'normal'): int
    {
        $reached = 0;
        foreach (Database::all('SELECT id, endpoint, p256dh, auth_secret FROM push_subscriptions WHERE user_id = ?', [$userId]) as $sub) {
            $r = WebPush::send($sub, $payloadJson, $urgency);
            if ($r === 'sent') {
                $reached++;
                Database::run('UPDATE push_subscriptions SET last_success_at = UTC_TIMESTAMP(), failures = 0 WHERE id = ?', [$sub['id']]);
            } elseif ($r === 'gone') {
                Database::run('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
            } else {
                Database::run('UPDATE push_subscriptions SET failures = LEAST(failures + 1, 250) WHERE id = ?', [$sub['id']]);
                Database::run('DELETE FROM push_subscriptions WHERE id = ? AND failures >= 8', [$sub['id']]);
            }
        }

        return $reached;
    }

    // ------------------------------------------------------------------------------------------------ subscriptions

    /** Saves (or refreshes) this device for the person. @throws \InvalidArgumentException with a safe message */
    public static function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): void
    {
        if (!WebPush::allowedEndpoint($endpoint)) {
            throw new \InvalidArgumentException('This browser\'s notification service is not supported.');
        }
        $pub = WebPush::b64d($p256dh);
        $sec = WebPush::b64d($auth);
        if (strlen($pub) !== 65 || $pub[0] !== "\x04" || strlen($sec) < 16 || strlen($sec) > 32) {
            throw new \InvalidArgumentException('The notification keys of this device are not valid.');
        }
        $hash = hash('sha256', $endpoint);
        Database::run(
            'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth_secret, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth_secret = VALUES(auth_secret), user_agent = VALUES(user_agent), failures = 0',
            [$userId, $hash, $endpoint, $p256dh, $auth, mb_substr($userAgent, 0, 250)],
        );
        // keep the newest few devices per person
        Database::run('DELETE FROM push_subscriptions WHERE user_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT ' . self::MAX_PER_USER . ') k)', [$userId, $userId]);
    }

    public static function unsubscribe(int $userId, string $endpoint): void
    {
        Database::run('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$userId, hash('sha256', $endpoint)]);
    }

    public static function deviceCount(int $userId): int
    {
        return (int) Database::one('SELECT COUNT(*) AS c FROM push_subscriptions WHERE user_id = ?', [$userId])['c'];
    }
}
