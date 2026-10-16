<?php
/**
 * SettingsStore — operator-editable key/value settings (SQLite, PDO).
 * spec: spec/settings.md §2 — infrastructural code, no customer TZ behind it.
 * config.php overlays whitelisted keys from this `settings` table; setup.php
 * writes to it. Standalone — no other dependencies.
 */

final class SettingsStore {
    private PDO $pdo;

    public function __construct(string $dbPath) {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                key         TEXT PRIMARY KEY,
                value       TEXT NOT NULL,
                updated_at  TEXT NOT NULL
            )'
        );
    }

    public function pdo(): PDO { return $this->pdo; }

    private function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    public function getSetting(string $key, ?string $default = null): ?string {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['value'] : $default;
    }

    public function setSetting(string $key, string $value): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings(key, value, updated_at) VALUES (:k, :v, :t)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute([':k' => $key, ':v' => $value, ':t' => $this->now()]);
    }

    public function allSettings(): array {
        $stmt = $this->pdo->query('SELECT key, value FROM settings');
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }
}
