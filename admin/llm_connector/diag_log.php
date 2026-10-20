<?php
/**
 * DiagLog — operator-facing diagnostic log (SQLite).
 * spec: spec/diag_log.md — infrastructural code, no customer TZ behind it.
 *
 * Why: when a provider call fails, the only trace used to be PHP's error_log,
 * which an operator on shared hosting cannot read. DiagLog keeps the last
 * MAX_ROWS entries in the app database so the admin page can render them,
 * copy them to the clipboard and hand them to whoever debugs the install.
 *
 * Properties that matter:
 *  - Self-contained: no SettingsStore / config coupling, own tables.
 *  - Never throws: a broken log must not break the feature being logged.
 *  - Redacted: secrets registered via addSecret() are masked in every message
 *    and context value, so a copied log can be pasted into a chat safely.
 *  - Reset on deploy: init() takes a code stamp; a changed stamp wipes the log,
 *    so what you read always describes the code currently running.
 *
 * PHP 7.4-compatible (no str_contains / str_starts_with).
 */

final class DiagLog {
    const LEVELS = ['error', 'warn', 'info'];
    const DEFAULT_MAX_ROWS = 2000;

    private static ?PDO $pdo = null;
    private static int $maxRows = self::DEFAULT_MAX_ROWS;
    private static array $secrets = [];
    private static ?object $storeAdapter = null;
    /** Set when the last init() detected a redeploy (used for the header line). */
    private static ?string $deployNote = null;

    /* ─────────────────────────── lifecycle ─────────────────────────── */

    /**
     * Open (and create) the log tables. $codeStamp is any string that changes
     * when the code is redeployed — see codeStamp(). A different stamp than the
     * stored one empties the log first.
     */
    public static function init(string $dbPath, string $codeStamp = '', int $maxRows = self::DEFAULT_MAX_ROWS): void {
        self::$maxRows = max(100, $maxRows);
        try {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS diag_log (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    ts       TEXT NOT NULL,
                    level    TEXT NOT NULL,
                    channel  TEXT NOT NULL,
                    message  TEXT NOT NULL,
                    context  TEXT
                )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS diag_log_level ON diag_log(level, id)');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS diag_state (
                    key   TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )'
            );
            self::$pdo = $pdo;
        } catch (Throwable $e) {
            self::$pdo = null;
            return;
        }
        if ($codeStamp !== '') self::applyCodeStamp($codeStamp);
    }

    public static function isReady(): bool { return self::$pdo !== null; }

    /**
     * Fingerprint of the deployed code: size+mtime of the given files. Cheap
     * (a few stat calls), and it changes on any upload — which is exactly what
     * "the log is wiped on redeploy" means here.
     */
    public static function codeStamp(array $files): string {
        $parts = [];
        foreach ($files as $f) {
            if (!is_string($f) || !is_file($f)) continue;
            $parts[] = basename($f) . ':' . (int) @filesize($f) . ':' . (int) @filemtime($f);
        }
        return $parts ? substr(md5(implode('|', $parts)), 0, 12) : '';
    }

    /** Stored stamp differs → new deployment: drop the log and remember when. */
    private static function applyCodeStamp(string $stamp): void {
        $known = self::state('code_stamp');
        if ($known === $stamp) return;
        if ($known !== null) {
            self::clear();
            self::$deployNote = 'предыдущий лог стёрт при обновлении кода';
        }
        self::setState('code_stamp', $stamp);
        self::setState('deployed_at', gmdate('Y-m-d\TH:i:s\Z'));
        self::write('info', 'deploy', $known === null
            ? 'Лог заведён (код ' . $stamp . ')'
            : 'Обнаружен редеплой: код ' . $known . ' → ' . $stamp . ', лог очищен');
    }

    public static function state(string $key): ?string {
        if (self::$pdo === null) return null;
        try {
            $stmt = self::$pdo->prepare('SELECT value FROM diag_state WHERE key = :k');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (string) $row['value'] : null;
        } catch (Throwable $e) { return null; }
    }

    public static function setState(string $key, string $value): void {
        if (self::$pdo === null) return;
        try {
            $stmt = self::$pdo->prepare(
                'INSERT INTO diag_state(key, value) VALUES (:k, :v)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value'
            );
            $stmt->execute([':k' => $key, ':v' => $value]);
        } catch (Throwable $e) { /* logging must never break the caller */ }
    }

    public static function deployNote(): ?string { return self::$deployNote; }

    /* ─────────────────────────── redaction ─────────────────────────── */

    /** Register a secret so it never reaches the log in clear text. */
    public static function addSecret(?string $value): void {
        $value = (string) $value;
        if (strlen($value) < 8) return;          // too short to mask meaningfully
        self::$secrets[$value] = self::maskKey($value);
    }

    /** "AQVN…ab12" — enough to tell WHICH key is configured, not what it is. */
    public static function maskKey(?string $value): string {
        $v = (string) $value;
        if ($v === '') return '';
        $len = strlen($v);
        if ($len <= 10) return str_repeat('•', $len);
        return substr($v, 0, 4) . '…' . substr($v, -4);
    }

    public static function redact(string $text): string {
        foreach (self::$secrets as $secret => $masked) {
            $text = str_replace($secret, $masked, $text);
        }
        return $text;
    }

    /* ─────────────────────────── writing ─────────────────────────── */

    public static function write(string $level, string $channel, string $message, array $context = []): void {
        $level = in_array($level, self::LEVELS, true) ? $level : 'info';
        // Report first: an error is worth forwarding even when the local log
        // itself is unavailable (unwritable data/, locked database).
        self::forward($level, $channel, $message, $context);
        if (self::$pdo === null) return;
        try {
            $json = $context ? json_encode(self::redactArray($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $stmt = self::$pdo->prepare(
                'INSERT INTO diag_log(ts, level, channel, message, context) VALUES (:t, :l, :c, :m, :x)'
            );
            $stmt->execute([
                ':t' => gmdate('Y-m-d\TH:i:s\Z'),
                ':l' => $level,
                ':c' => mb_substr($channel, 0, 40),
                ':m' => self::redact(mb_substr($message, 0, 4000)),
                ':x' => $json !== null ? mb_substr($json, 0, 8000) : null,
            ]);
            self::trim();
        } catch (Throwable $e) { /* logging must never break the caller */ }
    }

    /**
     * Single bridge to the self-maintaining layer: an error already logged for
     * the operator also becomes a report for the developer — but only when the
     * layer is present AND booted AND the operator agreed (SelfHeal decides).
     * DiagLog knows nothing about it beyond this call, and a missing or broken
     * selfheal directory changes nothing here.
     */
    private static function forward(string $level, string $channel, string $message, array $context): void {
        if ($level !== 'error') return;
        if (!class_exists('Selfheal\SelfHeal', false)) return;   // not installed / not booted
        try {
            \Selfheal\SelfHeal::forward($level, $channel, $message, $context);
        } catch (Throwable $e) { /* the bridge may never break logging */ }
    }

    public static function error(string $channel, string $message, array $context = []): void { self::write('error', $channel, $message, $context); }
    public static function warn(string $channel, string $message, array $context = []): void  { self::write('warn', $channel, $message, $context); }
    public static function info(string $channel, string $message, array $context = []): void  { self::write('info', $channel, $message, $context); }

    private static function redactArray(array $a): array {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v))       $out[$k] = self::redactArray($v);
            elseif (is_string($v))  $out[$k] = self::redact(mb_substr($v, 0, 2000));
            else                    $out[$k] = $v;
        }
        return $out;
    }

    /** Keep the table bounded: delete everything older than the last maxRows. */
    private static function trim(): void {
        try {
            if (random_int(1, 20) !== 1) return;   // amortised — not on every write
            self::$pdo->exec(
                'DELETE FROM diag_log WHERE id <= (SELECT MAX(id) FROM diag_log) - ' . (int) self::$maxRows
            );
        } catch (Throwable $e) { /* ignore */ }
    }

    /* ─────────────────────────── reading ─────────────────────────── */

    /** Newest-last rows (reading order), capped at $limit. */
    public static function tail(int $limit = 300, bool $errorsOnly = false): array {
        if (self::$pdo === null) return [];
        try {
            $sql = 'SELECT ts, level, channel, message, context FROM diag_log'
                . ($errorsOnly ? " WHERE level IN ('error','warn')" : '')
                . ' ORDER BY id DESC LIMIT ' . max(1, min(5000, $limit));
            $rows = self::$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_reverse($rows);
        } catch (Throwable $e) { return []; }
    }

    public static function counts(): array {
        if (self::$pdo === null) return ['total' => 0, 'errors' => 0];
        try {
            $total = (int) self::$pdo->query('SELECT COUNT(*) FROM diag_log')->fetchColumn();
            $err   = (int) self::$pdo->query("SELECT COUNT(*) FROM diag_log WHERE level IN ('error','warn')")->fetchColumn();
            return ['total' => $total, 'errors' => $err];
        } catch (Throwable $e) { return ['total' => 0, 'errors' => 0]; }
    }

    public static function clear(): void {
        if (self::$pdo === null) return;
        try { self::$pdo->exec('DELETE FROM diag_log'); } catch (Throwable $e) { /* ignore */ }
    }

    /**
     * Plain-text rendering meant to be copied into a chat: a header block with
     * the environment (so the reader needs no other context) followed by the
     * entries. $header is an ordered map of label => value.
     */
    public static function asText(array $rows, array $header = [], string $title = 'diagnostic log'): string {
        $out = ['=== ' . $title . ' ==='];
        $header = array_merge(['сформирован' => gmdate('Y-m-d\TH:i:s\Z') . ' UTC'], $header);
        foreach ($header as $k => $v) {
            if ($v === null || $v === '') $v = '—';
            $out[] = $k . ': ' . self::redact((string) $v);
        }
        $out[] = str_repeat('-', 60);
        if (!$rows) {
            $out[] = '(записей нет)';
            return implode("\n", $out);
        }
        foreach ($rows as $r) {
            $out[] = sprintf('%s %-5s %s | %s',
                (string) ($r['ts'] ?? ''),
                strtoupper((string) ($r['level'] ?? '')),
                (string) ($r['channel'] ?? ''),
                (string) ($r['message'] ?? '')
            );
            $ctx = (string) ($r['context'] ?? '');
            if ($ctx !== '') {
                $decoded = json_decode($ctx, true);
                if (is_array($decoded)) {
                    foreach (self::flatten($decoded) as $line) $out[] = '    ' . $line;
                } else {
                    $out[] = '    ' . $ctx;
                }
            }
        }
        return implode("\n", $out);
    }

    /** ['a'=>['b'=>1]] → ['a.b = 1'] — readable context in a flat text log. */
    private static function flatten(array $a, string $prefix = ''): array {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out = array_merge($out, self::flatten($v, $key));
            } else {
                $out[] = $key . ' = ' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }
        return $out;
    }

    /* ──────────────────── adapter for LLM::init($cfg, $store) ──────────────────── */

    /** Object with logLLMCall() — what LLM::init() duck-types against. */
    public static function store(): object {
        if (self::$storeAdapter === null) self::$storeAdapter = new DiagLogLLMStore();
        return self::$storeAdapter;
    }
}

/** Thin adapter: LLM call outcomes → DiagLog rows. */
final class DiagLogLLMStore {
    public function logLLMCall(?int $sessionId, string $step, string $model, string $promptVersion, int $latencyMs, string $status, ?string $error, ?string $raw): void {
        $level = in_array($status, ['ok'], true) ? 'info' : 'error';
        $ctx = [
            'step'       => $step,
            'model'      => $model,
            'status'     => $status,
            'latency_ms' => $latencyMs,
            'prompt'     => $promptVersion,
        ];
        if ($sessionId !== null) $ctx['session'] = $sessionId;
        if ($error !== null && $error !== '') $ctx['error'] = $error;
        // Successful answers are logged by shape only — user content stays out.
        if ($raw !== null && $level !== 'info') $ctx['response'] = mb_substr($raw, 0, 1000);
        if ($raw !== null && $level === 'info') $ctx['response_chars'] = mb_strlen($raw);
        DiagLog::write($level, 'llm', $status === 'ok'
            ? 'Ответ модели получен: ' . $model . ' (' . $step . ', ' . $latencyMs . ' мс)'
            : 'Вызов модели не удался: ' . $model . ' (' . $step . ', ' . $status . ')', $ctx);
    }
}
