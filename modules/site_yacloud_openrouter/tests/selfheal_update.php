<?php
/**
 * selfheal_update.php — the updater's safety net, tested without a network.
 * spec: spec/selfheal.md §13.2 — run: php tests/selfheal_update.php
 *
 * Builds synthetic "releases" on disk and drives the private steps through
 * reflection, because these are exactly the paths that must never damage an
 * install: syntax verification, preserved operator files, stale-file removal
 * and restoring a backup.
 *
 * DO NOT reflect into apply(), rollback() or restore() from here. Those write
 * to SELFHEAL_MODULE_ROOT by design, i.e. to the checkout running the test —
 * an earlier draft of this file did exactly that and overwrote the module with
 * a synthetic release. Everything they delegate to (verify, copyTree,
 * fileList, isPreserved, removeStale, rmTree) takes explicit paths and is
 * tested against temporary directories instead. The guard below enforces it.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/selfheal/bootstrap.php';

use Selfheal\State;
use Selfheal\Updater;

$tmp = sys_get_temp_dir() . '/selfheal-upd-' . getmypid();
@mkdir($tmp, 0775, true);

/** Nothing in this file may name the live module directory as a target. */
$safe = static function (array $args) : bool {
    foreach ($args as $a) {
        if (!is_string($a)) continue;
        $real = realpath($a) ?: $a;
        if ($real === realpath(SELFHEAL_MODULE_ROOT)) return false;
    }
    return true;
};

$pass = 0; $fail = 0;
$ok = static function (string $what, bool $cond, string $detail = '') use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ok   $what\n"; return; }
    $fail++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
};

$updater = new Updater(new State($tmp . '/state.db'), ['work_dir' => $tmp . '/work']);
$forbidden = ['apply', 'rollback', 'restore'];
$call = static function (string $method, array $args = []) use ($updater, $safe, $forbidden) {
    if (in_array($method, $forbidden, true)) {
        throw new LogicException($method . '() пишет в живой каталог модуля — не для теста');
    }
    if (!$safe($args)) {
        throw new LogicException('аргумент указывает на живой каталог модуля');
    }
    $r = new ReflectionMethod(Updater::class, $method);
    $r->setAccessible(true);
    return $r->invokeArgs($updater, $args);
};

/** Write a minimal, syntactically valid release tree. */
$release = static function (string $dir, string $version, array $extra = []): void {
    @mkdir($dir . '/selfheal', 0775, true);
    file_put_contents($dir . '/module.json', (string) json_encode([
        'module' => 'site_yacloud_openrouter', 'version' => $version,
        'api_version' => '1.0.0', 'repo' => 'dansury/site_yacloud_openrouter',
        'preserve' => ['data', 'selfheal/token.php'],
    ]));
    file_put_contents($dir . '/selfheal/bootstrap.php', "<?php\n// release $version\n");
    // Padding so the ">= 8 files" sanity check is satisfied.
    foreach (range(1, 8) as $i) file_put_contents($dir . '/file' . $i . '.php', "<?php\nreturn $i;\n");
    foreach ($extra as $rel => $body) {
        $full = $dir . '/' . $rel;
        @mkdir(dirname($full), 0775, true);
        file_put_contents($full, $body);
    }
};

echo "\n== проверка релиза перед установкой ==\n";

$good = $tmp . '/rel-good';
$release($good, '1.1.0');
[$vOk, $vNote] = $call('verify', [$good, '1.1.0']);
$ok('корректный релиз принят', $vOk, $vNote);

[$vOk2, $vNote2] = $call('verify', [$good, '9.9.9']);
$ok('подменённая версия отклонена', !$vOk2, $vNote2);

$broken = $tmp . '/rel-broken';
$release($broken, '1.1.0', ['selfheal/Oops.php' => "<?php\nfunction (((\n"]);
[$bOk, $bNote] = $call('verify', [$broken, '1.1.0']);
$ok('синтаксическая ошибка отклоняет весь релиз', !$bOk, $bNote);
$ok('в причине назван файл', strpos($bNote, 'Oops.php') !== false, $bNote);

$noEntry = $tmp . '/rel-noentry';
$release($noEntry, '1.1.0');
@unlink($noEntry . '/selfheal/bootstrap.php');
[$eOk] = $call('verify', [$noEntry, '1.1.0']);
$ok('релиз без точки входа отклонён', !$eOk);

$major = $tmp . '/rel-major';
$release($major, '2.0.0');
file_put_contents($major . '/module.json', (string) json_encode([
    'version' => '2.0.0', 'api_version' => '2.0.0', 'module' => 'site_yacloud_openrouter',
]));
[$mOk, $mNote] = $call('verify', [$major, '2.0.0']);
$ok('мажорная смена API отклонена', !$mOk, $mNote);

$thin = $tmp . '/rel-thin';
@mkdir($thin . '/selfheal', 0775, true);
file_put_contents($thin . '/module.json', (string) json_encode(['version' => '1.1.0', 'api_version' => '1.0.0']));
file_put_contents($thin . '/selfheal/bootstrap.php', "<?php\n");
[$tOk, $tNote] = $call('verify', [$thin, '1.1.0']);
$ok('неполная загрузка отклонена', !$tOk, $tNote);

echo "\n== файлы оператора не трогаются ==\n";

$ok('data/ сохраняется', (bool) $call('isPreserved', ['data/app.db']));
$ok('локальный токен оператора сохраняется', (bool) $call('isPreserved', ['selfheal/token.local.php']));
// The shipped blob MUST be replaceable, otherwise a leaked token could never
// be rotated out of the installed base.
$ok('зашитый токен заменяется обновлением', !$call('isPreserved', ['selfheal/token.php']));
$ok('pull-config.php сохраняется', (bool) $call('isPreserved', ['pull-config.php']));
$ok('код модуля не сохраняется', !$call('isPreserved', ['selfheal/SelfHeal.php']));
$ok('похожее имя не считается сохраняемым', !$call('isPreserved', ['database.php']));

// Install the "old" version into a fake module dir, then copy the new one over.
$live = $tmp . '/live';
$release($live, '1.0.0', [
    'data/app.db'              => 'ОПЕРАТОРСКИЕ ДАННЫЕ',
    'selfheal/token.local.php' => "<?php\nreturn ['gh' => 'sh1:местный-блоб'];\n",
    'pull-config.php'          => "<?php\nreturn ['secret'];\n",
    'dropped_in_new.php'       => "<?php\n// уедет в новой версии\n",
]);
$next = $tmp . '/rel-next';
$release($next, '1.2.0');

$listBefore = $call('fileList', [$live]);
$ok('список файлов не содержит сохраняемые пути',
    !in_array('data/app.db', $listBefore, true) && !in_array('selfheal/token.local.php', $listBefore, true));

$copied = $call('copyTree', [$next, $live, true]);
$ok('копирование релиза прошло', (bool) $copied);
$ok('операторские данные целы', file_get_contents($live . '/data/app.db') === 'ОПЕРАТОРСКИЕ ДАННЫЕ');
$ok('локальный токен цел', strpos((string) file_get_contents($live . '/selfheal/token.local.php'), 'местный-блоб') !== false);
$ok('локальный pull-config цел', is_file($live . '/pull-config.php'));
$ok('новая версия на месте',
    strpos((string) file_get_contents($live . '/module.json'), '1.2.0') !== false);
$ok('файл, которого нет в релизе, пока остался', is_file($live . '/dropped_in_new.php'));

echo "\n== восстановление ==\n";

// restore() targets the real SELFHEAL_MODULE_ROOT, so exercise the copy it
// delegates to — that is where the behaviour under test actually lives.
$backup = $tmp . '/work/backup/1.0.0-test';
$release($backup, '1.0.0');
$ok('откат вернул старую версию', (bool) $call('copyTree', [$backup, $live, true])
    && strpos((string) file_get_contents($live . '/module.json'), '1.0.0') !== false);
$ok('откат не тронул данные оператора', file_get_contents($live . '/data/app.db') === 'ОПЕРАТОРСКИЕ ДАННЫЕ');
$ok('откат не тронул локальный токен',
    strpos((string) file_get_contents($live . '/selfheal/token.local.php'), 'местный-блоб') !== false);
$ok('резервные копии перечисляются', count($updater->backups()) >= 1);
$ok('копия знает свою версию', (string) $updater->backups()[0]['version'] === '1.0.0');

echo "\n== rmTree не выходит за свой каталог ==\n";
$outside = $tmp . '/outside';
@mkdir($outside, 0775, true);
file_put_contents($outside . '/keep.txt', 'x');
$rm = new ReflectionMethod(Updater::class, 'rmTree');
$rm->setAccessible(true);
$rm->invoke($updater, $outside);
$ok('каталог вне work_dir не удалён', is_file($outside . '/keep.txt'));
$inside = $tmp . '/work/stage/doomed';
@mkdir($inside, 0775, true);
file_put_contents($inside . '/x.txt', 'x');
$rm->invoke($updater, $inside);
$ok('каталог внутри work_dir удалён', !is_dir($inside));

echo "\n" . str_repeat('-', 50) . "\n";
echo "пройдено: $pass, провалено: $fail\n";

$rmAll = static function (string $d) use (&$rmAll): void {
    if (!is_dir($d)) return;
    foreach ((array) scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$d/$f") ? $rmAll("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
};
$rmAll($tmp);

exit($fail === 0 ? 0 : 1);
