<?php
declare(strict_types=1);
define('PULL_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/pull.php';
require dirname(__DIR__) . '/admin/account/gate.php';
use NeuroSaaS\AdminAccount;
$checks = 0;
function verifyLayout(bool $ok, string $label): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($label);
}
$root = dirname(__DIR__);
$runtime = file_get_contents($root . '/admin/deployment/panel.php');
verifyLayout(str_replace("define('PULL_ROOT', dirname(__DIR__, 2))", "define('PULL_ROOT', __DIR__)", $runtime) === file_get_contents($root . '/pull.php'), 'bundled installer/runtime match');
foreach (['lib','docs','modules','tools','example.php'] as $old) verifyLayout(!file_exists($root . '/' . $old), 'consolidated ' . $old);
$state = []; $code = '';
$send = static function ($email, $value) use (&$code): void { $code = $value; };
AdminAccount::issue($state, 'enroll', 'owner@example.test', 'HASH', 'session-a', $send, 1000);
verifyLayout((bool)preg_match('/^[0-9]{6}$/D', $code), 'six-digit code');
verifyLayout(strpos(json_encode($state), $code) === false, 'code stored only as hash');
verifyLayout(AdminAccount::consume($state, 'reset', $code, 'session-a', 1001) === null, 'purpose isolation');
verifyLayout(AdminAccount::consume($state, 'enroll', $code, 'session-b', 1001) === null, 'session binding');
verifyLayout(AdminAccount::consume($state, 'enroll', '000000', 'session-a', 1001) === null, 'wrong code rejected');
verifyLayout($state['challenge']['attempts'] === 4, 'attempt counted');
try { AdminAccount::issue($state, 'enroll', 'owner@example.test', 'HASH', 'session-a', $send, 1002); throw new LogicException('cooldown bypassed'); }
catch (RuntimeException $e) { verifyLayout(true, 'resend cooldown'); }
$accepted = AdminAccount::consume($state, 'enroll', $code, 'session-a', 1002);
verifyLayout($accepted['email'] === 'owner@example.test' && $accepted['password_hash'] === 'HASH', 'confirmation payload');
verifyLayout(AdminAccount::consume($state, 'enroll', $code, 'session-a', 1003) === null, 'one-time code');
AdminAccount::issue($state, 'reset', 'owner@example.test', 'NEW', 'session-a', $send, 1100);
verifyLayout(AdminAccount::consume($state, 'reset', $code, 'session-a', 1700) === null, 'expires after ten minutes');
AdminAccount::issue($state, 'reset', 'owner@example.test', 'NEW', 'session-a', $send, 1800);
for ($i = 0; $i < 5; $i++) verifyLayout(AdminAccount::consume($state, 'reset', '000000', 'session-a', 1801) === null, 'bounded wrong attempt');
verifyLayout(AdminAccount::consume($state, 'reset', $code, 'session-a', 1802) === null, 'exhausted challenge cannot be used');
$state['sent'] = array_fill(0, 10, 1900);
try { AdminAccount::issue($state, 'reset', 'owner@example.test', 'NEW', 'session-a', $send, 2000); throw new LogicException('hour limit bypassed'); }
catch (RuntimeException $e) { verifyLayout(true, 'shared hourly delivery limit'); }
$_SERVER['SCRIPT_NAME'] = '/nested/admin/index.php';
define('NEUROSAAS_ADMIN', true);
verifyLayout(auth_cookie_path() === '/nested/', 'shared admin/cron cookie path');
$_SERVER['SCRIPT_NAME'] = '/admin/index.php';
verifyLayout(auth_cookie_path() === '/', 'root admin cookie path on Windows and Unix');
echo "$checks layout and account checks passed\n";
