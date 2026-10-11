<?php

declare(strict_types=1);

namespace Vertex\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
    }

    /** @param array<string,string> $headers */
    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return new self($status, '', ['Location' => $to]);
    }

    /** Forces a download (never inline) of a stored file. */
    public static function download(string $path, string $name, string $mime): self
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);

        return new self(200, (string) file_get_contents($path), [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$safe}\"; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Length' => (string) filesize($path),
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
