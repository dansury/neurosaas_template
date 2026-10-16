<?php
/**
 * Scrub — everything leaving this server passes through here first.
 * spec: spec/selfheal.md §8
 *
 * A report is only acceptable if it is useful to the developer and harmless to
 * the operator. So: registered secrets are masked, anything that LOOKS like a
 * credential is masked even if nobody registered it, absolute paths are cut
 * back to the module-relative part, and e-mail / card-shaped strings are
 * removed. Applied to titles, bodies, contexts and stack traces alike.
 */

declare(strict_types=1);

namespace Selfheal;

final class Scrub {
    /** Explicitly registered secrets (API keys, passwords) → mask. */
    private static array $secrets = [];

    /** Shapes that are masked even when not registered. */
    private const PATTERNS = [
        // GitHub tokens, OpenAI/OpenRouter style keys, Yandex IAM/API keys, JWT, Bearer.
        '~\b(gh[pousr]_[A-Za-z0-9]{16,})~'                      => 'github-token',
        '~\bgithub_pat_[A-Za-z0-9_]{20,}~'                      => 'github-token',
        '~\bsk-[A-Za-z0-9\-_]{16,}~'                            => 'api-key',
        '~\bAQ[A-Za-z0-9\-_]{20,}~'                             => 'yandex-key',
        '~\bey[A-Za-z0-9\-_]{10,}\.[A-Za-z0-9\-_]{10,}\.[A-Za-z0-9\-_]{10,}~' => 'jwt',
        '~(?i)\b(bearer|authorization:)\s+\S+~'                 => 'authorization',
        '~(?i)\b(api[-_]?key|token|password|passwd|secret)\s*[=:]\s*\S+~' => 'credential',
        // Personal data that has no business in a bug report.
        '~[\w.+-]+@[\w-]+\.[\w.]{2,}~'                          => 'email',
        '~\b(?:\d[ -]?){13,19}\b~'                              => 'card-like',
    ];

    public static function addSecret(?string $value): void {
        $value = (string) $value;
        if (strlen($value) < 8) return;                 // too short to mask safely
        self::$secrets[$value] = self::mask($value);
    }

    public static function forgetSecrets(): void { self::$secrets = []; }

    /** "AQVN…ab12" — says WHICH key, never what it is. */
    public static function mask(?string $value): string {
        $v = (string) $value;
        if ($v === '') return '';
        $len = strlen($v);
        if ($len <= 10) return str_repeat('*', $len);
        return substr($v, 0, 4) . '…' . substr($v, -4);
    }

    /** Full pass: registered secrets, credential shapes, paths. */
    public static function text(string $text): string {
        foreach (self::$secrets as $secret => $masked) $text = str_replace($secret, $masked, $text);
        foreach (self::PATTERNS as $re => $label) {
            $text = (string) preg_replace($re, '[' . $label . ' скрыт]', $text);
        }
        return self::paths($text);
    }

    /**
     * Absolute paths → module-relative. Two reasons: a filesystem layout is
     * information about someone else's server, and a report is far more useful
     * when the same file reads the same way across every install.
     */
    public static function paths(string $text): string {
        $root = defined('SELFHEAL_MODULE_ROOT') ? (string) SELFHEAL_MODULE_ROOT : '';
        if ($root !== '') {
            $text = str_replace([$root . DIRECTORY_SEPARATOR, $root], ['<module>/', '<module>'], $text);
            $parent = dirname($root);
            if ($parent !== '' && $parent !== '.' && $parent !== DIRECTORY_SEPARATOR) {
                $text = str_replace([$parent . DIRECTORY_SEPARATOR, $parent], ['<host>/', '<host>'], $text);
            }
        }
        $doc = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($doc !== '' && strlen($doc) > 3) $text = str_replace($doc, '<docroot>', $text);
        // Anything left that still looks like a home directory.
        return (string) preg_replace('~/(?:home|Users)/[^/\s\'"]+~', '<home>', $text);
    }

    /** Recursive scrub of a context array; values are also length-capped. */
    public static function context(array $a, int $depth = 0): array {
        if ($depth > 4) return ['…' => 'обрезано'];
        $out = [];
        foreach ($a as $k => $v) {
            $key = self::text((string) $k);
            if (is_array($v))          $out[$key] = self::context($v, $depth + 1);
            elseif (is_string($v))     $out[$key] = self::text(mb_substr($v, 0, 800));
            elseif (is_scalar($v))     $out[$key] = $v;
            elseif ($v === null)       $out[$key] = null;
            else                       $out[$key] = '[' . (is_object($v) ? get_class($v) : gettype($v)) . ']';
        }
        return $out;
    }

    /**
     * Stack trace → a few scrubbed lines, module frames only by default.
     * Host frames are dropped: they are not our bug and not our business.
     */
    public static function trace(array $frames, bool $moduleOnly = true, int $limit = 12): array {
        $root = defined('SELFHEAL_MODULE_ROOT') ? (string) SELFHEAL_MODULE_ROOT : '';
        $out  = [];
        foreach ($frames as $f) {
            if (!is_array($f)) continue;
            $file = (string) ($f['file'] ?? '');
            $mine = $root !== '' && $file !== '' && strpos($file, $root) === 0;
            if ($moduleOnly && !$mine) {
                $out[] = '… (кадр вызывающего проекта пропущен)';
                continue;
            }
            $fn = (string) ($f['class'] ?? '') . (string) ($f['type'] ?? '') . (string) ($f['function'] ?? '');
            $out[] = self::paths($file) . ':' . (int) ($f['line'] ?? 0) . ' ' . $fn . '()';
            if (count($out) >= $limit) break;
        }
        // Collapse runs of skipped host frames into one line.
        $collapsed = [];
        foreach ($out as $line) {
            if ($line === '… (кадр вызывающего проекта пропущен)' && end($collapsed) === $line) continue;
            $collapsed[] = $line;
        }
        return $collapsed;
    }
}
