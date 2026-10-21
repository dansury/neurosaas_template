<?php
namespace NeuroSaaS;

require_once __DIR__ . '/WebPush.php';

/** Installable application and owner-device notifications, without Composer. */
final class WebApp
{
    private static $db;
    private static $path;
    private static $subject = 'mailto:admin@example.invalid';

    /** Optional storage override is intended for isolated tests and embedded services. */
    public static function init(?string $path = null, string $subject = ''): void
    {
        if ($path !== null) { self::$path = $path; self::$db = null; }
        if ($subject !== '') self::$subject = $subject;
    }

    private static function db(): \PDO
    {
        if (self::$db) return self::$db;
        $path = self::$path ?: dirname(__DIR__) . '/account/data/webapp.sqlite';
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Не удалось создать хранилище приложения.');
        if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        $db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        @chmod($path, 0600);
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('CREATE TABLE IF NOT EXISTS webapp_settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS webapp_subscriptions (endpoint TEXT PRIMARY KEY, p256dh TEXT NOT NULL, auth TEXT NOT NULL)');
        return self::$db = $db;
    }

    private static function get(string $name, string $default = ''): string
    {
        $q = self::db()->prepare('SELECT value FROM webapp_settings WHERE name=?'); $q->execute([$name]);
        $value = $q->fetchColumn(); return $value === false ? $default : (string)$value;
    }

    public static function setName(string $name): void
    {
        $name = self::clip(trim($name), 60);
        if ($name === '') throw new \InvalidArgumentException('Введите название приложения.');
        self::db()->prepare('INSERT OR REPLACE INTO webapp_settings(name,value) VALUES(?,?)')->execute(['name', $name]);
    }

    public static function vapid(): array
    {
        $db = self::db(); $db->exec('BEGIN IMMEDIATE');
        try {
            $keys = json_decode(self::get('vapid'), true);
            if (!is_array($keys)) {
                [$public, $private] = WebAppPush::generate();
                $keys = ['public'=>$public, 'private'=>$private, 'subject'=>self::$subject];
                $db->prepare('INSERT OR REPLACE INTO webapp_settings(name,value) VALUES(?,?)')->execute(['vapid', json_encode($keys)]);
            }
            $db->exec('COMMIT'); return $keys;
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }

    /** Only browser push services may receive outbound subscription requests. */
    public static function endpointAllowed(string $endpoint): bool
    {
        if (strlen($endpoint) > 4096 || preg_match('/[\x00-\x20]/', $endpoint) || strpos($endpoint, chr(92)) !== false) return false;
        $p = parse_url($endpoint);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)) return false;
        $host = strtolower((string)($p['host'] ?? ''));
        return (bool)preg_match('/^(?:fcm\.googleapis\.com|(?:updates\.)?push\.services\.mozilla\.com|web\.push\.apple\.com|[a-z0-9-]+\.notify\.windows\.com)$/D', $host);
    }

    public static function subscribe(array $subscription): void
    {
        $endpoint = (string)($subscription['endpoint'] ?? '');
        $key = (string)($subscription['keys']['p256dh'] ?? ''); $auth = (string)($subscription['keys']['auth'] ?? '');
        $decode = static function ($s) { return preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $s) ? base64_decode(strtr($s, '-_', '+/'), true) : false; };
        $public = $decode($key); $secret = $decode($auth);
        if (!self::endpointAllowed($endpoint) || strlen((string)$public) !== 65 || $public[0] !== "\x04" || strlen((string)$secret) !== 16) throw new \InvalidArgumentException('Некорректная подписка браузера.');
        $q = self::db()->prepare('INSERT OR REPLACE INTO webapp_subscriptions(endpoint,p256dh,auth) VALUES(?,?,?)'); $q->execute([$endpoint,$key,$auth]);
    }

    public static function unsubscribe(string $endpoint): void
    {
        self::db()->prepare('DELETE FROM webapp_subscriptions WHERE endpoint=?')->execute([$endpoint]);
    }

    /** Server-only event API. Push-service acceptance is not device-delivery proof. */
    public static function notify(string $title, string $body, string $url = './', ?string $onlyEndpoint = null): array
    {
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url) || preg_match('/[\x00-\x20]/', $url) || strpos($url, chr(92)) !== false) throw new \InvalidArgumentException('Ссылка уведомления должна вести внутри сервиса.');
        $payload = json_encode(['title'=>self::clip($title,80),'body'=>self::clip($body,500),'url'=>$url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen((string)$payload) > 3500) throw new \InvalidArgumentException('Уведомление слишком длинное.');
        $q = self::db()->prepare('SELECT * FROM webapp_subscriptions' . ($onlyEndpoint === null ? '' : ' WHERE endpoint=?'));
        $q->execute($onlyEndpoint === null ? [] : [$onlyEndpoint]);
        $result = ['accepted'=>0,'expired'=>0,'failed'=>0];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $subscription) {
            try { $code = WebAppPush::send($subscription, (string)$payload); } catch (\Throwable $e) { $code = 0; }
            if ($code === 404 || $code === 410) { self::unsubscribe($subscription['endpoint']); ++$result['expired']; }
            elseif ($code >= 200 && $code < 300) ++$result['accepted'];
            else ++$result['failed'];
        }
        return $result;
    }

    public static function basePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        if (basename($dir) === 'admin') $dir = dirname($dir);
        return rtrim($dir === '.' ? '' : $dir, '/') . '/';
    }

    public static function manifest(string $base): array
    {
        try { $name = self::get('name', 'Мой сервис'); } catch (\Throwable $e) { $name = 'Мой сервис'; }
        return ['id'=>$base,'name'=>$name,'short_name'=>self::clip($name,20),'lang'=>'ru','start_url'=>$base,'scope'=>$base,'display'=>'standalone','background_color'=>'#ffffff','theme_color'=>'#111111','icons'=>[
            ['src'=>$base.'admin/webapp/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],
            ['src'=>$base.'admin/webapp/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],
            ['src'=>$base.'admin/webapp/icon.svg','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any'],
        ]];
    }

    public static function head(?string $base = null): void
    {
        $base = htmlspecialchars($base ?? self::basePath(), ENT_QUOTES, 'UTF-8');
        echo '<link rel="manifest" href="'.$base.'webapp.php"><meta name="theme-color" content="#111111"><script defer src="'.$base.'admin/webapp/client.js" data-webapp-base="'.$base.'"></script>';
    }

    public static function installButton(): void
    {
        echo '<button type="button" data-webapp-install>Установить приложение</button><p data-webapp-status role="status"></p>';
    }

    private static function clip(string $text, int $length): string
    {
        return implode('', array_slice(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, $length));
    }
}
