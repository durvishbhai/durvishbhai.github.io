<?php

declare(strict_types=1);

namespace Vertex\Core;

final class Request
{
    private static ?string $requestId = null;

    /** Per-request scratch values set by the router/middleware (e.g. matched route options). @var array<string,mixed> */
    public array $attributes = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $server = [],
        public readonly array $files = [],
    ) {
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $body = $_POST;
        // HTML forms only do GET/POST; allow explicit override on POST only.
        if ($method === 'POST' && isset($body['_method']) && in_array(strtoupper((string) $body['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $body['_method']);
        }

        $uri = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $uri = '/' . trim(rawurldecode($uri), '/');

        return new self($method, $uri, $_GET, $body, $server, $_FILES);
    }

    public static function id(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);

        return is_string($v) ? trim($v) : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->query + $this->body;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        if (($this->server['SERVER_PORT'] ?? null) == 443) {
            return true;
        }

        return $this->fromTrustedProxy() && strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
    }

    /** Client IP. X-Forwarded-For is honoured ONLY when REMOTE_ADDR is a configured trusted proxy. */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($this->fromTrustedProxy()) {
            $xff = (string) $this->header('X-Forwarded-For');
            if ($xff !== '') {
                $candidate = trim(explode(',', $xff)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    public function cookie(string $name): ?string
    {
        $v = $_COOKIE[$name] ?? null;

        return is_string($v) ? $v : null;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function expectsJson(): bool
    {
        return str_contains((string) $this->header('Accept'), 'application/json');
    }

    private function fromTrustedProxy(): bool
    {
        $trusted = Config::get('app.trusted_proxies', []);

        return is_array($trusted) && in_array((string) ($this->server['REMOTE_ADDR'] ?? ''), $trusted, true);
    }
}
