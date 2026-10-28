<?php
/**
 * Operator settings — LLM provider / model / OCR + SMTP.
 * spec: spec/settings.md §3 — infrastructural code, no customer TZ behind it.
 *
 * Saved values land in the `settings` table and are overlaid by config.php on
 * the next request, so provider / models / API keys / mail can be changed
 * without editing code. Access is gated by ADMIN_PASSWORD (config / ENV).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Robots-Tag: noindex, nofollow', true);

require_once __DIR__ . '/settings_store.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/llm.php';            // model list + auto-fallback preview
require_once __DIR__ . '/model_catalog.php';  // live provider catalogue
require_once __DIR__ . '/diag_log.php';       // operator-facing diagnostic log
require_once __DIR__ . '/auto_pull.php';      // silent "is the deployed code the latest?" check
require_once __DIR__ . '/selfheal/bootstrap.php';  // self-maintaining layer (own namespace)

$cfg   = require __DIR__ . '/config.php';
$store = new SettingsStore($cfg['DB_PATH']);

// Diagnostic log: same database, wiped whenever the deployed code changes.
DiagLog::init((string) $cfg['DB_PATH'], DiagLog::codeStamp([
    __DIR__ . '/llm.php', __DIR__ . '/config.php', __DIR__ . '/model_catalog.php',
    __DIR__ . '/setup.php', __DIR__ . '/parser.php', __DIR__ . '/mailer.php',
]));
DiagLog::addSecret((string) ($cfg['OPENROUTER_API_KEY'] ?? ''));
DiagLog::addSecret((string) ($cfg['YANDEX_API_KEY'] ?? ''));
DiagLog::addSecret((string) ($cfg['SMTP_PASS'] ?? ''));
DiagLog::addSecret((string) ($cfg['GENROUTER_API_KEY'] ?? ''));
DiagLog::addSecret((string) ($cfg['ADMIN_PASSWORD'] ?? ''));
ToolkitLLM::init($cfg, DiagLog::store());

// Self-maintaining layer: error reports to the canonical repo (only with the
// operator's consent), update notice with «Обновить» / «Откатить». Booting it
// changes nothing else on this page — it installs chained handlers and returns.
Selfheal\SelfHeal::boot([
    'app'     => 'llm_connector/setup.php',
    'secrets' => [
        (string) ($cfg['OPENROUTER_API_KEY'] ?? ''), (string) ($cfg['YANDEX_API_KEY'] ?? ''),
        (string) ($cfg['SMTP_PASS'] ?? ''), (string) ($cfg['ADMIN_PASSWORD'] ?? ''),
    ],
]);

session_start();
header('Cache-Control: no-store');
$ADMIN_PW = (string) ($cfg['ADMIN_PASSWORD'] ?? '');
$login_error = null;

if (($_POST['action'] ?? '') === 'login') {
    $pw = (string) ($_POST['password'] ?? '');
    if ($ADMIN_PW !== '' && hash_equals($ADMIN_PW, $pw)) {
        $_SESSION['admin_authed'] = true;
        header('Location: setup.php');
        exit;
    }
    $login_error = 'Неверный пароль';
}

if (($_GET['logout'] ?? '') !== '') {
    $_SESSION = [];
    session_destroy();
    header('Location: setup.php');
    exit;
}

$h = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$hostAuthenticated = defined('SITE_TOOLKIT_HOST_AUTHENTICATED') && SITE_TOOLKIT_HOST_AUTHENTICATED === true;
if (!$hostAuthenticated && empty($_SESSION['admin_authed'])) {
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>llm_connector — setup (вход)</title>
    <style>
:root { color-scheme: light; }
* { box-sizing: border-box; }
input, select, textarea, button, .box { border-radius: 8px; }
      body { font-family: -apple-system, system-ui, sans-serif; background: #fff; color: #17243b; padding: 24px; }
      .box { max-width: 340px; margin: 80px auto; background: #fff; border: 1px solid #d9e1ed; padding: 28px 26px; }
      .catalog-reset { padding:2px 7px; background:transparent; color:#555; opacity:.65; margin-left:4px; font-size:14px; }
  h1 { font-size: 20px; margin: 0 0 18px; }
      input { width: 100%; padding: 10px 12px; background: #fff; color: #17243b; border: 1px solid #d9e1ed; font: inherit; margin-bottom: 14px; }
      button { background: #171717; color: #fff; border: 0; padding: 12px 18px; font-weight: 700; cursor: pointer; width: 100%; }
      .err { color: #a82742; font-size: 13px; margin: 0 0 12px; }
    </style></head><body>
    <div class="box">
      <h1>ВХОД В НАСТРОЙКИ</h1>
      <?php if ($login_error): ?><p class="err"><?= $h($login_error) ?></p><?php endif; ?>
      <?php if ($ADMIN_PW === ''): ?><p class="err">ADMIN_PASSWORD не задан (config.php / ENV).</p><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <input type="password" name="password" autofocus required placeholder="Пароль администратора">
        <button type="submit">Войти</button>
      </form>
    </div></body></html><?php
    exit;
}

// Auto-pull options: the switch lives in `settings`, the credentials in
// pull-config.php next to pull.php. The state file goes to the data directory —
// the web root is what a deploy overwrites.
$autopull_opts = AutoPull::options($cfg, ['state_dir' => dirname((string) $cfg['DB_PATH'])]);

// Silent check on every view of this page: a newer commit is deployed and the
// page is reloaded from the new code. Nothing is printed when there is nothing to do.
AutoPull::run($autopull_opts);

$messages = [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// The module's own buttons (consent, update, rollback) are handled first and
// return null for every POST that is not theirs, so nothing below changes.
$selfheal_result = Selfheal\SelfHeal::handleAdminPost($_POST);
if (is_array($selfheal_result)) {
    $messages[] = ['ok' => !empty($selfheal_result['ok']),
        'text' => (!empty($selfheal_result['ok']) ? '✅ Модуль: ' : '⚠️ Модуль: ') . (string) ($selfheal_result['note'] ?? '')];
}

if ($method === 'POST') {
    if (isset($_POST['model_catalog'])) {
        // Keys just typed into the form are saved before the fetch — otherwise
        // the catalogue would be pulled with the previous credentials.
        foreach (['GENROUTER_API_KEY', 'OPENROUTER_API_KEY', 'YANDEX_API_KEY', 'YANDEX_FOLDER_ID'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '') $store->setSetting($k, $v);
        }
        $cfg_live = require __DIR__ . '/config.php';
        if ((string) $_POST['model_catalog'] === 'forget') {
            ModelCatalog::forget($store);
            $messages[] = ['ok' => true, 'at' => 'catalog', 'text' => '✅ Живой каталог забыт — в списках остались вшитые модели.'];
        } else {
            try {
                $rep = ModelCatalog::refresh($cfg_live, $store);
                $messages[] = ['ok' => true, 'at' => 'catalog', 'text' => '✅ Каталог обновлён: ' . (int) $rep['rows'] . ' моделей'
                    . ' (OpenRouter: ' . (is_int($rep['openrouter']) ? $rep['openrouter'] : '⛔ ' . $rep['openrouter'])
                    . ', Yandex: ' . (is_int($rep['yandex']) ? $rep['yandex'] : '⛔ ' . $rep['yandex']) . ').'];
            } catch (Throwable $e) {
                $messages[] = ['ok' => false, 'at' => 'catalog', 'text' => '⚠️ Каталог моделей не получен: ' . $e->getMessage()];
            }
        }
    } elseif (isset($_POST['autopull_check'])) {
        $rep = AutoPull::check($autopull_opts, true);
        $messages[] = $rep['ok']
            ? ['ok' => true, 'at' => 'autopull', 'text' => '✅ Автообновление: ' . $rep['note']
                . ' (head ' . substr($rep['head'], 0, 7) . ').']
            : ['ok' => false, 'at' => 'autopull', 'text' => '⚠️ Автообновление: ' . $rep['error']];
    } elseif (isset($_POST['smtp_test'])) {
        // Test letter via the CURRENT saved settings (re-read overlay).
        $cfg_live = require __DIR__ . '/config.php';
        $test_to = trim((string) ($_POST['smtp_test_to'] ?? '')) ?: (string) ($cfg_live['ADMIN_EMAIL'] ?? '');
        try {
            Mailer::sendTest($cfg_live, $test_to);
            $messages[] = ['ok' => true, 'at' => 'smtp', 'text' => '✅ Тестовое письмо отправлено на ' . $test_to
                . ' (host=' . ($cfg_live['SMTP_HOST'] ?? '') . ':' . ($cfg_live['SMTP_PORT'] ?? '') . ').'];
        } catch (Throwable $e) {
            $messages[] = ['ok' => false, 'at' => 'smtp', 'text' => '⚠️ SMTP-тест не прошёл: ' . $e->getMessage()];
        }
    } elseif (isset($_POST['llm_probe'])) {
        $cfg_live = require __DIR__ . '/config.php';
        ToolkitLLM::init($cfg_live, DiagLog::store());
        foreach (ToolkitLLM::probe() as $leg) {
            $messages[] = ['ok' => (bool) $leg['ok'], 'at' => 'probe', 'text' => ($leg['ok'] ? '✅ ' : '⛔ ')
                . $leg['leg'] . ' — ' . $leg['model'] . ': ' . $leg['text']];
        }
    } elseif (isset($_POST['diag_clear'])) {
        DiagLog::clear();
        DiagLog::info('admin', 'Лог очищен вручную из настроек');
        $messages[] = ['ok' => true, 'text' => '✅ Лог очищен.'];
    } elseif (($_POST['action'] ?? 'save') === 'save') {
        $edited = [];
        // Backup models come from three dropdowns; the joined value is always
        // written (an empty pick MUST be able to clear the list).
        $picked = [];
        foreach (['LLM_FALLBACK_MODEL_1', 'LLM_FALLBACK_MODEL_2', 'LLM_FALLBACK_MODEL_3'] as $slot) {
            $v = trim((string) ($_POST[$slot] ?? ''));
            if ($v !== '' && !in_array($v, $picked, true)) $picked[] = $v;
        }
        if (isset($_POST['LLM_FALLBACK_MODEL_1'])) {
            $store->setSetting('LLM_FALLBACK_MODELS', implode(',', $picked));
            $edited[] = 'LLM_FALLBACK_MODELS';
        }
        // String settings. Empty values never overwrite existing.
        $map = [
            'LLM_PROVIDER', 'LLM_PROVIDER_PRIORITY', 'LLM_DEFAULT_MODEL',
            'LLM_FALLBACK_MODE', 'MODEL_CATALOG_TTL_MIN', 'LLM_MAX_TOKENS',
            'LLM_OCR_MODELS', 'YANDEX_OCR_MODEL',
            'GENROUTER_API_KEY', 'GENROUTER_BASE_URL', 'GENROUTER_MODEL', 'LLM_AUDIO_MODEL', 'OPENROUTER_API_KEY', 'YANDEX_API_KEY', 'YANDEX_FOLDER_ID',
            'ADMIN_EMAIL', 'ERROR_EMAIL',
            'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_FROM_NAME',
            'ADMIN_PASSWORD',
        ];
        foreach ($map as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '') { $store->setSetting($k, $v); $edited[] = $k; }
        }
        // Model dropdowns: «— не задана —» has to be able to clear the value,
        // so these are written whenever the field was submitted at all.
        foreach (['LLM_VISION_MODEL', 'LLM_FALLBACK_MODEL', 'YANDEX_FALLBACK_MODEL'] as $k) {
            if (!isset($_POST[$k])) continue;
            $store->setSetting($k, trim((string) $_POST[$k]));
            $edited[] = $k;
        }
        // Auto-pull: the checkbox and its two knobs are always written, so both
        // «выключить» and «очистить адрес» actually take effect.
        $store->setSetting('AUTOPULL_ENABLED', isset($_POST['AUTOPULL_ENABLED']) ? '1' : '0');
        $edited[] = 'AUTOPULL_ENABLED=' . (isset($_POST['AUTOPULL_ENABLED']) ? '1' : '0');
        foreach (['AUTOPULL_INTERVAL', 'AUTOPULL_URL'] as $k) {
            if (isset($_POST[$k])) $store->setSetting($k, trim((string) $_POST[$k]));
        }
        // Checkbox: Yandex Vision OCR. Always written.
        $store->setSetting('YANDEX_OCR_ENABLED', isset($_POST['YANDEX_OCR_ENABLED']) ? '1' : '0');
        $edited[] = 'YANDEX_OCR_ENABLED=' . (isset($_POST['YANDEX_OCR_ENABLED']) ? '1' : '0');
        $messages[] = ['ok' => true, 'text' => '✅ Сохранено: ' . implode(', ', $edited)];
    }
}

// The model catalogue refreshes when this page is opened: a cache older than
// MODEL_CATALOG_TTL_MIN minutes is refetched from the providers, otherwise
// nothing happens. A network failure never breaks the page — the previous list
// stays and the reason is rendered below.
ModelCatalog::maybeRefresh(require __DIR__ . '/config.php', $store);

// Re-load config so freshly-saved overlay values render in the "current" hints.
$cfg = require __DIR__ . '/config.php';
$current = $store->allSettings();
$mask = static fn (?string $v) => $v ? str_repeat('•', max(4, min(strlen((string) $v), 16))) : '';
$mask_key = static function (?string $v): string {
    $v = (string) $v;
    if ($v === '') return '';
    return strlen($v) > 10 ? substr($v, 0, 4) . '…' . substr($v, -4) : str_repeat('•', strlen($v));
};
// Effective value: saved overlay first, then config fallback.
$eff = static function (string $k) use ($cfg, $current): string {
    if (isset($current[$k]) && $current[$k] !== '') return (string) $current[$k];
    $v = $cfg[$k] ?? '';
    return is_array($v) ? implode(',', $v) : (string) $v;
};
$ocr_models_eff = $eff('LLM_OCR_MODELS');

ToolkitLLM::init($cfg, DiagLog::store());

// ── Model dropdowns ──────────────────────────────────────────────────────
// Nobody types a slug: every model field is a <select> built from the same
// catalogue (hardcoded rows + what the providers reported).
$groups = [];
usort($cfg['AVAILABLE_MODELS'], static function(array $a,array $b):int { $rank=['genrouter'=>0,'openrouter'=>1,'yandex'=>2];return ($rank[$a['provider'] ?? ''] ?? 3)<=>($rank[$b['provider'] ?? ''] ?? 3); });
foreach ((array) ($cfg['AVAILABLE_MODELS'] ?? []) as $mdl) {
    if (empty($mdl['ocr_only'])) $groups[(string) ($mdl['group'] ?? 'Модели')][] = $mdl;
}
// Price hint: RUB per 1k for hardcoded rows, USD per 1M for live ones (that is
// how the provider reports it — no invented exchange rate).
$price = static function (array $m): string {
    $num = static function (float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); };
    $in = (float) ($m['price_in'] ?? 0); $out = (float) ($m['price_out'] ?? 0);
    if ($in > 0 || $out > 0) return sprintf(' · ~%s/%s ₽ за 1k', $num($in), $num($out));
    $ui = (float) ($m['price_usd_in'] ?? 0); $uo = (float) ($m['price_usd_out'] ?? 0);
    if ($ui > 0 || $uo > 0) return sprintf(' · $%s/$%s за 1M', $num($ui), $num($uo));
    return !empty($m['free']) ? ' · бесплатно' : '';
};
/**
 * One model <select>. $value tells what a row is worth as a stored value —
 * a short id for LLM_DEFAULT_MODEL, "provider:slug" wherever the provider has
 * to travel with the slug. A saved value missing from the catalogue is kept as
 * its own option: otherwise the browser would pick the first row and saving
 * would silently swap the model.
 */
$model_select = static function (string $name, string $current, callable $value, ?callable $filter = null, string $empty = '') use ($groups, $price, $h): string {
    $html = '<select name="' . $h($name) . '">';
    if ($empty !== '') {
        $html .= '<option value=""' . ($current === '' ? ' selected' : '') . '>' . $h($empty) . '</option>';
    }
    $known = false;
    $body = '';
    foreach ($groups as $gname => $grows) {
        $rows = $filter === null ? $grows : array_values(array_filter($grows, $filter));
        if (!$rows) continue;
        $body .= '<optgroup label="' . $h((string) $gname) . '">';
        foreach ($rows as $mdl) {
            $val = (string) $value($mdl);
            if ($val === $current) $known = true;
            $body .= '<option value="' . $h($val) . '"' . ($val === $current ? ' selected' : '') . '>'
                . $h((string) $mdl['label']) . ' — ' . $h((string) $mdl['provider']) . '/' . $h((string) $mdl['full_id'])
                . $h($price($mdl)) . '</option>';
        }
        $body .= '</optgroup>';
    }
    if ($current !== '' && !$known) {
        $html .= '<option value="' . $h($current) . '" selected>' . $h($current) . ' — нет в каталоге</option>';
    }
    return $html . $body . '</select>';
};
$by_short  = static function (array $m): string { return (string) $m['id']; };
$by_slug   = static function (array $m): string { return (string) $m['provider'] . ':' . (string) $m['full_id']; };
$only_vision = static function (array $m): bool { return !empty($m['vision']); };
$fallback_picked = array_values(array_filter(array_map('trim', explode(',', $eff('LLM_FALLBACK_MODELS')))));
// A vision model stored as a bare slug (the config default) is shown as the
// provider-qualified option it resolves to — otherwise a perfectly valid value
// would render as «нет в каталоге».
$vision_cur = $eff('LLM_VISION_MODEL');
if ($vision_cur !== '' && strpos($vision_cur, ':') === false) {
    $vision_row = ToolkitLLM::resolveModelSpec($vision_cur);
    if ($vision_row !== null) $vision_cur = $vision_row['provider'] . ':' . $vision_row['full_id'];
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>llm_connector — настройки</title>
<style>
:root { color-scheme: light; }
* { box-sizing: border-box; }
input, select, textarea, button, .box { border-radius: 8px; }
  body { font-family: -apple-system, system-ui, sans-serif; background: #fff; color: #17243b; padding: 70px 24px 24px; max-width: 720px; margin: 0 auto; }
  .catalog-reset { padding:2px 7px; background:transparent; color:#555; opacity:.65; margin-left:4px; font-size:14px; }
  h1 { font-size: 22px; } h2 { font-size: 16px; margin: 26px 0 10px; color: #171717; }
  .lede { color: #56657a; font-size: 14px; }
  label { display: block; margin: 12px 0; font-size: 14px; }
  label span { display: block; color: #56657a; margin-bottom: 4px; }
  input, select { width: 100%; padding: 9px 11px; background: #fff; color: #17243b; border: 1px solid #d9e1ed; font: inherit; }
  button { background: #171717; color: #fff; border: 0; padding: 12px 20px; font-weight: 700; cursor: pointer; }
  .msg { padding: 10px 12px; margin: 8px 0; border: 1px solid #d9e1ed; font-size: 14px; }
  .msg.ok { border-color: #1f6f4f; } .msg.bad { border-color: #a82742; }
  .row { display: flex; flex-wrap: wrap; gap: 12px; } .row > * { flex: 1 1 220px; min-width: 0; }
  a { color: #171717; }
</style></head><body>
<h1>llm_connector — настройки <a href="?logout=1" style="font-size:13px;float:right">выйти</a></h1>
<p class="lede">Заполните только нужные поля — пустые значения не перезаписывают существующие. Значения сразу применяются ко всему сервису (overlay через таблицу <code>settings</code>).</p>

<?php
// A check's answer is printed under its own button; only the rest goes on top
$render_msgs = static function (string $at) use ($messages, $h): string {
    $out = '';
    foreach ($messages as $m) {
        if (($m['at'] ?? 'top') !== $at) continue;
        $out .= '<div class="msg ' . ($m['ok'] ? 'ok' : 'bad') . '">' . $h($m['text']) . '</div>';
    }
    return $out;
};
echo $render_msgs('top');
?>

<?php
/* Self-maintaining layer: the consent question while it is open, the "new
   version" notice with «Обновить» / «Откатить», and the module's own status.
   Returns '' when there is nothing to say. */
echo Selfheal\SelfHeal::adminNotice();
?>

<form method="post" autocomplete="off">
  <input type="hidden" name="action" value="save">

  <h2 id="provider-keys">API-ключи</h2><p>Начните с ключа провайдера, затем выберите модели ниже.</p>
  <label><span>GenRouter API Key</span><input type="password" name="GENROUTER_API_KEY" placeholder="<?= $h($mask_key($eff('GENROUTER_API_KEY'))) ?>"></label>
  <details><summary>Подключить дополнительные провайдеры</summary>
  <label><span>OpenRouter API Key</span><input type="password" name="OPENROUTER_API_KEY" placeholder="<?= $h($mask_key($eff('OPENROUTER_API_KEY'))) ?>"></label>
  <label><span>Yandex API Key</span><input type="password" name="YANDEX_API_KEY" placeholder="<?= $h($mask_key($eff('YANDEX_API_KEY'))) ?>"></label>
  <label data-yandex-folder <?= trim((string)$eff('YANDEX_API_KEY')) === '' ? 'hidden' : '' ?>><span>Yandex Folder ID</span><input type="text" name="YANDEX_FOLDER_ID" placeholder="<?= $h($eff('YANDEX_FOLDER_ID')) ?>"></label>

  </details>
  <h2>Провайдер и модели</h2>
  <div class="row">
    <label><span>Провайдер по умолчанию</span>
      <select name="LLM_PROVIDER">
        <option value="genrouter" <?= $eff('LLM_PROVIDER') === 'genrouter' ? 'selected' : '' ?>>GenRouter</option>
        <option value="openrouter" <?= $eff('LLM_PROVIDER') === 'openrouter' ? 'selected' : '' ?>>openrouter (fallback → yandex)</option>
        <option value="yandex" <?= $eff('LLM_PROVIDER') === 'yandex' ? 'selected' : '' ?>>yandex</option>
      </select>
    </label>
    <label><span>Приоритет провайдеров</span>
      <select name="LLM_PROVIDER_PRIORITY">
        <option value="genrouter,openrouter,yandex" <?= $eff('LLM_PROVIDER_PRIORITY') === 'genrouter,openrouter,yandex' ? 'selected' : '' ?>>GenRouter → OpenRouter → Yandex</option>
        <option value="openrouter,yandex" <?= $eff('LLM_PROVIDER_PRIORITY') === 'openrouter,yandex' ? 'selected' : '' ?>>openrouter → yandex</option>
        <option value="yandex,openrouter" <?= $eff('LLM_PROVIDER_PRIORITY') === 'yandex,openrouter' ? 'selected' : '' ?>>yandex → openrouter</option>
      </select>
    </label>
  </div>
  <label><span>Модель по умолчанию (каталог: вшитый список + то, что отдал провайдер)</span>
    <?php $model_cur = $eff('LLM_DEFAULT_MODEL'); ?>
    <?= $model_select('LLM_DEFAULT_MODEL', $model_cur, $by_short) ?>
  </label>
  <?php
  $live_rows   = ModelCatalog::decode((string) ($cfg['MODEL_CATALOG_MODELS'] ?? ''));
  $live_synced = (string) ($cfg['MODEL_CATALOG_SYNCED_AT'] ?? '');
  $live_error  = (string) ($cfg['MODEL_CATALOG_ERROR'] ?? '');
  $live_ttl    = (int) ($cfg['MODEL_CATALOG_TTL_MIN'] ?? ModelCatalog::TTL_MIN);
  ?>
  <p class="lede" style="margin:-4px 0 10px">
    Список тянется прямо у провайдеров (OpenRouter <code>GET /models</code>, Yandex <code>GET /foundationModels/v1/models</code>)
    и кэшируется в настройках. Каталог обновляется сам при заходе на эту страницу, если кэш старше
    <?= $live_ttl ?> мин; кнопка ниже обновляет сразу. Пока обновления не было — работает вшитый список.
    <?php if ($live_rows): ?>
      <br>Сейчас живых моделей: <b><?= count($live_rows) ?></b><?= $live_synced !== '' ? ', обновлено ' . $h($live_synced) : '' ?>.
    <?php else: ?>
      <br>Живого каталога пока нет — в списках только вшитые модели.
    <?php endif; ?>
    <?php if ($live_error !== ''): ?>
      <br><span style="color:#a82742">Последняя попытка: <?= $h(mb_substr($live_error, 0, 200)) ?></span>
    <?php endif; ?>
  </p>
  <label><span>Срок годности кэша каталога, мин</span><input type="text" name="MODEL_CATALOG_TTL_MIN" placeholder="<?= $h((string) $live_ttl) ?>"></label>
  <div class="row" id="catalog">
    <button type="submit" name="model_catalog" value="refresh" formaction="setup.php#catalog" formnovalidate>Обновить каталог моделей</button>
    <?php if ($live_rows): ?>
      <button type="submit" name="model_catalog" value="forget" aria-label="Забыть живой каталог" title="Забыть живой каталог" class="catalog-reset" formaction="setup.php#catalog" formnovalidate>×</button>
    <?php endif; ?>
  </div>
  <?= $render_msgs('catalog') ?>

  <?php
  // What 'auto' resolves to for the currently chosen model — shown right here,
  // because an irregular slug (yandexgpt, gpt-4o) carries no readable version.
  ToolkitLLM::init($cfg);
  $auto_rows = ToolkitLLM::autoFallbackRows($model_cur);
  ?>
  <label style="margin-top:14px"><span>Запасная модель: что пробовать после выбранной</span>
    <select name="LLM_FALLBACK_MODE">
      <option value="auto" <?= $eff('LLM_FALLBACK_MODE') !== 'manual' ? 'selected' : '' ?>>авто — более новая версия той же модели, затем список</option>
      <option value="manual" <?= $eff('LLM_FALLBACK_MODE') === 'manual' ? 'selected' : '' ?>>только список ниже</option>
    </select>
  </label>
  <p class="lede" style="margin:-4px 0 8px">
    «Авто» ищет в каталоге ту же модель более новой версии (<code>gpt-4.1</code> → <code>gpt-5.1</code>,
    <code>claude-sonnet-4</code> → <code>claude-sonnet-4.5</code>), поэтому обновлённый каталог сразу даёт свежий запас.
    <?php if ($auto_rows): ?>
      Для выбранной модели это: <b><?= $h(implode(', ', array_map(static function ($r) { return (string) $r['full_id']; }, array_slice($auto_rows, 0, 3)))) ?></b>.
    <?php else: ?>
      У выбранной модели версия в слаге не читается или новее ничего нет — сработает список ниже.
    <?php endif; ?>
  </p>
  <label><span>Запасные модели — выбираются из каталога, пробуются в этом порядке после выбранной</span></label>
  <div class="row">
    <?php for ($i = 1; $i <= 3; $i++): ?>
      <label><span>№<?= $i ?></span>
        <?= $model_select('LLM_FALLBACK_MODEL_' . $i, (string) ($fallback_picked[$i - 1] ?? ''), $by_short, null, '— не задана —') ?>
      </label>
    <?php endfor; ?>
  </div>
  <label><span>Vision-модель: фото, этикетки и страницы PDF (GenRouter, OpenRouter и Yandex — только модели со зрением)</span>
    <?= $model_select('LLM_VISION_MODEL', $vision_cur, $by_slug, $only_vision, '— не задана (значение из config.php) —') ?>
  </label>
  <label><span>OpenRouter fallback-модель (последняя попытка на этом провайдере)</span>
    <?= $model_select('LLM_FALLBACK_MODEL', $eff('LLM_FALLBACK_MODEL'), static function (array $m): string { return (string) $m['full_id']; }, static function (array $m): bool { return ($m['provider'] ?? '') === 'openrouter'; }, '— не задана —') ?>
  </label>
  <label><span>Yandex fallback-модель (full_id без gpt://, последняя попытка на этом провайдере)</span>
    <?= $model_select('YANDEX_FALLBACK_MODEL', $eff('YANDEX_FALLBACK_MODEL'), static function (array $m): string { return (string) $m['full_id']; }, static function (array $m): bool { return ($m['provider'] ?? '') === 'yandex'; }, '— не задана —') ?>
  </label>
  <p class="lede" style="margin:-4px 0 8px">
    Модель, которой нет в живом каталоге провайдера, в запасные не подставляется: слепой запрос к ней
    отвечает <code>Failed to get model</code> и прячет настоящую причину сбоя.
  </p>
  <label><span>Предел длины ответа, токенов — 0 значит «сколько даст провайдер»</span>
    <input type="number" name="LLM_MAX_TOKENS" min="0" placeholder="<?= $h((string) $eff('LLM_MAX_TOKENS')) ?>"></label>
  <p class="lede" style="margin:-4px 0 8px">
    Пусто или 0 — предел выбирает провайдер, и ничего из того, что помещается сейчас, обрезаться не начнёт.
    Поднимать это число стоит тогда, когда в логе появляется «ответ оборвался по пределу длины»:
    оборванный ответ никогда не разбирается как JSON.
  </p>
  <label><span>OCR-модели OpenRouter (через запятую, по порядку)</span><input type="text" name="LLM_OCR_MODELS" placeholder="<?= $h($ocr_models_eff ?: 'google/gemini-2.5-flash,google/gemini-2.0-flash-001') ?>"></label>
  <label><span>Yandex Vision OCR модель</span><input type="text" name="YANDEX_OCR_MODEL" placeholder="<?= $h($eff('YANDEX_OCR_MODEL') ?: 'page') ?>"></label>
  <label style="display:flex;align-items:center;gap:8px;">
    <input type="checkbox" name="YANDEX_OCR_ENABLED" value="1" style="width:auto;" <?= $eff('YANDEX_OCR_ENABLED') === '1' ? 'checked' : '' ?>>
    <span style="margin:0;">Включить Yandex Vision OCR в цепочку PDF-распознавания</span>
  </label>

  <label><span>Голосовая модель (GenRouter / OpenRouter)</span>
    <?= $model_select('LLM_AUDIO_MODEL', $eff('LLM_AUDIO_MODEL'), $by_slug, static function(array $m):bool{return !empty($m['audio']);}, '— подключите GenRouter и обновите каталог —') ?>
  </label>
  <h2>Почта (SMTP)</h2>
  <p>SMTP — подключение почтового сервера для отправки писем из вашего сервиса. Если сервис не отправляет письма, эти поля можно не заполнять. Подтверждение email администратора также может использовать почту хостинга.</p>
  <div class="row">
    <label><span>SMTP host</span><input type="text" name="SMTP_HOST" placeholder="<?= $h($eff('SMTP_HOST') ?: 'smtp.example.com') ?>"></label>
    <label><span>SMTP port</span><input type="text" name="SMTP_PORT" placeholder="<?= $h($eff('SMTP_PORT') ?: '465') ?>"></label>
  </div>
  <label><span>SMTP user</span><input type="text" name="SMTP_USER" placeholder="<?= $h($eff('SMTP_USER')) ?>"></label>
  <label><span>Пароль приложения</span><input type="password" name="SMTP_PASS" placeholder="<?= $h($mask($eff('SMTP_PASS'))) ?>"></label>
  <div class="row">
    <label><span>SMTP from</span><input type="text" name="SMTP_FROM" placeholder="<?= $h($eff('SMTP_FROM')) ?>"></label>
    <label><span>From name</span><input type="text" name="SMTP_FROM_NAME" placeholder="<?= $h($eff('SMTP_FROM_NAME') ?: 'llm_connector') ?>"></label>
  </div>
  <label><span>ADMIN_EMAIL (получатель копий/вложений)</span><input type="text" name="ADMIN_EMAIL" placeholder="<?= $h($eff('ADMIN_EMAIL')) ?>"></label>
  <label><span>ERROR_EMAIL (уведомления об ошибках)</span><input type="text" name="ERROR_EMAIL" placeholder="<?= $h($eff('ERROR_EMAIL')) ?>"></label>

  <h2>Автообновление кода с GitHub</h2>
  <?php
  $ap_status = AutoPull::status($autopull_opts);
  $ap_root   = AutoPull::root($autopull_opts);
  $ap_cfg    = AutoPull::pullConfig($ap_root);
  ?>
  <p class="lede" style="margin:-4px 0 10px">
    На время активной разработки: каждое открытие страницы тихо спрашивает у GitHub head
    отслеживаемой ссылки. Тот же коммит — не происходит ничего; новый — <code>pull.php</code>
    выкладывает его, и страница открывается заново уже на новом коде. Репозиторий, токен и
    пароль <code>pull.php</code> берутся из <code>pull-config.php</code> рядом со скриптом —
    здесь их дублировать не нужно.
    <?php if ($ap_cfg === null): ?>
      <br><span style="color:#a82742">pull-config.php не найден (искали в <?= $h($ap_root) ?>) — включать нечего.</span>
    <?php else: ?>
      <br>Отслеживается: <b><?= $h($ap_cfg['repo']) ?></b> ·
      <?= $ap_cfg['source'] === 'pr' ? 'PR #' . (int) $ap_cfg['pr_number'] : 'ветка ' . $h($ap_cfg['branch']) ?>.
    <?php endif; ?>
    <?php if ($ap_status['checked_at'] > 0): ?>
      <br>Последняя проверка: <?= $h(date('Y-m-d H:i:s', $ap_status['checked_at'])) ?>
      <?= $ap_status['note'] !== '' ? '— ' . $h($ap_status['note']) : '' ?>.
    <?php endif; ?>
    <?php if ($ap_status['error'] !== ''): ?>
      <br><span style="color:#a82742">Ошибка: <?= $h(mb_substr($ap_status['error'], 0, 200)) ?></span>
    <?php endif; ?>
  </p>
  <label style="display:flex;align-items:center;gap:10px;">
    <input type="checkbox" name="AUTOPULL_ENABLED" value="1" style="width:auto;" <?= $eff('AUTOPULL_ENABLED') === '1' ? 'checked' : '' ?>>
    <span style="margin:0;">Проверять обновления при каждом запуске сервиса</span>
  </label>
  <div class="row">
    <label><span>Не чаще, сек (0 — при каждом открытии страницы)</span><input type="text" name="AUTOPULL_INTERVAL" value="<?= $h($eff('AUTOPULL_INTERVAL')) ?>"></label>
    <label><span>Адрес pull.php (пусто — вычисляется сам)</span><input type="text" name="AUTOPULL_URL" value="<?= $h($eff('AUTOPULL_URL')) ?>" placeholder="https://сайт/pull.php"></label>
  </div>

  <h2>Доступ</h2>
  <label><span>Пароль администратора (ADMIN_PASSWORD)</span><input type="password" name="ADMIN_PASSWORD" placeholder="<?= $h($mask($eff('ADMIN_PASSWORD'))) ?>"></label>

  <p><button type="submit">Сохранить</button></p>
</form>

<form method="post" action="setup.php#autopull" id="autopull" autocomplete="off" style="margin-top:18px;">
  <h2>Проверить обновление сейчас</h2>
  <p class="lede" style="margin:-4px 0 10px">Спрашивает head у GitHub и, если коммит новее выложенного, запускает <code>pull.php</code> — независимо от галочки выше.</p>
  <p><button type="submit" name="autopull_check" value="1">Проверить и обновить</button></p>
  <?= $render_msgs('autopull') ?>
</form>

<form method="post" action="setup.php#probe" id="probe" autocomplete="off" style="margin-top:18px;">
  <h2>Проверка провайдеров</h2>
  <p class="lede">Один короткий запрос к каждой настроенной модели — сразу видно, что именно
    отвечает провайдер: неверный ключ, чужой каталог, модель не включена в облачном каталоге.
    Результат попадает и в лог ниже.</p>
  <p><button type="submit" name="llm_probe" value="1">Проверить модели и ключи</button></p>
  <?= $render_msgs('probe') ?>
</form>

<?php
$diag_counts = DiagLog::counts();
$diag_header = [
    'приложение'   => 'llm_connector (setup.php)',
    'php'          => PHP_VERSION . ' на ' . PHP_OS,
    'база'         => (string) $cfg['DB_PATH'],
    'код'          => (string) (DiagLog::state('code_stamp') ?? '—')
        . ', задеплоен ' . (string) (DiagLog::state('deployed_at') ?? '—'),
    'ключи'        => 'OpenRouter: ' . ($eff('OPENROUTER_API_KEY') !== '' ? DiagLog::maskKey($eff('OPENROUTER_API_KEY')) : 'НЕ ЗАДАН')
        . '; Yandex: ' . ($eff('YANDEX_API_KEY') !== '' ? DiagLog::maskKey($eff('YANDEX_API_KEY')) : 'НЕ ЗАДАН')
        . '; folder: ' . ($eff('YANDEX_FOLDER_ID') !== '' ? $eff('YANDEX_FOLDER_ID') : 'НЕ ЗАДАН'),
];
foreach (ToolkitLLM::configSummary() as $k => $v) $diag_header['llm.' . $k] = is_scalar($v) ? (string) $v : json_encode($v);
$diag_full   = DiagLog::asText(DiagLog::tail(400, false), $diag_header, 'llm_connector — полный лог');
$diag_errors = DiagLog::asText(DiagLog::tail(400, true), $diag_header, 'llm_connector — только ошибки');
?>
<h2>Лог</h2>
<p class="lede">
  Записей: <b><?= (int) $diag_counts['total'] ?></b>, из них ошибок и предупреждений: <b><?= (int) $diag_counts['errors'] ?></b>.
  Лог живёт в той же базе и <b>очищается сам при обновлении кода</b> — то, что здесь видно, относится к текущему деплою.
  Ключи и пароли в тексте замаскированы, лог можно пересылать как есть.
</p>
<div class="row" style="margin-bottom:10px;">
  <button type="button" onclick="diagCopy('diag-full', this)">Скопировать полный лог</button>
  <button type="button" onclick="diagCopy('diag-errors', this)">Скопировать только ошибки</button>
  <form method="post" style="margin:0;"><button type="submit" name="diag_clear" value="1" style="width:100%">Очистить лог</button></form>
</div>
<label><span>Только ошибки</span><textarea id="diag-errors" readonly rows="10" style="width:100%;font:12px/1.45 ui-monospace,Menlo,Consolas,monospace;background:#fff;color:#17243b;border:1px solid #d9e1ed;padding:9px 11px;"><?= $h($diag_errors) ?></textarea></label>
<label><span>Полный лог</span><textarea id="diag-full" readonly rows="16" style="width:100%;font:12px/1.45 ui-monospace,Menlo,Consolas,monospace;background:#fff;color:#17243b;border:1px solid #d9e1ed;padding:9px 11px;"><?= $h($diag_full) ?></textarea></label>
<script>
function diagCopy(id, btn) {
  var el = document.getElementById(id);
  var done = function () { var t = btn.textContent; btn.textContent = 'Скопировано'; setTimeout(function () { btn.textContent = t; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(el.value).then(done, function () { el.select(); document.execCommand('copy'); done(); });
  } else { el.select(); document.execCommand('copy'); done(); }
}
</script>

<form method="post" action="setup.php#smtp" id="smtp" autocomplete="off" style="margin-top:18px;">
  <script>(()=>{const host=document.querySelector('[name=SMTP_HOST]'),pass=document.querySelector('[name=SMTP_PASS]');if(!host||!pass)return;const note=document.createElement('p');note.className='lede';pass.parentElement.after(note);const help=()=>{const h=host.value.toLowerCase();let url='',label='';if(h.includes('yandex')){url='https://id.yandex.ru/security/app-passwords';label='Яндекс';}else if(h.includes('mail.ru')||h.includes('bk.ru')||h.includes('list.ru')||h.includes('inbox.ru')){url='https://id.mail.ru/security/passwords';label='Mail.ru';}else if(h.includes('gmail')||h.includes('google')){url='https://myaccount.google.com/apppasswords';label='Google (нужна двухэтапная аутентификация)';}note.replaceChildren();if(url){const a=document.createElement('a');a.href=url;a.target='_blank';a.rel='noopener';a.textContent='Создать пароль приложения — '+label;note.append(a);}};host.addEventListener('input',help);help()})();</script>
  <h2>Тест SMTP</h2>
  <label><span>Кому отправить тестовое письмо</span><input type="text" name="smtp_test_to" placeholder="<?= $h($eff('ADMIN_EMAIL')) ?>"></label>
  <p><button type="submit" name="smtp_test" value="1">Отправить тестовое письмо</button></p>
  <?= $render_msgs('smtp') ?>
</form>
</body></html>


<style>[data-yandex-folder][hidden]{display:none!important}</style>
<script>(()=>{const key=document.querySelector('[name="YANDEX_API_KEY"]'),folder=document.querySelector('[data-yandex-folder]');if(!key||!folder)return;const saved=<?= json_encode(trim((string)$eff('YANDEX_API_KEY')) !== '') ?>;const update=()=>{folder.hidden=!(saved||key.value.trim());};key.addEventListener('input',update);update();})();</script>
