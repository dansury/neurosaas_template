<?php
/**
 * selfheal_install.php — first-run check + the one question that must be asked.
 * spec: spec/selfheal.md §12
 *
 * Runs as CLI or as a page. It does three things and nothing else:
 *   1. reports whether the environment can carry the module at all;
 *   2. ASKS whether error reports may go to the developer — and refuses to
 *      guess: with no answer the module keeps reports strictly local;
 *   3. prints the exact snippet to paste into the host project.
 *
 * It never writes to the host project, never touches the host database and
 * never enables anything the operator did not agree to.
 *
 *   php selfheal_install.php                 # interactive
 *   php selfheal_install.php --yes|--no      # answer without a prompt
 *   php selfheal_install.php --status        # just the report
 */

declare(strict_types=1);

require_once __DIR__ . '/selfheal/bootstrap.php';

use Selfheal\Consent;
use Selfheal\Contract;
use Selfheal\SelfHeal;
use Selfheal\Vault;

SelfHeal::boot(['capture' => false, 'app' => 'installer']);

/* ─────────────────────── environment report ─────────────────────── */

$checks = [];
$checks[] = ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, (string) (Contract::manifest()['requires']['php'] ?? '7.4.0'), '>='),
    'нужен PHP ' . (string) (Contract::manifest()['requires']['php'] ?? '7.4.0') . ' или новее'];
foreach ((array) (Contract::manifest()['requires']['ext'] ?? []) as $ext) {
    $checks[] = ['расширение ' . $ext, extension_loaded((string) $ext), 'обязательное расширение отсутствует'];
}
foreach (['zip' => 'обновления скачиваются архивом — без zip доступен только ручной апдейт',
          'openssl' => 'без openssl используется резервный алгоритм запечатывания'] as $ext => $why) {
    $checks[] = ['расширение ' . $ext . ' (необязательное)', extension_loaded($ext), $why];
}
$diag = SelfHeal::diagnostics();
$checks[] = ['своя база состояния', !empty($diag['state_ok']), 'каталог data/ должен быть доступен на запись'];
$checks[] = ['каталог модуля доступен на запись', is_writable(SELFHEAL_MODULE_ROOT), 'без записи обновление и откат недоступны'];
$checks[] = ['учётные данные для отчётов', Vault::source() !== 'none' || Vault::relay() !== '',
    'ни зашитого токена, ни SELFHEAL_GH_TOKEN, ни SELFHEAL_RELAY_URL — отчёты будут копиться локально'];

$snippet = "require_once __DIR__ . '/" . basename(SELFHEAL_MODULE_ROOT) . "/selfheal/bootstrap.php';\n"
         . "Selfheal\\SelfHeal::boot(['app' => 'имя-вашего-проекта']);";

/* ─────────────────────── CLI ─────────────────────── */

if (PHP_SAPI === 'cli') {
    $args = array_slice($argv, 1);
    $has  = static function (string $f) use ($args): bool { return in_array('--' . $f, $args, true); };

    echo "\n=== " . Contract::MODULE . ' ' . Contract::version() . ' (API ' . Contract::API_VERSION . ") ===\n\n";
    foreach ($checks as [$label, $ok, $why]) {
        echo ($ok ? '  [ok]   ' : '  [!]    ') . $label . ($ok ? '' : ' — ' . $why) . "\n";
    }
    echo "\nОтчёты об ошибках: " . SelfHeal::reporting() . "\n";

    if ($has('status')) { echo "\n"; exit(0); }

    if ($has('yes') || $has('no')) {
        SelfHeal::setReporting($has('yes'), 'installer');
        echo 'Записано: отправка отчётов ' . ($has('yes') ? 'ВКЛЮЧЕНА' : 'ВЫКЛЮЧЕНА') . ".\n\n";
    } elseif (SelfHeal::reporting() === Consent::ASK) {
        echo "\n" . wordwrap(Consent::question(), 78) . "\n\n";
        echo "Отправлять отчёты разработчику? [y/n] ";
        $answer = strtolower(trim((string) fgets(STDIN)));
        if ($answer === 'y' || $answer === 'yes' || $answer === 'д' || $answer === 'да') {
            SelfHeal::setReporting(true, 'installer');
            echo "Включено. Отключить в любой момент: админка или SelfHeal::setReporting(false).\n\n";
        } elseif ($answer === 'n' || $answer === 'no' || $answer === 'н' || $answer === 'нет') {
            SelfHeal::setReporting(false, 'installer');
            echo "Выключено. Модуль работает полностью, наружу не уходит ничего.\n\n";
        } else {
            echo "Ответа нет — оставлено «не решено»: отчёты пишутся только локально.\n\n";
        }
    }

    echo "Подключение в вашем проекте:\n\n" . $snippet . "\n\n";
    echo "Проверить: php tests/selfheal_smoke.php\n";
    echo "Инструкции для ассистента/разработчика: AGENTS.md\n\n";
    exit(0);
}

/* ─────────────────────── web ─────────────────────── */

header('X-Robots-Tag: noindex, nofollow', true);
header('Content-Type: text/html; charset=utf-8');

$result = SelfHeal::handleAdminPost($_POST);
$h = static function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h(Contract::MODULE) ?> — установка</title>
<style>
  body{max-width:820px;margin:0 auto;padding:24px 16px;font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#111;background:#fff}
  @media (prefers-color-scheme:dark){body{color:#e8e8ea;background:#141417}code,pre{background:#1e1e22}}
  h1{font-size:21px;margin:0 0 4px}
  ul{padding-left:20px}li{margin:3px 0}
  code,pre{background:#f3f4f6;border-radius:6px;padding:2px 6px;font-size:13px}
  pre{padding:12px;overflow:auto}
  .bad{color:#b54708}
</style></head><body>
<h1><?= $h(Contract::MODULE) ?> <?= $h(Contract::version()) ?></h1>
<p style="opacity:.7;margin:0 0 18px">API <?= $h(Contract::API_VERSION) ?> · установка <?= $h((string) ($diag['install_id'] ?? '')) ?></p>

<h2 style="font-size:16px">Окружение</h2>
<ul>
<?php foreach ($checks as [$label, $ok, $why]): ?>
  <li><?= $ok ? '✔' : '⚠' ?> <?= $h($label) ?><?= $ok ? '' : ' — <span class="bad">' . $h($why) . '</span>' ?></li>
<?php endforeach; ?>
</ul>

<?= SelfHeal::adminNotice(['result' => (array) $result]) ?>

<h2 style="font-size:16px">Подключение</h2>
<pre><?= $h($snippet) ?></pre>
<p>Подробные правила встраивания — в <code>AGENTS.md</code>, проверка — <code>php tests/selfheal_smoke.php</code>.</p>
</body></html>
