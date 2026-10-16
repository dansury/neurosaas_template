<?php
/**
 * State — the module's OWN SQLite file: settings, report queue, issue map.
 * spec: spec/selfheal.md §3
 *
 * Deliberately separate from the host's database (and from the module's own
 * app.db): a self-maintaining layer must never be able to lock, migrate or
 * corrupt data that belongs to someone else. Every method swallows failure —
 * if the file cannot be opened, the module keeps working with reporting off.
 */

declare(strict_types=1);

namespace Selfheal;

use PDO;
use Throwable;

final class State {
    private ?PDO $pdo = null;
    private string $path;

    public function __construct(string $path) {
        $this->path = $path;
        try {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 2000');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sh_kv (
                    k  TEXT PRIMARY KEY,
                    v  TEXT NOT NULL,
                    at TEXT NOT NULL
                 )'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sh_queue (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    fp       TEXT NOT NULL,
                    level    TEXT NOT NULL,
                    ts       INTEGER NOT NULL,
                    title    TEXT NOT NULL,
                    payload  TEXT NOT NULL,
                    tries    INTEGER NOT NULL DEFAULT 0,
                    next_try INTEGER NOT NULL DEFAULT 0,
                    sent_at  INTEGER,
                    issue    INTEGER,
                    note     TEXT
                 )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS sh_queue_pending ON sh_queue(sent_at, next_try)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS sh_queue_fp ON sh_queue(fp, ts)');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sh_issues (
                    fp      TEXT PRIMARY KEY,
                    issue   INTEGER NOT NULL,
                    seen    INTEGER NOT NULL DEFAULT 1,
                    first   INTEGER NOT NULL,
                    last    INTEGER NOT NULL,
                    bumped  INTEGER NOT NULL DEFAULT 0
                 )'
            );
            $this->pdo = $pdo;
        } catch (Throwable $e) {
            $this->pdo = null;
        }
    }

    public function ready(): bool { return $this->pdo !== null; }
    public function path(): string { return $this->path; }

    /* ───────────────────────── key/value ───────────────────────── */

    public function get(string $key, ?string $default = null): ?string {
        if ($this->pdo === null) return $default;
        try {
            $st = $this->pdo->prepare('SELECT v FROM sh_kv WHERE k = :k');
            $st->execute([':k' => $key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? (string) $row['v'] : $default;
        } catch (Throwable $e) { return $default; }
    }

    public function set(string $key, string $value): void {
        if ($this->pdo === null) return;
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO sh_kv(k, v, at) VALUES (:k, :v, :t)
                 ON CONFLICT(k) DO UPDATE SET v = excluded.v, at = excluded.at'
            );
            $st->execute([':k' => $key, ':v' => $value, ':t' => gmdate('Y-m-d\TH:i:s\Z')]);
        } catch (Throwable $e) { /* state is best-effort by design */ }
    }

    public function forget(string $key): void {
        if ($this->pdo === null) return;
        try {
            $st = $this->pdo->prepare('DELETE FROM sh_kv WHERE k = :k');
            $st->execute([':k' => $key]);
        } catch (Throwable $e) { /* ignore */ }
    }

    /* ───────────────────────── report queue ───────────────────────── */

    /** Enqueue one report. Returns the row id, or 0 when it was not stored. */
    public function enqueue(string $fp, string $level, string $title, array $payload): int {
        if ($this->pdo === null) return 0;
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO sh_queue(fp, level, ts, title, payload, next_try)
                 VALUES (:fp, :lv, :ts, :ti, :pl, 0)'
            );
            $st->execute([
                ':fp' => $fp, ':lv' => $level, ':ts' => time(),
                ':ti' => mb_substr($title, 0, 200),
                ':pl' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (Throwable $e) { return 0; }
    }

    /** Oldest unsent rows whose backoff has expired. */
    public function pending(int $limit = 2): array {
        if ($this->pdo === null) return [];
        try {
            $st = $this->pdo->prepare(
                'SELECT * FROM sh_queue WHERE sent_at IS NULL AND next_try <= :now
                 ORDER BY id ASC LIMIT :lim'
            );
            $st->bindValue(':now', time(), PDO::PARAM_INT);
            $st->bindValue(':lim', max(1, min(20, $limit)), PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    public function markSent(int $id, ?int $issue, string $note = ''): void {
        if ($this->pdo === null) return;
        try {
            $st = $this->pdo->prepare('UPDATE sh_queue SET sent_at = :t, issue = :i, note = :n WHERE id = :id');
            $st->execute([':t' => time(), ':i' => $issue, ':n' => mb_substr($note, 0, 300), ':id' => $id]);
        } catch (Throwable $e) { /* ignore */ }
    }

    public function markFailed(int $id, int $tries, string $note): void {
        if ($this->pdo === null) return;
        // 2^tries minutes, capped at 6 hours — a broken network costs nothing.
        $delay = (int) min(21600, 60 * pow(2, max(0, min(10, $tries))));
        try {
            $st = $this->pdo->prepare('UPDATE sh_queue SET tries = :tr, next_try = :nt, note = :n WHERE id = :id');
            $st->execute([':tr' => $tries, ':nt' => time() + $delay, ':n' => mb_substr($note, 0, 300), ':id' => $id]);
        } catch (Throwable $e) { /* ignore */ }
    }

    /** How many reports were accepted into the queue since $since. */
    public function countSince(int $since, ?string $fp = null): int {
        if ($this->pdo === null) return 0;
        try {
            $sql = 'SELECT COUNT(*) FROM sh_queue WHERE ts >= :s' . ($fp !== null ? ' AND fp = :fp' : '');
            $st  = $this->pdo->prepare($sql);
            $st->bindValue(':s', $since, PDO::PARAM_INT);
            if ($fp !== null) $st->bindValue(':fp', $fp);
            $st->execute();
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    public function queueStats(): array {
        $out = ['queued' => 0, 'sent' => 0, 'failing' => 0];
        if ($this->pdo === null) return $out;
        try {
            $out['queued']  = (int) $this->pdo->query('SELECT COUNT(*) FROM sh_queue WHERE sent_at IS NULL')->fetchColumn();
            $out['sent']    = (int) $this->pdo->query('SELECT COUNT(*) FROM sh_queue WHERE sent_at IS NOT NULL')->fetchColumn();
            $out['failing'] = (int) $this->pdo->query('SELECT COUNT(*) FROM sh_queue WHERE sent_at IS NULL AND tries > 0')->fetchColumn();
        } catch (Throwable $e) { /* ignore */ }
        return $out;
    }

    /** Newest queue rows for the admin panel (payload already decoded). */
    public function recent(int $limit = 20): array {
        if ($this->pdo === null) return [];
        try {
            $st = $this->pdo->prepare('SELECT id, fp, level, ts, title, tries, sent_at, issue, note FROM sh_queue ORDER BY id DESC LIMIT :lim');
            $st->bindValue(':lim', max(1, min(100, $limit)), PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    /** Drop the whole local queue (admin "забыть отчёты"). */
    public function clearQueue(): void {
        if ($this->pdo === null) return;
        try { $this->pdo->exec('DELETE FROM sh_queue'); } catch (Throwable $e) { /* ignore */ }
    }

    /** Keep the queue bounded — oldest sent rows go first. */
    public function trimQueue(int $keep = 300): void {
        if ($this->pdo === null) return;
        try {
            $this->pdo->exec(
                'DELETE FROM sh_queue WHERE id NOT IN (SELECT id FROM sh_queue ORDER BY id DESC LIMIT ' . max(50, (int) $keep) . ')'
            );
        } catch (Throwable $e) { /* ignore */ }
    }

    /* ───────────────────────── fingerprint → issue ───────────────────────── */

    public function issueFor(string $fp): ?array {
        if ($this->pdo === null) return null;
        try {
            $st = $this->pdo->prepare('SELECT * FROM sh_issues WHERE fp = :fp');
            $st->execute([':fp' => $fp]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }

    public function rememberIssue(string $fp, int $issue, bool $bumped = false): void {
        if ($this->pdo === null) return;
        $now = time();
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO sh_issues(fp, issue, seen, first, last, bumped)
                 VALUES (:fp, :i, 1, :n, :n, :b)
                 ON CONFLICT(fp) DO UPDATE SET
                     issue  = excluded.issue,
                     seen   = sh_issues.seen + 1,
                     last   = excluded.last,
                     bumped = CASE WHEN :b > 0 THEN excluded.last ELSE sh_issues.bumped END'
            );
            $st->execute([':fp' => $fp, ':i' => $issue, ':n' => $now, ':b' => $bumped ? $now : 0]);
        } catch (Throwable $e) { /* ignore */ }
    }
}
