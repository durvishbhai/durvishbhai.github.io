<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * The one place the platform makes outbound HTTPS calls (AI provider, Razorpay). Plain cURL with certificate checks always on, a short timeout, no redirects
 * followed and a size cap. Tests replace it with Http::fake() so no test ever touches the network.
 */
final class Http
{
    /** @var (callable(string,string,array<string,string>,?string,int):array{status:int,body:string})|null */
    private static $fake = null;

    /** @param callable(string,string,array<string,string>,?string,int):array{status:int,body:string}|null $fn */
    public static function fake(?callable $fn): void
    {
        self::$fake = $fn;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     * @throws \RuntimeException when the request could not be made at all
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        if (self::$fake !== null) {
            return (self::$fake)($method, $url, $headers, $body, $timeout);
        }
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Only https:// addresses are allowed.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP curl extension is not enabled on this server.');
        }
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $h, CURLOPT_USERAGENT => 'VertexPlatform/1.0', CURLOPT_MAXFILESIZE => 5_000_000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new \RuntimeException('The request failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        return ['status' => $status, 'body' => (string) $out];
    }
}
