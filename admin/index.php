<?php
declare(strict_types=1);
define('NEUROSAAS_ADMIN', true);
// Older installers preserve themselves. Upgrade their bundled code atomically before use.
// Config, credentials and deployment history are never rewritten here.
$root = dirname(__DIR__);
$runtime = __DIR__ . '/deployment/panel.php';
$installer = $root . '/pull.php';
$source = file_get_contents($runtime);
$standalone = str_replace("define('PULL_ROOT', dirname(__DIR__, 2))", "define('PULL_ROOT', __DIR__)", $source);
if (is_file($installer) && strpos((string)file_get_contents($installer), "define('PULL_ROOT'") === false) {
    $lock = fopen($root . '/pull-deploy.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        http_response_code(503); exit('Идёт обновление. Повторите вход после его завершения.');
    }
    try {
        $temporary = tempnam($root, '.installer-');
        if ($temporary === false || file_put_contents($temporary, $standalone) === false || !rename($temporary, $installer)) {
            if (isset($temporary) && is_string($temporary) && is_file($temporary)) unlink($temporary);
            http_response_code(503); exit('Не удалось обновить установщик. Проверьте права на pull.php и повторите вход.');
        }
        if (function_exists('opcache_invalidate')) opcache_invalidate($installer, true);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
require $runtime;
