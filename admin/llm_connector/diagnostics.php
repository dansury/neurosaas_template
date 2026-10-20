<?php
declare(strict_types=1);
namespace NeuroSaaS;

/**
 * Local warning/fatal capture and consented feedback snapshots.
 * Behavior ported from kp-atlant lib/logger.php and lib/support.php.
 * Reuses DiagLog; never starts reporting, mail or AI requests.
 */
final class Diagnostics
{
    private static bool $installed = false;
    private static bool $busy = false;
    private static string $lastFatal = '';

    public static function install(): void
    {
        if (self::$installed) return;
        self::$installed = true;
        $previous = set_error_handler(static function (int $type, string $message, string $file, int $line) use (&$previous): bool {
            if (error_reporting() & $type) self::capture($type, $file, $line);
            return $previous ? (bool)$previous($type, $message, $file, $line) : false;
        });
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                self::capture((int)$error['type'], (string)$error['file'], (int)$error['line']);
            }
        });
    }

    /** Arbitrary PHP messages may contain customer payloads: retain location/type only. */
    private static function capture(int $type, string $file, int $line): void
    {
        if (self::$busy || !class_exists('DiagLog', false) || !\DiagLog::isReady()) return;
        self::$busy = true;
        try {
            $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
            $file = str_replace('\\', '/', $file);
            $relative = strpos($file, $root) === 0 ? substr($file, strlen($root)) : basename($file);
            $fatal = in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
            if ($fatal) {
                $fingerprint = $type . ':' . $relative . ':' . $line;
                if (self::$lastFatal === $fingerprint) return;
                self::$lastFatal = $fingerprint;
            }
            \DiagLog::write($fatal ? 'error' : 'warn', 'php', 'PHP error type ' . $type,
                ['file' => $relative, 'line' => $line, 'errno' => $type]);
        } catch (\Throwable $ignored) {
            // Diagnostics must never interrupt the request they describe.
        } finally {
            self::$busy = false;
        }
    }

    public static function boot(): void
    {
        try {
            require_once __DIR__ . '/toolkit.php';
            Toolkit::boot();
            self::install();
        } catch (\Throwable $ignored) {
            // Installation still works if the optional diagnostic store cannot open.
        }
    }

    /** Safe export excludes prompts, request URLs, payloads and arbitrary error messages. */
    public static function snapshot(): string
    {
        self::boot();
        if (!class_exists('DiagLog', false) || !\DiagLog::isReady()) return 'Diagnostic log unavailable.';
        $lines = ['Service diagnostics', 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'UTC ' . gmdate('Y-m-d H:i:s')];
        foreach (\DiagLog::tail(100, true) as $row) {
            if (!in_array($row['level'] ?? '', ['error', 'warn'], true)) continue;
            $channel = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($row['channel'] ?? ''));
            $context = json_decode((string)($row['context'] ?? ''), true);
            $safe = [];
            foreach (['line', 'errno', 'status', 'http_code'] as $key) {
                if (isset($context[$key]) && is_numeric($context[$key])) $safe[$key] = (int)$context[$key];
            }
            if ($channel === 'php' && isset($context['file'])) {
                $file = (string)$context['file'];
                // Capture creates relative paths; reject absolute/URI values from other callers.
                if (preg_match('~^[a-zA-Z0-9_./-]+\\.php$~D', $file) && strpos($file, '..') === false) $safe['file'] = $file;
            }
            $lines[] = (string)$row['ts'] . ' ' . strtoupper((string)$row['level']) . ' [' . substr($channel, 0, 40) . '] ' .
                ($channel === 'php' ? 'PHP error' : 'Module error; inspect the local log for details') . ' ' .
                json_encode($safe, JSON_UNESCAPED_SLASHES);
        }
        if (count($lines) === 3) $lines[] = 'No recorded warnings or errors.';
        return substr(implode("\n", $lines), 0, 14000);
    }
}
