<?php

declare(strict_types=1);

namespace Vertex\Tests\Support;

/** Minimal cookie-aware curl client for end-to-end tests. Never follows redirects, so tests can assert on them. */
final class HttpClient
{
    private string $jar;

    /** @var list<string> raw Set-Cookie header lines seen on the last response */
    public array $setCookies = [];

    /** Page used to obtain a CSRF token for POSTs (a signed-in client uses a page that has a form). */
    public string $formPage = '/login';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'vtxjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * @param array<string,mixed> $form
     * @param list<string> $extraHeaders
     * @return array{status:int,body:string,location:?string}
     */
    public function request(string $method, string $path, array $form = [], array $extraHeaders = []): array
    {
        $ch = curl_init($this->base . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'vertex-phpunit',
            CURLOPT_HTTPHEADER => $extraHeaders,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                $headers[] = trim($line);

                return strlen($line);
            },
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->setCookies = array_values(array_filter($headers, static fn (string $h): bool => stripos($h, 'set-cookie:') === 0));
        $location = null;
        foreach ($headers as $h) {
            if (stripos($h, 'location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }

        return ['status' => $status, 'body' => $body, 'location' => $location];
    }

    /** @return array{status:int,body:string,location:?string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * POST with a CSRF token taken from a form on $formPage (default: the login page, which any visitor can load).
     *
     * @param array<string,mixed> $form
     * @return array{status:int,body:string,location:?string}
     */
    public function post(string $path, array $form = [], ?string $formPage = null): array
    {
        $form['_token'] = $this->token($formPage ?? $this->formPage);

        return $this->request('POST', $path, $form);
    }

    /** @param array<string,mixed> $form @return array{status:int,body:string,location:?string} */
    public function postNoToken(string $path, array $form = []): array
    {
        return $this->request('POST', $path, $form);
    }

    public function token(string $page = '/login'): string
    {
        $r = $this->get($page);
        if (!preg_match('/name="_token" value="([a-f0-9]{64})"/', $r['body'], $m)) {
            throw new \RuntimeException("No CSRF token found on {$page} (status {$r['status']}).");
        }

        return $m[1];
    }

    /** Cookie value from the jar (HttpOnly cookies are stored with a #HttpOnly_ prefix). */
    public function cookie(string $name): ?string
    {
        foreach (file($this->jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = preg_replace('/^#HttpOnly_/', '', $line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === $name) {
                return $parts[6];
            }
        }

        return null;
    }

    public function login(string $email, string $password): array
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }
}
