<?php
/**
 * Http — the module's only outbound transport.
 * spec: spec/selfheal.md §6
 *
 * Hard rules: never throws, always bounded by a wall-clock timeout, never
 * follows a redirect to a non-HTTPS host, never prints. A host page must not
 * be able to hang because GitHub is slow.
 */

declare(strict_types=1);

namespace Selfheal;

final class Http {
    /** Absolute ceiling for a single call, seconds — nothing may exceed it. */
    public const MAX_TIMEOUT = 20;

    /** GET/POST/PATCH JSON. Returns [status, decoded|null, rawBody, error]. */
    public static function json(string $method, string $url, array $headers = [], ?array $body = null, int $timeout = 8): array {
        $payload = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        [$status, $raw, $err] = self::raw($method, $url, $headers, $payload, $timeout);
        $decoded = null;
        if ($raw !== '') {
            $try = json_decode($raw, true);
            if (is_array($try)) $decoded = $try;
        }
        return [$status, $decoded, $raw, $err];
    }

    /** Raw transfer. Returns [status, body, error]; status 0 means "no answer". */
    public static function raw(string $method, string $url, array $headers = [], ?string $payload = null, int $timeout = 8): array {
        if (strpos($url, 'https://') !== 0) return [0, '', 'only https is allowed'];
        if (!function_exists('curl_init'))   return [0, '', 'ext-curl is missing'];
        $timeout = max(1, min(self::MAX_TIMEOUT, $timeout));

        $ch = curl_init($url);
        if ($ch === false) return [0, '', 'curl_init failed'];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json', 'User-Agent: ' . self::agent()], $headers),
        ];
        if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = $payload;
        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = $raw === false ? (string) curl_error($ch) : '';
        curl_close($ch);
        return [$status, is_string($raw) ? $raw : '', $err];
    }

    /** Download to a local file. Returns [ok, bytes, error]. */
    public static function download(string $url, string $dest, array $headers = [], int $timeout = self::MAX_TIMEOUT): array {
        if (strpos($url, 'https://') !== 0) return [false, 0, 'only https is allowed'];
        if (!function_exists('curl_init'))   return [false, 0, 'ext-curl is missing'];
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return [false, 0, 'cannot create ' . $dir];
        $fh = @fopen($dest, 'wb');
        if ($fh === false) return [false, 0, 'cannot write ' . $dest];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,      // codeload redirects; still https-only below
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT        => max(5, min(120, $timeout)),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: ' . self::agent()], $headers),
        ]);
        $ok     = curl_exec($ch) !== false;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = $ok ? '' : (string) curl_error($ch);
        curl_close($ch);
        fclose($fh);

        $bytes = (int) @filesize($dest);
        if ($ok && $status >= 200 && $status < 300 && $bytes > 0) return [true, $bytes, ''];
        @unlink($dest);
        return [false, 0, $err !== '' ? $err : 'HTTP ' . $status];
    }

    private static function agent(): string {
        return Contract::MODULE . '/' . Contract::version() . ' (self-maintaining module)';
    }
}
