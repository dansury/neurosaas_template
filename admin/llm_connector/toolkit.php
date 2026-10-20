<?php
declare(strict_types=1);

namespace NeuroSaaS;

/** Canonical toolkit integration; the instance-based global LLM remains compatible. */
final class Toolkit
{
    private static ?array $config = null;
    public static function root(): string { return dirname(__DIR__, 2); }
    public static function module(): string { return self::root() . '/admin/llm_connector'; }

    public static function boot(): array
    {
        if (self::$config !== null) return self::$config;
        require_once __DIR__ . '/migrate.php';
        LayoutMigration::run();
        require_once __DIR__ . '/adapter.php';
        $module = self::module();
        // Protected files are skipped by the installer even on a fresh target.
        if (!is_file($module . '/.htaccess')) file_put_contents($module . '/.htaccess', "Require all denied\n");
        foreach (['settings_store', 'llm', 'parser', 'mailer', 'diag_log', 'auto_pull'] as $file) {
            require_once $module . '/' . $file . '.php';
        }
        require_once $module . '/selfheal/bootstrap.php';
        $cfg = require $module . '/config.php';
        if (!is_dir($module . '/data')) mkdir($module . '/data', 0700, true);
        @chmod($module . '/data', 0700);
        $store = new \SettingsStore((string)$cfg['DB_PATH']);
        @chmod((string)$cfg['DB_PATH'], 0600);
        if ($store->getSetting('NEUROSAAS_MIGRATED') === null) {
            $legacy = is_file(self::root() . '/ai-config.php') ? require self::root() . '/ai-config.php' : [];
            $legacy = is_array($legacy) ? $legacy : [];
            $map = ['yandex' => ['key' => 'YANDEX_API_KEY', 'folder' => 'YANDEX_FOLDER_ID'],
                    'openrouter' => ['key' => 'OPENROUTER_API_KEY']];
            foreach ($map as $provider => $keys) {
                if (empty($legacy[$provider]['enabled'])) continue;
                foreach ($keys as $old => $key) {
                    $value = (string)($legacy[$provider][$old] ?? '');
                    if ($value !== '' && $store->getSetting($key) === null) $store->setSetting($key, $value);
                }
            }
            $order = array_values(array_filter($legacy['order'] ?? [], static function ($p) use ($legacy): bool {
                return in_array($p, ['yandex', 'openrouter'], true) && !empty($legacy[$p]['enabled']);
            }));
            if ($order !== []) {
                if ($store->getSetting('LLM_PROVIDER_PRIORITY') === null) $store->setSetting('LLM_PROVIDER_PRIORITY', implode(',', $order));
                if ($store->getSetting('LLM_PROVIDER') === null) $store->setSetting('LLM_PROVIDER', $order[0]);
                foreach (['LLM_DEFAULT_MODEL' => $order[0], 'YANDEX_FALLBACK_MODEL' => 'yandex', 'LLM_FALLBACK_MODEL' => 'openrouter'] as $key => $provider) {
                    $model = (string)($legacy[$provider]['model'] ?? '');
                    if ($model !== '' && $store->getSetting($key) === null) {
                        if ($provider === 'yandex') {
                            $model = preg_replace('~^gpt://[^/]+/|/(?:latest|rc|deprecated)$~', '', $model);
                            $model = 'yandex:' . $model;
                        }
                        $store->setSetting($key, $model);
                    }
                }
            }
            $store->setSetting('NEUROSAAS_MIGRATED', '1');
        }
        // The canonical page updater must call the explicit cron endpoint of this deployer.
        if ($store->getSetting('AUTOPULL_URL') === null && self::updateUrl() !== '') {
            $store->setSetting('AUTOPULL_URL', self::updateUrl());
        }
        $cfg = require $module . '/config.php';
        \DiagLog::init((string)$cfg['DB_PATH'], \DiagLog::codeStamp([
            $module . '/llm.php', $module . '/parser.php', $module . '/mailer.php', __FILE__
        ]));
        foreach (['OPENROUTER_API_KEY', 'YANDEX_API_KEY', 'SMTP_PASS', 'ADMIN_PASSWORD'] as $key) {
            \DiagLog::addSecret((string)($cfg[$key] ?? ''));
        }
        \ToolkitLLM::init($cfg, \DiagLog::store());
        \Selfheal\SelfHeal::boot(['app' => 'NeuroSaaS', 'secrets' => [
            (string)($cfg['OPENROUTER_API_KEY'] ?? ''), (string)($cfg['YANDEX_API_KEY'] ?? ''),
            (string)($cfg['SMTP_PASS'] ?? '')
        ]]);
        self::$config = $cfg;
        return $cfg;
    }

    private static function updateUrl(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('~^[a-zA-Z0-9.-]+(?::[0-9]+)?$~', $host)) return '';
        $document = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $root = realpath(self::root());
        if ($document === false || $root === false) return '';
        $document = str_replace('\\', '/', $document);
        $root = str_replace('\\', '/', $root);
        if ($root !== $document && strpos($root, $document . '/') !== 0) return '';
        $base = substr($root, strlen($document));
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https://' : 'http://') . $host . $base . '/pull.php?check=1';
    }

    /** Call before output on ordinary application pages; disabled until the operator opts in. */
    public static function page(): void
    {
        $cfg = self::boot();
        $opts = \AutoPull::options($cfg, ['state_dir' => dirname((string)$cfg['DB_PATH'])]);
        $opts['root'] = self::root();
        \AutoPull::run($opts);
    }

    public static function chatText(string $system, string $prompt, ?int $sessionId = null, float $temperature = 0.7): string
    {
        self::boot();
        return \ToolkitLLM::chatText($system, $prompt, $sessionId, $temperature);
    }

    public static function chatJson(string $system, string $prompt, ?int $sessionId = null, float $temperature = 0.1): array
    {
        self::boot();
        return \ToolkitLLM::chatJson($system, $prompt, $sessionId, $temperature);
    }

    public static function extract(string $path, string $type): array
    {
        self::boot();
        return \Parser::extract($path, $type);
    }

    /** Auth and CSRF are checked by pull.php before entering the canonical settings page. */
    public static function settings(string $csrf): void
    {
        self::boot();
        if (!defined('SITE_TOOLKIT_HOST_AUTHENTICATED')) define('SITE_TOOLKIT_HOST_AUTHENTICATED', true);
        ob_start(static function (string $html) use ($csrf): string {
            $html = str_replace('setup.php', '?toolkit=1', $html);
            $field = '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';
            $html = preg_replace('~(<form\\b[^>]*>)~i', '$1' . $field, $html);
            return str_replace('</body>', '<p><a href="?">Вернуться в панель NeuroSaaS</a></p></body>', $html);
        });
        require self::module() . '/setup.php';
        ob_end_flush();
    }
}

