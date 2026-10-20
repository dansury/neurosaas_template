<?php
declare(strict_types=1);

namespace NeuroSaaS;

/** Preserve local state before the first use of the consolidated layout. */
final class LayoutMigration
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        $legacy = $root . '/modules/site_yacloud_openrouter';
        if (!is_dir($legacy)) return;
        $lock = fopen($root . '/pull-deploy.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException('Идёт обновление. Повторите вход после его завершения.');
        }
        try {
            foreach (['data', '.env', '.htaccess', 'selfheal/token.local.php'] as $path) {
                $source = $legacy . '/' . $path;
                $target = __DIR__ . '/' . $path;
                if (!file_exists($source) || file_exists($target)) continue;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                    throw new \RuntimeException('Не удалось перенести локальные настройки. Проверьте права папки admin.');
                }
                // Same filesystem: rename is atomic and preserves SQLite sidecar files.
                if (!rename($source, $target)) {
                    throw new \RuntimeException('Не удалось перенести локальные настройки. Исходные файлы сохранены.');
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
