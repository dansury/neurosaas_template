<?php
/**
 * selfheal_admin.php — standalone admin page for the self-maintaining layer.
 * spec: spec/selfheal.md §11.2
 *
 * For hosts that have no admin panel of their own, or do not want to embed
 * SelfHeal::adminNotice() into theirs. Same actions, same nonce, same block.
 *
 * Gate: ADMIN_PASSWORD — from config.php when this module is used as a whole,
 * otherwise from the SELFHEAL_ADMIN_PASSWORD / ADMIN_PASSWORD env var. With no
 * password set the page refuses to render: an unprotected update button on a
 * public URL would be a hole, not a feature.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store');

require_once __DIR__ . '/selfheal/bootstrap.php';

use Selfheal\Contract;
use Selfheal\SelfHeal;

/* ── password: config.php when present, ENV otherwise ── */
$password = (string) (getenv('SELFHEAL_ADMIN_PASSWORD') ?: (getenv('ADMIN_PASSWORD') ?: ''));
if ($password === '' && is_file(__DIR__ . '/config.php')) {
    try {
        $cfg = require __DIR__ . '/config.php';
        if (is_array($cfg)) $password = (string) ($cfg['ADMIN_PASSWORD'] ?? '');
    } catch (Throwable $e) { /* standalone install without config */ }
}

$h = static function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$page = static function (string $body) use ($h): void {
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $h(Contract::MODULE) . ' — обслуживание</title><style>'
       . 'body{max-width:860px;margin:0 auto;padding:24px 16px;font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#111;background:#fff}'
       . '@media (prefers-color-scheme:dark){body{color:#e8e8ea;background:#141417}code{background:#1e1e22}}'
       . 'h1{font-size:20px;margin:0 0 16px}code{background:#f3f4f6;border-radius:5px;padding:1px 5px;font-size:13px}'
       . 'input[type=password]{padding:8px;border-radius:6px;border:1px solid #bbb;width:240px}'
       . 'button{padding:8px 14px;border-radius:7px;cursor:pointer}'
       . '</style></head><body>' . $body . '</body></html>';
};

if ($password === '') {
    http_response_code(503);
    $page('<h1>Обслуживание модуля недоступно</h1><p>Не задан пароль администратора. '
        . 'Установите <code>SELFHEAL_ADMIN_PASSWORD</code> (или <code>ADMIN_PASSWORD</code>) '
        . 'и откройте страницу снова.</p>');
    exit;
}

session_start();
if (($_GET['logout'] ?? '') !== '') {
    $_SESSION = [];
    session_destroy();
    header('Location: selfheal_admin.php');
    exit;
}
$loginError = '';
if (($_POST['selfheal_login'] ?? '') !== '') {
    if (hash_equals($password, (string) $_POST['selfheal_login'])) {
        $_SESSION['selfheal_admin'] = true;
        header('Location: selfheal_admin.php');
        exit;
    }
    $loginError = 'Неверный пароль';
    usleep(300000);                            // token brake on guessing
}
if (empty($_SESSION['selfheal_admin'])) {
    $page('<h1>' . $h(Contract::MODULE) . ' — обслуживание</h1>'
        . ($loginError !== '' ? '<p style="color:#b54708">' . $h($loginError) . '</p>' : '')
        . '<form method="post"><input type="password" name="selfheal_login" placeholder="пароль администратора" autofocus> '
        . '<button type="submit">Войти</button></form>');
    exit;
}

SelfHeal::boot(['app' => 'selfheal_admin', 'capture' => false]);
$result = SelfHeal::handleAdminPost($_POST);

$page('<h1>' . $h(Contract::MODULE) . ' — обслуживание '
    . '<a href="?logout=1" style="font-size:13px;float:right">выйти</a></h1>'
    . SelfHeal::adminNotice(['result' => (array) $result])
    . '<p style="font-size:13px;opacity:.7">Страница относится только к модулю. '
    . 'Настройки провайдеров и почты — <code>setup.php</code>.</p>');
