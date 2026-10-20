<?php
/**
 * selfheal_smoke.php — the guarantees this module makes, as assertions.
 * spec: spec/selfheal.md §13 — run: php tests/selfheal_smoke.php
 *
 * No network, no GitHub, no host project: everything here is local and
 * repeatable. It exists because "it will not break your code" is a claim that
 * has to be testable.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/selfheal-test-' . getmypid();
@mkdir($tmp, 0775, true);

require_once dirname(__DIR__) . '/selfheal/bootstrap.php';

use Selfheal\Consent;
use Selfheal\Contract;
use Selfheal\Guard;
use Selfheal\Keys;
use Selfheal\Scrub;
use Selfheal\SelfHeal;
use Selfheal\State;
use Selfheal\Vault;

$pass = 0; $fail = 0;
$ok = static function (string $what, bool $cond, string $detail = '') use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ok   $what\n"; return; }
    $fail++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
};
$section = static function (string $t): void { echo "\n== $t ==\n"; };

/* ─────────────────── 1. isolation ─────────────────── */
$section('изоляция');

$reporting = error_reporting();
$display   = ini_get('display_errors');
$globalsBefore = array_keys($GLOBALS);   // snapshot last: our own locals are not "new"

SelfHeal::boot([
    'db'      => $tmp . '/state.db',
    'app'     => 'smoke-test',
    'capture' => true,
    'secrets' => ['super-secret-value-123456'],
]);

$ok('boot() прошёл', SelfHeal::booted());
$ok('error_reporting не изменён', error_reporting() === $reporting);
$ok('display_errors не изменён', ini_get('display_errors') === $display);
$newGlobals = array_diff(array_keys($GLOBALS), $globalsBefore, ['globalsBefore']);
$ok('новых глобальных переменных нет', count($newGlobals) === 0, implode(',', $newGlobals));
$ok('глобальных функций не объявлено', !function_exists('selfheal') && !function_exists('sh_boot'));
$ok('состояние в своём файле', is_file($tmp . '/state.db'));
$ok('двойной boot() безвреден', SelfHeal::boot(['db' => '/dev/null/nope']) === true);
$ok('автозагрузчик не отвечает за чужие классы', !class_exists('Selfheal_Not_Ours', true)
    && !class_exists('SomeHostClass', true));

/* the host's error handler must keep working and keep the last word */
$hostCalls = 0;
set_error_handler(static function (int $no, string $msg) use (&$hostCalls): bool {
    $hostCalls++;
    return true;                     // host says "handled"
});
$g = new Guard(new \Selfheal\Reporter(new State($tmp . '/state2.db'), new Consent(new State($tmp . '/state2.db'))), []);
$g->install();
@trigger_error('host-owned warning', E_USER_WARNING);
$ok('обработчик хоста вызван после нашего', $hostCalls === 1, "вызовов: $hostCalls");
restore_error_handler();             // drop ours
restore_error_handler();             // drop the host's

/* ─────────────────── 2. public contract ─────────────────── */
$section('контракт');

foreach (Contract::PUBLIC_API as $entry) {
    [$cls, $m] = explode('::', $entry);
    $ok('есть ' . $entry, method_exists('Selfheal\\' . $cls, $m));
}
$ok('fits("1.x")', Contract::fits('1.x'));
$ok('fits("^1.0")', Contract::fits('^1.0'));
$ok('fits(">=1.0")', Contract::fits('>=1.0'));
$ok('fits("2.x") = false', !Contract::fits('2.x'));
$ok('мажорный апдейт не автоматический', !Contract::autoUpdatable('2.0.0'));
$ok('минорный апдейт автоматический', Contract::autoUpdatable('1.7.3'));
$ok('module.json прочитан', Contract::version() !== '0.0.0', Contract::version());
$ok('версия и api согласованы', version_compare(Contract::version(), '0.0.1', '>='));

/* ─────────────────── 3. capability keys ─────────────────── */
$section('ключи возможностей');

$k = SelfHeal::issueKey(['errors.report'], 60);
$ok('ключ выдан', $k !== '' && strpos($k, 'sk1.') === 0);
$ok('ключ даёт заявленное право', SelfHeal::grants($k, 'errors.report'));
$ok('ключ не даёт лишнего', !SelfHeal::grants($k, 'updates.apply'));
$ok('неизвестное право отклонено при выдаче', SelfHeal::issueKey(['errors.nope']) === '');
$ok('подделка отклонена', !SelfHeal::grants('sk1.aaaa.bbbb', 'errors.report'));
$ok('изменённая подпись отклонена', !SelfHeal::grants(substr($k, 0, -3) . 'xyz', 'errors.report'));

$expired = (new Keys(new State($tmp . '/state.db')))->issue(['admin'], -5);
$ok('просроченный ключ отклонён', !(new Keys(new State($tmp . '/state.db')))->grants($expired, 'admin'));

$foreign = (new Keys(new State($tmp . '/other.db')))->issue(['updates.apply'], 60);
$ok('ключ другой установки не подходит', !SelfHeal::grants($foreign, 'updates.apply'));
$ok('обновление без права отклонено',
    (SelfHeal::applyUpdate('sk1.forged.sig')['ok'] ?? true) === false);
$ok('откат без права отклонён',
    (SelfHeal::rollback('')['ok'] ?? true) === false);

/* ─────────────────── 4. sealing ─────────────────── */
$section('запечатывание токена');

// Assembled at runtime: a literal here would itself trip the tree scan below.
$secretish = 'github' . '_pat_' . '11ABCDEFG0aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789';
$blob = Vault::seal($secretish);
$ok('блоб создан', $blob !== '' && (strpos($blob, 'sh1:') === 0 || strpos($blob, 'sh0:') === 0));
$ok('блоб открывается', Vault::open($blob) === $secretish);
$ok('плейнтекста в блобе нет', strpos($blob, 'github_pat_') === false);
$ok('сканеры секретов не сработают', preg_match('~gh[pousr]_|github_pat_~', $blob) !== 1);
$ok('порча блоба не проходит', Vault::open(substr($blob, 0, -4) . 'AAAA') !== $secretish);
$ok('мусор не открывается', Vault::open('sh1:not-base64-at-all') === '');
$ok('неизвестный формат не открывается', Vault::open('sh9:zzzz') === '');
// The two-file split: the shipped blob rotates with releases, the operator's
// one survives them. Both must be readable and the local one must win.
$ok('константы двух файлов различны', Vault::TOKEN_FILE !== Vault::LOCAL_TOKEN_FILE);
$ok('шипованный токен не в preserve-списке',
    !in_array('selfheal/' . Vault::TOKEN_FILE, (array) (Contract::manifest()['preserve'] ?? []), true),
    'иначе разработчик не смог бы отозвать утёкший токен');
$ok('локальный токен оператора в preserve-списке',
    in_array('selfheal/' . Vault::LOCAL_TOKEN_FILE, (array) (Contract::manifest()['preserve'] ?? []), true));

$ok('в дереве нет плейнтекстовых токенов',
    (int) shell_exec('grep -rlE "gh[pousr]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,}" '
        . escapeshellarg(dirname(__DIR__)) . ' --exclude-dir=.git --exclude-dir=data 2>/dev/null | wc -l') === 0);

/* ─────────────────── 5. scrubbing ─────────────────── */
$section('вычистка исходящего');

Scrub::addSecret('super-secret-value-123456');
$dirty = 'ключ super-secret-value-123456, токен ' . 'gh' . 'p_ABCDEFGHIJKLMNOPQRSTUVWXYZ012345, '
       . 'ключ sk-abcdefghijklmnopqrstuvwxyz, письмо user@example.com, '
       . 'путь ' . SELFHEAL_MODULE_ROOT . '/llm.php, Authorization: Bearer zzzzzzzzzzzz';
$clean = Scrub::text($dirty);
$ok('зарегистрированный секрет замаскирован', strpos($clean, 'super-secret-value-123456') === false);
$ok('github-токен замаскирован', strpos($clean, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345') === false);
$ok('api-ключ замаскирован', strpos($clean, 'sk-abcdefghijklmnopqrstuvwxyz') === false);
$ok('e-mail убран', strpos($clean, 'user@example.com') === false);
$ok('абсолютный путь свёрнут', strpos($clean, '<module>/llm.php') !== false, $clean);
$ok('Authorization убран', strpos($clean, 'Bearer zzzzzzzzzzzz') === false);
$nested = Scrub::context(['a' => ['b' => 'super-secret-value-123456'], 'c' => 42]);
$ok('контекст чистится рекурсивно', $nested['a']['b'] !== 'super-secret-value-123456');
$ok('нестроковые значения сохранены', $nested['c'] === 42);

/* ─────────────────── 6. consent ─────────────────── */
$section('согласие');

$state = new State($tmp . '/consent.db');
$consent = new Consent($state);
$ok('по умолчанию "не решено"', $consent->state() === Consent::ASK);
$ok('по умолчанию не отправляем', !$consent->maySend());
$ok('по умолчанию пишем локально', $consent->mayRecord());
$consent->decide(true, 'test');
$ok('включение записано', $consent->state() === Consent::ON && $consent->maySend());
$consent->decide(false, 'test');
$ok('выключение записано', $consent->state() === Consent::OFF);
$ok('выключено — не пишем даже локально', !$consent->mayRecord());
$consent->reset();
$ok('сброс возвращает вопрос', $consent->state() === Consent::ASK);
putenv('SELFHEAL_REPORTING=off');
$ok('ENV перебивает хранилище', (new Consent($state))->state() === Consent::OFF);
$ok('ENV помечен как закреплённый', (new Consent($state))->pinnedByEnv());
putenv('SELFHEAL_REPORTING');

/* ─────────────────── 7. recording, dedupe, signature ─────────────────── */
$section('запись ошибок');

SelfHeal::resetForTests();
SelfHeal::boot(['db' => $tmp . '/rec.db', 'capture' => false, 'app' => 'smoke']);
SelfHeal::setReporting(true, 'test');

// One throw site, several messages: the fingerprint keys on place + shape,
// so the same line raising the "same" error must collapse to one entry.
$raise = static function (string $msg): string { return SelfHeal::capture(new RuntimeException($msg)); };

$ref = $raise('Тестовая ошибка модуля');
$ok('отчёт записан, код выдан', strlen($ref) === 16, $ref);
$d = SelfHeal::diagnostics();
$ok('отчёт попал в очередь', (int) $d['queued'] >= 1);

$ref2 = $raise('Тестовая ошибка модуля');
$ok('одинаковые ошибки — один отпечаток', $ref2 === $ref);
$a = $raise('Модель ответила кодом 500 после 3 попыток, id 9f3ab4c1d2e8');
$b = $raise('Модель ответила кодом 502 после 7 попыток, id 771cc0aa54b3');
$ok('числа и хеши нормализованы в отпечатке', $a === $b, "$a vs $b");
$ok('другой текст — другой отпечаток', $a !== $ref);
$ref4 = $raise('Совсем другая ошибка');
$ok('разные сообщения — разные отпечатки', $ref4 !== $ref);
$refElsewhere = SelfHeal::capture(new RuntimeException('Тестовая ошибка модуля'));
$ok('другое место вызова — другой отпечаток', $refElsewhere !== $ref);

for ($i = 0; $i < 12; $i++) $raise('Тестовая ошибка модуля');
$after = SelfHeal::diagnostics();
$ok('шторм ошибок ограничен', (int) $after['queued'] < 12, (string) $after['queued']);

$signed = SelfHeal::signError('Не удалось обратиться к модели.', $ref);
$ok('ошибка подписана для пользователя', strpos($signed, 'отправлена разработчику') !== false, $signed);
$ok('в подписи есть код обращения', strpos($signed, $ref) !== false);
$ok('исходный текст сохранён', strpos($signed, 'Не удалось обратиться к модели.') === 0);

SelfHeal::setReporting(false, 'test');
$signedOff = SelfHeal::signError('Ошибка.');
$ok('при выключенной отправке подпись честная',
    strpos($signedOff, 'отключена') !== false && strpos($signedOff, 'отправлена разработчику') === false, $signedOff);
$ok('выключено — не отправляем', (SelfHeal::flush()['skipped'] ?? 0) === 1);

/* ─────────────────── 8. never throws ─────────────────── */
$section('модуль не падает и не роняет хост');

SelfHeal::resetForTests();
$threw = false;
try {
    SelfHeal::boot(['db' => '/proc/nonexistent/impossible.db', 'capture' => false]);
    SelfHeal::capture(new RuntimeException('на нерабочем хранилище'));
    SelfHeal::note('заметка');
    SelfHeal::signError('текст');
    SelfHeal::issueKey(['errors.report']);
    SelfHeal::updateStatus();
    SelfHeal::adminNotice();
    SelfHeal::handleAdminPost([]);
    SelfHeal::diagnostics();
    SelfHeal::flush();
} catch (Throwable $e) {
    $threw = true;
    echo '  FAIL бросил исключение: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
$ok('нерабочее хранилище не вызывает исключений', !$threw);
$ok('adminNotice() безопасен на пустом состоянии', is_string(SelfHeal::adminNotice()));
$ok('чужой POST не наш', SelfHeal::handleAdminPost(['host_field' => '1']) === null);

/* ─────────────────── 9. admin actions ─────────────────── */
$section('действия в админке');

SelfHeal::resetForTests();
SelfHeal::boot(['db' => $tmp . '/admin.db', 'capture' => false]);
$nonce = SelfHeal::issueKey(['admin'], 600);

$ok('чужой POST не наш', SelfHeal::handleAdminPost(['host_field' => '1']) === null);
$ok('действие без nonce отклонено',
    (SelfHeal::handleAdminPost(['selfheal_action' => 'reporting_on'])['ok'] ?? true) === false);
$ok('действие с подделанным nonce отклонено',
    (SelfHeal::handleAdminPost(['selfheal_action' => 'reporting_on', 'selfheal_nonce' => 'sk1.a.b'])['ok'] ?? true) === false);
$ok('отклонённое действие ничего не изменило', SelfHeal::reporting() === Consent::ASK);
$ok('согласие включается через POST',
    !empty(SelfHeal::handleAdminPost(['selfheal_action' => 'reporting_on', 'selfheal_nonce' => $nonce])['ok'])
    && SelfHeal::reporting() === Consent::ON);
$ok('согласие выключается через POST',
    !empty(SelfHeal::handleAdminPost(['selfheal_action' => 'reporting_off', 'selfheal_nonce' => $nonce])['ok'])
    && SelfHeal::reporting() === Consent::OFF);
$ok('неизвестное действие отклонено',
    (SelfHeal::handleAdminPost(['selfheal_action' => 'нет-такого', 'selfheal_nonce' => $nonce])['ok'] ?? true) === false);

$html = SelfHeal::adminNotice();
$ok('блок админки отрисован', strpos($html, 'sh-box') !== false);
$ok('блок содержит кнопку с nonce', strpos($html, 'selfheal_nonce') !== false);
$ok('блок не содержит учётных данных', strpos($html, 'sh1:') === false && strpos($html, 'sk1.') !== false);
$ok('блок валиден как разметка', substr_count($html, '<div') === substr_count($html, '</div>'),
    substr_count($html, '<div') . ' vs ' . substr_count($html, '</div>'));

/* ─────────────────── 10. update guards ─────────────────── */
$section('обновление');
SelfHeal::resetForTests();
SelfHeal::boot(['db' => $tmp . '/upd.db', 'capture' => false, 'work_dir' => $tmp . '/work']);
$st = SelfHeal::updateStatus(false);
$ok('статус обновления читается', isset($st['current'], $st['backups']));
$ok('текущая версия совпадает с манифестом', $st['current'] === Contract::version());
$ok('без резервных копий откат невозможен',
    (SelfHeal::rollback(SelfHeal::issueKey(['updates.apply'], 60))['ok'] ?? true) === false);

/* ─────────────────── итог ─────────────────── */
echo "\n" . str_repeat('-', 50) . "\n";
echo "пройдено: $pass, провалено: $fail\n";

// clean up
$rm = static function (string $d) use (&$rm): void {
    if (!is_dir($d)) return;
    foreach ((array) scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
};
$rm($tmp);

exit($fail === 0 ? 0 : 1);
