<?php
declare(strict_types=1);

final class Http
{
    /** Send JSON with the given method. Returns [status, bodyText]. Throws on transport failure. */
    public static function json(string $method, string $url, ?array $body, array $headers = [], int $timeout = 40): array
    {
        $ch = curl_init($url);
        $hdr = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $out = curl_exec($ch);
        if ($out === false) {
            throw new RuntimeException("HTTP transport error: " . curl_error($ch));
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$status, (string)$out];
    }

    public static function postJson(string $url, array $body, array $headers = [], int $timeout = 40): array
    {
        return self::json('POST', $url, $body, $headers, $timeout);
    }
}
