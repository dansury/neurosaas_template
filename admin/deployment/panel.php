<?php
// Parse-safe bootstrap: older PHP versions must report requirements, not a syntax error.
if (PHP_VERSION_ID < 70400) {
    if (!headers_sent()) http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><h1>Обновите PHP на хостинге</h1><p>Требуется PHP 7.4 или новее; рекомендуется 8.1+. Сейчас: ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</p><p>Сервер: ' . htmlspecialchars(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'версия не раскрывается', ENT_QUOTES, 'UTF-8') . '</p><p>Попросите хостинг переключить PHP и включить zip и cURL. Для .htaccess нужен Apache 2.4+, для nginx — аналогичные запреты доступа.</p>';
    exit;
}
$neurosaas_engine = <<<'NEUROSAAS_ENGINE_20'
<?php
// NeuroSaaS deployment and service control panel.
// Pulls a subdirectory from a GitHub repo and overwrites the directory this file lives in.
// Configuration lives in ./pull-config.php (next to this file). Delete pull-config.php to re-run setup.
//
// Usage: open https://<your-host>/pull.php in a browser; GET shows the control panel.
// Append ?plain=1 for unstyled text/plain output (useful for cron / curl).
// Append ?watch=1 for the tracking screen: it polls the tracked ref and can deploy on its own.
// Append ?check=1 to deploy only when the tracked ref moved (the cron-friendly entry point).
// Append ?status=1 for a JSON snapshot of "what is live vs. what is on GitHub".
// Append ?history=1 for the deploy log: every version that stood here, with the
//   date it was deployed and a button to roll back to it.
// Append ?logout=1 to forget a remembered password on this browser.
//
// What gets deployed is picked during setup: the head of a branch, or the head of a
// pull request (deploy previews). The deployed commit is remembered in ./pull-state.json,
// which is what makes "only pull when something changed" possible.
//
// Optional password gate (set during setup): the password is stored hashed and
// can be remembered in a cookie. For cron, send it as the X-Pull-Password header.
// Optional purge: after copying, delete everything that is no longer in the repo.

declare(strict_types=1);

if (!defined('PULL_ROOT')) define('PULL_ROOT', dirname(__DIR__, 2));

const CONFIG_FILE = 'pull-config.php';
const STATE_FILE  = 'pull-state.json';
const ALWAYS_KEEP = ["pull.php","pull-config.php","pull-state.json","pull-deploy.lock","ai-config.php","admin/feedback/data","pull-archives","data","storage",".env","config.php",".git","admin/account/data","admin/llm_connector/data","admin/llm_connector/.env","admin/llm_connector/.htaccess","admin/llm_connector/selfheal/token.local.php","modules/site_yacloud_openrouter/data","modules/site_yacloud_openrouter/.env","modules/site_yacloud_openrouter/.htaccess","modules/site_yacloud_openrouter/selfheal/token.local.php"];

// Prefilled fine-grained token form: everything but the repository picker can be
// set from the URL. See docs.github.com -> "Pre-filling personal access token details".
const PAT_NEW_URL     = 'https://github.com/settings/personal-access-tokens/new';
const PAT_CLASSIC_URL = 'https://github.com/settings/tokens/new?scopes=repo&description=pull.php+deploy';

const AUTO_INTERVAL_MIN = 15;
const AUTO_INTERVAL_DEF = 60;

// How many past deploys stay in the log, and so how far back a rollback can reach.
const HISTORY_MAX = 20;

const AUTH_COOKIE       = 'pull_auth';
const AUTH_REMEMBER_TTL = 2592000; // "remember me" cookie lifetime: 30 days
const AUTH_SESSION_TTL  = 43200;   // without "remember me": 12 hours, session cookie

if (defined('PULL_LIBRARY_ONLY')) return;
if (isset($_GET['feedback_image']) && is_file(PULL_ROOT.'/admin/deployment/feedback-media.php')) { require_once PULL_ROOT.'/admin/deployment/feedback-media.php'; FeedbackMedia::serve((string)$_GET['feedback_image']); }
if (!defined('PULL_SKIP_PREFLIGHT')) {
    $missing = [];
    if (PHP_VERSION_ID < 70400) $missing[] = 'PHP 7.4+';
    if (!class_exists('ZipArchive')) $missing[] = 'PHP extension zip';
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) $missing[] = 'cURL or allow_url_fopen';
    if (!is_writable(PULL_ROOT)) $missing[] = 'write permission for the installation directory';
    if ($missing) {
        http_response_code(503);
        echo '<!doctype html><meta charset="utf-8"><h1>Требования к хостингу</h1><p>Попросите хостинг включить: ' . htmlspecialchars(implode(', ', $missing), ENT_QUOTES, 'UTF-8') . '</p><p>PHP: ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '; сервер: ' . htmlspecialchars((string)($_SERVER['SERVER_SOFTWARE'] ?? 'не раскрывает версию'), ENT_QUOTES, 'UTF-8') . '</p><p>Рекомендуется PHP 8.1+. Apache 2.4+ нужен только для правил .htaccess; nginx поддерживается при аналогичном запрете доступа к данным.</p>';
        exit;
    }
}
// The standalone installer remains an authenticated management entry point after installation.
init_panel();
if (is_file(PULL_ROOT . '/admin/llm_connector/diagnostics.php')) {
    require_once PULL_ROOT . '/admin/llm_connector/diagnostics.php';
    \NeuroSaaS\Diagnostics::boot();
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') check_csrf();

$configPath = PULL_ROOT . '/' . CONFIG_FILE;
$plain      = isset($_GET['plain']);

// First-run setup: render form (GET) or write config (POST)
if (!file_exists($configPath)) {
    if (isset($_GET['feedback'])) { $config=['repo'=>'']; render_feedback(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'Сначала установите сервис и подключите GitHub, затем отправьте идею.' : '', ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'); exit; }
    if (in_array($_POST['action'] ?? '', ['github_repositories','github_branches'], true)) { repository_discovery(''); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_setup_post($configPath);
    } else {
        render_setup_form();
    }
    exit;
}

$config = load_config($configPath);
if ($config['password_hash'] === '' && $config['bootstrap_session'] !== '' && !hash_equals($config['bootstrap_session'], hash('sha256', session_id()))) { http_response_code(403); exit('Продолжите установку в браузере, в котором вы её начали. Если доступ потерян, удалите pull-config.php на хостинге и начните заново.'); }
date_default_timezone_set($config['timezone'] ?: 'UTC');

ignore_user_abort(true);
@set_time_limit(300);

// Optional IP allowlist (KRASKI_PULL_ALLOW_IPS, comma-separated) restricts who
// may trigger a pull. Empty = no restriction.
$denied = null;
$allow = array_filter(array_map(
    'trim',
    explode(',', (string)(getenv('KRASKI_PULL_ALLOW_IPS') ?: ''))
));
if ($allow !== [] && !in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), $allow, true)) {
    $denied = 'forbidden: IP not allowed';
}
if ($denied !== null) {
    http_response_code(403);
    if ($plain) {
        header('Content-Type: text/plain; charset=utf-8');
        exit($denied . "\n");
    }
    header('Content-Type: text/html; charset=utf-8');
    exit("<!doctype html><meta charset=\"utf-8\"><title>403</title><body style=\"color-scheme:light;background:#fff;color:#86283b;font-family:monospace;padding:2rem\">"
        . htmlspecialchars($denied, ENT_QUOTES) . "</body>");
}

$mode = isset($_GET['status']) ? 'json' : ($plain ? 'plain' : 'html');

$accountGateAvailable = is_file(PULL_ROOT . '/admin/account/gate.php');
if (defined('NEUROSAAS_ADMIN')) {
    require_once PULL_ROOT . '/admin/llm_connector/migrate.php';
    try { \NeuroSaaS\LayoutMigration::run(); }
    catch (Throwable $e) { http_response_code(503); page_start('Обновление настроек'); echo '<p>' . h($e->getMessage()) . '</p>'; page_end(); exit; }
}
if ($accountGateAvailable) {
    if (!defined('PULL_ACCOUNT_HOST')) define('PULL_ACCOUNT_HOST', true);
    require_once PULL_ROOT . '/admin/account/gate.php';
    if (($_GET['account'] ?? '') === 'reset') \NeuroSaaS\AdminAccount::gate($configPath, true);
}
// Existing deployment passwords authenticate the owner before enrollment.
if ($config['password_hash'] !== '') {
    require_auth($config['password_hash'], $mode);
}
if ($accountGateAvailable) {
    \NeuroSaaS\AdminAccount::gate($configPath);
}
if (defined('NEUROSAAS_ADMIN') && isset($_GET['webapp'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['password'])) { header('Location: ?webapp=1', true, 303); exit; }
    require_once PULL_ROOT . '/admin/webapp/controller.php';
    \NeuroSaaS\webapp_admin((string)$_SESSION['pull_csrf'], (string)($config['admin_email'] ?? ''));
    exit;
}
if (isset($_GET['toolkit'])) {
    // Never let the standalone module interpret the login POST as a settings save.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['password'])) {
        header('Location: ?toolkit=1', true, 303);
        exit;
    }
    if ($config['password_hash'] === '') {
        http_response_code(403);
        exit('Set a panel password before opening advanced settings.');
    }
    require_once PULL_ROOT . '/admin/llm_connector/toolkit.php';
    try { \NeuroSaaS\Toolkit::settings((string)$_SESSION['pull_csrf']); }
    catch (Throwable $e) { http_response_code(503); echo 'Advanced settings require PHP curl, pdo_sqlite, zip, dom and mbstring with a writable module data directory.'; }
    exit;
}

$ghToken = (string)(getenv('GITHUB_TOKEN') ?: $config['gh_token']);
$action = (string)($_POST['action'] ?? '');
if (in_array($action, ['github_repositories','github_branches'], true)) { repository_discovery($ghToken); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rollback_archive'])) { archive_deploy($config); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '' && $action !== 'deploy') {
    handle_panel_post($config, $ghToken);
    exit;
}
if (isset($_GET['feedback'])) { render_feedback(); exit; }
if (isset($_GET['diagnostics'])) { render_diagnostics_page(); exit; }
if (!isset($_GET['status']) && !isset($_GET['watch']) && !isset($_GET['history'])
    && !isset($_GET['check']) && !$plain && $action !== 'deploy') {
    render_dashboard($config); exit;
}


// Local uploads must never enter the GitHub resolver/deployer, including cron and rollback.
if (is_local_install($config)) {
    if (isset($_GET['watch'])) { render_dashboard($config); exit; }
    if (isset($_GET['status'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'mode' => 'local', 'auto' => false, 'changed' => false, 'sha' => '', 'deployed' => '']); exit;
    }
    if (isset($_GET['history']) && !empty(state_read()['sha'])) { render_history_page($config); exit; }
    if (isset($_GET['history'])) { page_start(ui('Version history', 'История версий')); echo '<section><p>' . ui('Files were uploaded manually. No GitHub deployment history exists yet.', 'Файлы загружены вручную. Истории установок из GitHub пока нет.') . '</p><a href="?">← ' . ui('Back to panel', 'Вернуться в панель') . '</a></section>'; page_end(); exit; }
    http_response_code(409);
    if ($plain) { header('Content-Type: text/plain; charset=utf-8'); exit("STATUS: ERROR\nLocal installation: connect GitHub in administration before deploying.\n"); }
    render_dashboard($config, ui('Connect GitHub before installing updates.', 'Для установки обновлений сначала подключите GitHub.'), true); exit;
}
// Read-only endpoints. None of them touch the target directory.
if (isset($_GET['status']))  { emit_status_json($config, $ghToken); exit; }
if (isset($_GET['watch']))   { render_watch_page($config);          exit; }
if (isset($_GET['history'])) { render_history_page($config);        exit; }

// ?check=1 deploys only when the tracked ref moved since the last successful pull.
$checkOnly = isset($_GET['check']) || isset($_POST['automatic']);
if (isset($_POST['automatic']) && !$config['auto_pull']) { http_response_code(409); exit('Automatic updates disabled'); }

// A rollback re-deploys a commit that already stood on this server. The version is
// looked up in the deploy log, so only what actually ran here can be rolled back to.
$rollbackTo = trim((string)($_POST['rollback'] ?? ''));

// Resolve what to deploy before any output, so a hard failure can still answer with
// a real HTTP status instead of a 200 with an error inside it. Streaming starts
// right after, as it always did.
// A stable lock inode is always preserved during deploy and purge.
$deployLock = @fopen(PULL_ROOT . '/pull-deploy.lock', 'c');
if ($deployLock === false || !flock($deployLock, LOCK_EX | LOCK_NB)) {
    http_response_code(409); start_output($plain);
    term(ui('Another update is running. Please try again shortly.', 'Другое обновление уже выполняется. Повторите чуть позже.'));
    end_output(); exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$state    = state_read();
$deployed = (string)($state['sha'] ?? '');
$track    = $rollbackTo !== ''
    ? resolve_rollback($rollbackTo, $state)
    : resolve_target($config, $ghToken);
if ($checkOnly && ($track['error'] !== '' || $track['sha'] === '')) $track['fatal'] = true;
if ($track['fatal']) http_response_code(502);

start_output($plain);

$tz       = date_default_timezone_get();
$startTs  = microtime(true);
$startStr = date('Y-m-d H:i:s');

term("================================================");
term("  START:    {$startStr} ({$tz})");
term("================================================");
term("");

$isRollback = $track['mode'] === 'rollback';

term(($isRollback ? "rollback:  " : "tracking:  ") . $track['label']);
if ($track['sha'] !== '') {
    term(($isRollback ? "version:   " : "head:      ") . short_sha($track['sha'])
        . ($deployed !== ''
            ? "   (deployed: " . short_sha($deployed) . ")"
            : "   (nothing deployed from here yet)"));
}
if ($track['error'] !== '') term("warning: {$track['error']}");

// A pull request that cannot be resolved has no safe fallback — stop before the copy.
if ($track['fatal']) {
    term("error: cannot resolve what to deploy — nothing was touched");
    // A missing version is a bad parameter, not a permissions problem: no token hints.
    if ($isRollback) {
        term("hint: pull.php?history=1 lists the versions that can be rolled back to");
    } else {
        foreach (token_hint_lines($config) as $line) term($line);
    }
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

// A rollback pins this directory to an old commit. Automatic deploys stand down
// until someone deploys the tracked head again, otherwise the next check would
// immediately undo the rollback.
if ($checkOnly && !$isRollback && !empty($state['pinned'])) {
    term("check: pinned to " . short_sha($deployed) . " by a rollback on " . (string)($state['at'] ?? '?'));
    term("       automatic deploys are paused — open pull.php to deploy the tracked head and resume");
    print_finish_banner($startTs, $startStr, $tz, true, 0, null, 'PINNED');
    end_output();
    exit;
}

if ($checkOnly) {
    if ($track['sha'] === '') {
        term("check: head commit unknown — deploying anyway");
    } elseif ($track['sha'] === $deployed) {
        term("check: already up to date — nothing to do");
        print_finish_banner($startTs, $startStr, $tz, true, 0, null, 'UP-TO-DATE');
        end_output();
        exit;
    } else {
        term("check: new commit found — deploying");
    }
}
term("");

$target = __DIR__;
$tmp    = sys_get_temp_dir() . '/pull_' . bin2hex(random_bytes(6));
if (!mkdir($tmp, 0755, true)) {
    http_response_code(500);
    term("error: cannot create temp dir");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$authHeaders = $ghToken !== '' ? ["Authorization: Bearer {$ghToken}"] : [];

// With a resolved commit we download that exact sha: the deploy then matches what
// was reported above, and PR heads are reachable this way while branch names are not.
$zipUrls = $track['sha'] !== ''
    ? [
        sprintf('https://api.github.com/repos/%s/zipball/%s', $config['repo'], $track['sha']),
        sprintf('https://codeload.github.com/%s/zip/%s', $config['repo'], $track['sha']),
    ]
    : [
        sprintf('https://api.github.com/repos/%s/zipball/%s', $config['repo'], $config['branch']),
        sprintf('https://codeload.github.com/%s/zip/refs/heads/%s', $config['repo'], $config['branch']),
        sprintf('https://github.com/%s/archive/refs/heads/%s.zip', $config['repo'], $config['branch']),
    ];
$zipFile = $tmp . '/repo.zip';

if ($ghToken === '') {
    term("warning: no GitHub token set — private repo downloads will return 404");
}

$bytes = 0;
$lastErr = '';
foreach ($zipUrls as $zipUrl) {
    term("downloading {$zipUrl}");
    [$bytes, $lastErr] = download($zipUrl, $zipFile, $authHeaders);
    if ($bytes > 0) break;
    term("  failed: {$lastErr}");
}
if ($bytes <= 0) {
    cleanup($tmp);
    http_response_code(502);
    term("download failed: {$lastErr}");
    if (strpos($lastErr, 'http 404') !== false || strpos($lastErr, 'http 401') !== false) {
        foreach (token_hint_lines($config) as $line) term($line);
    }
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
term("downloaded {$bytes} bytes");

if (!class_exists('ZipArchive')) {
    cleanup($tmp);
    http_response_code(500);
    term("error: ZipArchive extension not available");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    cleanup($tmp);
    http_response_code(500);
    term("error: cannot open zip");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
try { validate_archive($zip); } catch (Throwable $e) { $zip->close(); cleanup($tmp); http_response_code(422); term($e->getMessage()); end_output(); exit; }
if (!$zip->extractTo($tmp)) {
    $zip->close();
    cleanup($tmp);
    http_response_code(500);
    term("error: extract failed");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}
$zip->close();
term("extracted archive");

$src = null;
$subdir = $config['subdir'] !== '' ? $config['subdir'] : '.';
foreach (scandir($tmp) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $candidate = $subdir === '.' ? $tmp . '/' . $entry : $tmp . '/' . $entry . '/' . $subdir;
    if (is_dir($candidate)) { $src = $candidate; break; }
}
if ($src === null) {
    cleanup($tmp);
    http_response_code(500);
    term("error: '{$subdir}' not found inside the archive");
    print_finish_banner($startTs, $startStr, $tz, false);
    end_output();
    exit;
}

$keep = array_values(array_unique(array_merge(ALWAYS_KEEP, $config['keep_files'])));
term("copying into {$target} (preserving: " . implode(', ', $keep) . ")");
$copied = 0;
copyTree($src, $target, $keep, $copied);

term("copied {$copied} files");

// Purge runs only after a successful copy, so a failed download never deletes anything.
$deleted = null;
if ($config['purge']) {
    term("purge: on — deleting everything that is no longer in the repository (irreversible)");
    $deletedFiles = 0;
    $deletedDirs  = 0;
    purgeExtra($src, $target, $keep, $deletedFiles, $deletedDirs);
    $deleted = $deletedFiles;
    term("deleted {$deletedFiles} files, {$deletedDirs} directories");
} else {
    term("purge: off — files removed from the repository stay on the server");
}

cleanup($tmp); // only now — purge compares the target against $src

// Remember what is live and append to the deploy log: that is what the next
// ?check=1 compares against, and what a rollback can later reach back to.
$prSummary = '';
if (!$isRollback && $track['sha'] !== '') {
    [$prs,$ignored] = gh_api('/repos/' . $config['repo'] . '/commits/' . $track['sha'] . '/pulls', $ghToken);
    foreach ((array)$prs as $pr) if (is_array($pr)) $prSummary .= '#' . (int)($pr['number'] ?? 0) . ' ' . (string)($pr['title'] ?? '') . "\n";
}
state_record_deploy([
    'pr_summary' => $isRollback ? ($track['from']['pr_summary'] ?? '') : trim($prSummary),
    'sha'      => $track['sha'],
    'ref'      => $track['ref'],
    'mode'     => $isRollback ? (string)($track['from']['mode'] ?? 'rollback') : $track['mode'],
    'label'    => $isRollback ? (string)($track['from']['label'] ?? $track['label']) : $track['label'],
    'pr'       => $track['pr'],
    'at'       => date('Y-m-d H:i:s'),
    'at_utc'   => gmdate('c'),
    'status'   => 'ok',
    'files'    => $copied,
    'deleted'  => $deleted,
    'rollback' => $isRollback,
], $isRollback);

if ($track['sha'] !== '') term("deployed commit " . short_sha($track['sha']) . " recorded in " . STATE_FILE);
if ($isRollback) {
    term("pinned: automatic deploys are paused until the tracked head is deployed from pull.php");
} elseif (!empty($state['pinned'])) {
    term("unpinned: back on the tracked ref, automatic deploys resume");
}

print_finish_banner($startTs, $startStr, $tz, true, $copied, $deleted, 'DONE', $track);
end_output();
exit;

// ---------- output helpers ----------




function print_finish_banner(
    float $startTs, string $startStr, string $tz, bool $ok,
    int $copied = 0, ?int $deleted = null, string $label = '', ?array $track = null
): void {
    $endTs   = microtime(true);
    $endStr  = date('Y-m-d H:i:s');
    $dur     = $endTs - $startTs;
    $status  = $label !== '' ? $label : ($ok ? 'DONE' : 'FAILED');
    term("");
    term("================================================");
    term("  STATUS:   {$status}");
    term("  START:    {$startStr} ({$tz})");
    term("  END:      {$endStr} ({$tz})");
    term("  DURATION: " . number_format($dur, 2) . " seconds");
    if ($track !== null && $track['sha'] !== '') {
        term("  COMMIT:   " . short_sha($track['sha']) . "  (" . $track['ref'] . ")");
    }
    if ($ok) {
        term("  FILES:    {$copied}");
        if ($deleted !== null) {
            term("  DELETED:  {$deleted}");
        }
    }
    term("================================================");
    if (empty($GLOBALS['TERM_PLAIN'])) {
        echo '</pre></details><p role="status" class="notice ' . ($ok ? 'success' : 'error') . '">'
            . ($ok ? ui('Service updated successfully.', 'Сервис успешно обновлён.') : ui('Update failed. Open technical details above and check the GitHub connection and hosting permissions.', 'Обновление не удалось. Откройте технические сведения выше и проверьте подключение GitHub и права на хостинге.'))
            . '</p>';
        if ($ok) echo '<p><a class="button open-service" href="' . (defined('NEUROSAAS_ADMIN') ? '../' : './') . '">Открыть сайт →</a></p>';
        echo '<details hidden><pre>';
    }
}

// ---------- config loader ----------

function load_config(string $path): array {
    $raw = require $path;
    if (!is_array($raw)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("pull-config.php must return an array\n");
    }
    return [
        'installation_mode' => ($raw['installation_mode'] ?? '') === 'local' ? 'local' : 'github',
        'repo'       => (string)($raw['repo']       ?? ''),
        'branch'     => (string)($raw['branch']     ?? 'main'),
        'subdir'     => (string)($raw['subdir']     ?? '.'),
        'gh_token'   => (string)($raw['gh_token']   ?? ''),
        'keep_files' => is_array($raw['keep_files'] ?? null) ? array_values($raw['keep_files']) : [],
        'timezone'   => (string)($raw['timezone']   ?? 'UTC'),
        // Both default to "off" when the key is missing, so a config written by an
        // older version keeps working and never starts deleting files by surprise.
        'password_hash' => (string)($raw['password_hash'] ?? ''),
        'bootstrap_session' => (string)($raw['bootstrap_session'] ?? ''),
        'purge'         => (bool)($raw['purge'] ?? false),
        // Tracking. A config written before tracking existed has no 'source' key,
        // so it keeps behaving exactly as it did: plain branch deploys, no auto-pull.
        'source'        => ((string)($raw['source'] ?? '') === 'pr' || (int)($raw['pr_number'] ?? 0) > 0) ? 'pr' : 'branch',
        'pr_number'     => max(0, (int)($raw['pr_number'] ?? 0)),
        'auto_pull'     => (bool)($raw['auto_pull'] ?? false),
        'auto_interval' => max(AUTO_INTERVAL_MIN, (int)($raw['auto_interval'] ?? AUTO_INTERVAL_DEF)),
    ];
}

// ---------- tracking: what is on GitHub vs. what is deployed ----------

function short_sha(string $sha): string {
    return $sha === '' ? '-' : substr($sha, 0, 7);
}

function state_path(): string {
    return PULL_ROOT . '/' . STATE_FILE;
}

// The deploy log: which commit is currently live. Missing or unreadable = nothing deployed yet.
function state_read(): array {
    if (!is_file(state_path())) return [];
    $raw = json_decode((string)@file_get_contents(state_path()), true);
    return is_array($raw) ? $raw : [];
}

function state_write(array $state): void {
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return;
    @file_put_contents(state_path(), $json . "\n", LOCK_EX);
    @chmod(state_path(), 0600); // it lives in the web root — keep PR titles out of public reach
}

// Appends a deploy to the log and marks what is live. $pinned is set by a rollback:
// it tells the automatic paths to stand down so they do not undo it.
function state_record_deploy(array $entry, bool $pinned): void {
    $state   = state_read();
    $history = isset($state['history']) && is_array($state['history']) ? $state['history'] : [];

    // Deploying the same commit twice in a row refreshes the top entry instead of
    // filling the log with repeats of one version.
    if (!empty($history) && (string)($history[0]['sha'] ?? '') === (string)$entry['sha'] && empty($entry['rollback'])) {
        $history[0] = $entry;
    } else {
        array_unshift($history, $entry);
    }

    $state            = $entry;
    $state['pinned']  = $pinned;
    $state['history'] = array_slice($history, 0, HISTORY_MAX);
    state_write($state);
}

// The log is a deploy journal, but what a rollback picks from is a list of versions:
// one entry per commit, carrying the last date it was deployed.
function history_versions(array $state): array {
    $seen = [];
    $out  = [];
    foreach (($state['history'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $sha = (string)($entry['sha'] ?? '');
        if ($sha === '' || isset($seen[$sha])) continue;
        $seen[$sha] = true;
        $out[] = $entry;
    }
    return $out;
}

// "3 d ago" next to an exact timestamp — the age is what tells you at a glance
// how old the version you are about to roll back to actually is.
function human_age(string $iso): string {
    $t = strtotime($iso);
    if ($t === false) return '';
    $d = time() - $t;
    if ($d < 90)     return 'just now';
    if ($d < 5400)   return (int)floor($d / 60) . ' min ago';
    if ($d < 172800) return (int)floor($d / 3600) . ' h ago';
    return (int)floor($d / 86400) . ' d ago';
}

// Looks a rollback target up in the deploy log. Only versions that actually stood
// here can be reached, so a stray parameter cannot deploy an arbitrary commit.
function resolve_rollback(string $wanted, array $state): array {
    $out = [
        'mode' => 'rollback', 'ref' => '', 'sha' => '', 'label' => '',
        'error' => '', 'fatal' => false, 'pr' => null, 'commit' => null, 'from' => null,
    ];
    $wanted = strtolower((string)preg_replace('~[^0-9a-fA-F]~', '', $wanted));
    $out['label'] = $wanted === '' ? 'requested version is empty' : "requested version {$wanted}";
    if (strlen($wanted) < 7) {
        $out['error'] = 'a rollback needs at least the first 7 characters of the commit id';
        $out['fatal'] = true;
        return $out;
    }

    foreach (($state['history'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $sha = strtolower((string)($entry['sha'] ?? ''));
        if ($sha !== '' && strpos($sha, $wanted) === 0) {
            $out['sha']   = (string)$entry['sha'];
            $out['ref']   = (string)($entry['ref'] ?? '');
            $out['pr']    = $entry['pr'] ?? null;
            $out['from']  = $entry;
            $out['label'] = "back to " . short_sha($out['sha']) . " — "
                          . (string)($entry['label'] ?? $entry['ref'] ?? 'unknown source')
                          . ", deployed " . (string)($entry['at'] ?? 'at an unknown time');
            return $out;
        }
    }

    $out['error'] = "{$wanted} is not in the deploy log — only versions listed at "
                  . "pull.php?history=1 can be rolled back to";
    $out['fatal'] = true;
    return $out;
}

// Minimal GitHub REST client. Returns [decoded body, ''] or [null, 'reason'].
function gh_api(string $path, string $token): array {
    $url     = 'https://api.github.com' . $path;
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: pull.php',
    ];
    if ($token !== '') $headers[] = "Authorization: Bearer {$token}";

    $body = '';
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body  = (string)curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) return [null, "curl errno {$errno}: {$err}"];
    } else {
        if (!ini_get('allow_url_fopen')) return [null, 'no curl and allow_url_fopen=Off'];
        $hdr = '';
        foreach ($headers as $h) $hdr .= $h . "\r\n";
        $ctx  = stream_context_create(['http' => [
            'header'         => $hdr,
            'timeout'        => 20,
            'ignore_errors'  => true,
            'follow_location'=> 1,
        ]]);
        $body = (string)@file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $code = (int)$m[1];
        }
    }

    if ($code === 401) return [null, 'http 401 — the token is invalid, expired or revoked'];
    if ($code === 403) return [null, 'http 403 — rate limited, or the token is not allowed here'];
    if ($code === 404) return [null, 'http 404 — no such repo/ref, or the token cannot see it'];
    if ($code >= 400)  return [null, "http {$code}"];

    $data = json_decode($body, true);
    if (!is_array($data)) return [null, 'unreadable answer from api.github.com'];
    return [$data, ''];
}

// Works out the exact commit to deploy. 'fatal' means there is no safe fallback
// and the caller must abort; a soft 'error' means "deploy the branch tip blindly".
function resolve_target(array $config, string $token): array {
    $out = [
        'mode' => $config['source'], 'ref' => '', 'sha' => '', 'label' => '',
        'error' => '', 'fatal' => false, 'pr' => null, 'commit' => null,
    ];

    if ($config['source'] === 'pr') {
        $n = $config['pr_number'];
        $out['ref']   = "refs/pull/{$n}/head";
        $out['label'] = "pull request #{$n} of {$config['repo']}";

        [$pr, $err] = gh_api("/repos/{$config['repo']}/pulls/{$n}", $token);
        if ($pr === null) {
            $out['error'] = "cannot read pull request #{$n}: {$err}";
            $out['fatal'] = true; // deploying the branch instead would ship the wrong code
            return $out;
        }
        $out['pr'] = [
            'number'  => $n,
            'title'   => (string)($pr['title'] ?? ''),
            'state'   => !empty($pr['merged']) ? 'merged' : (string)($pr['state'] ?? ''),
            'draft'   => (bool)($pr['draft'] ?? false),
            'author'  => (string)($pr['user']['login'] ?? ''),
            'head'    => (string)($pr['head']['ref'] ?? ''),
            'base'    => (string)($pr['base']['ref'] ?? ''),
            'updated' => (string)($pr['updated_at'] ?? ''),
            'url'     => (string)($pr['html_url'] ?? ''),
        ];
        $out['sha']   = (string)($pr['head']['sha'] ?? '');
        $out['label'] = "PR #{$n} \"{$out['pr']['title']}\" [{$out['pr']['state']}"
                      . ($out['pr']['draft'] ? ', draft' : '') . "] "
                      . "{$out['pr']['head']} -> {$out['pr']['base']} by {$out['pr']['author']}";
        if ($out['sha'] === '') {
            $out['error'] = "pull request #{$n} has no head commit (deleted branch?)";
            $out['fatal'] = true;
        }
        return $out;
    }

    $out['ref']   = "refs/heads/{$config['branch']}";
    $out['label'] = "branch {$config['branch']} of {$config['repo']}";

    [$commit, $err] = gh_api("/repos/{$config['repo']}/commits/" . rawurlencode($config['branch']), $token);
    if ($commit === null) {
        // Manual deployment may use the branch archive; automated checks fail closed.
        $out['error'] = "cannot read the head of {$config['branch']}: {$err} — "
                      . "deploying the branch tip without change tracking";
        return $out;
    }
    $out['sha']    = (string)($commit['sha'] ?? '');
    $message       = (string)($commit['commit']['message'] ?? '');
    $out['commit'] = [
        'message' => trim(explode("\n", $message)[0]),
        'author'  => (string)($commit['author']['login'] ?? ($commit['commit']['author']['name'] ?? '')),
        'date'    => (string)($commit['commit']['author']['date'] ?? ''),
        'url'     => (string)($commit['html_url'] ?? ''),
    ];
    return $out;
}

// The prefilled "create a fine-grained token" URL. Everything except the repository
// picker can be set from the query string, so the user only ticks the repo itself.
function pat_url(string $repo, bool $needsPullRequests): string {
    $owner  = trim(explode('/', $repo)[0] ?? '');
    $name   = substr('pull.php deploy ' . $repo, 0, 40); // GitHub caps the name at 40 chars
    $params = [
        'name'        => $name,
        'description' => 'Deploy and feedback token for pull.php. Under "Repository access" pick '
                       . ($repo !== '' ? $repo : 'the repository to deploy') . '.',
        'expires_in'  => 'none',
        'contents'    => 'write',
        'issues'      => 'write',
        'metadata'    => 'read',
    ];
    $params['pull_requests'] = 'read';
    if ($owner !== '')      $params['target_name']   = $owner;
    return PAT_NEW_URL . '?' . http_build_query($params);
}

// Shown wherever a download or an API call comes back 401/404.
function token_hint_lines(array $config): array {
    $needsPr = $config['source'] === 'pr';
    return array_filter([
        "hint: 401/404 usually means the token cannot see this repository.",
        "  fine-grained PAT — needs 'Repository access' to include {$config['repo']},",
        "    plus Contents: Read and Issues: Read and write" . ($needsPr ? " and Pull requests: Read (for PR tracking)" : ""),
        "  ready-made link (permissions preselected):",
        "    " . pat_url($config['repo'], $needsPr),
        "  classic PAT — needs the full 'repo' scope, not just 'public_repo'",
        "  also check the token is neither expired nor revoked",
    ]);
}

// Compact deploy log for JSON consumers: enough to show it, not the whole record.
function history_summary(array $state): array {
    $out = [];
    foreach (history_versions($state) as $entry) {
        $out[] = [
            'sha'      => (string)($entry['sha'] ?? ''),
            'short'    => short_sha((string)($entry['sha'] ?? '')),
            'at'       => (string)($entry['at'] ?? ''),
            'age'      => human_age((string)($entry['at_utc'] ?? '')),
            'label'    => (string)($entry['label'] ?? ''),
            'ref'      => (string)($entry['ref'] ?? ''),
            'rollback' => !empty($entry['rollback']),
        ];
    }
    return $out;
}

// JSON snapshot for the tracking screen and for anything else that wants to poll.
function emit_status_json(array $config, string $token): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $track = resolve_target($config, $token);
    $state = state_read();
    $live  = (string)($state['sha'] ?? '');

    echo json_encode([
        'ok'            => $track['error'] === '',
        'error'         => $track['error'],
        'repo'          => $config['repo'],
        'mode'          => $track['mode'],
        'ref'           => $track['ref'],
        'label'         => $track['label'],
        'sha'           => $track['sha'],
        'short'         => short_sha($track['sha']),
        'commit'        => $track['commit'],
        'pr'            => $track['pr'],
        'deployed'      => $live,
        'deployed_short'=> short_sha($live),
        'deployed_at'   => (string)($state['at'] ?? ''),
        'deployed_files'=> $state['files'] ?? null,
        // Unknown head = cannot compare, so report "no change" rather than looping on pulls.
        'changed'       => $track['sha'] !== '' && $track['sha'] !== $live,
        'auto'          => $config['auto_pull'],
        'interval'      => $config['auto_interval'],
        'purge'         => $config['purge'],
        // A rollback pins the directory: the tracked head then sits ahead on purpose.
        'pinned'        => !empty($state['pinned']),
        'pinned_at'     => (string)($state['at'] ?? ''),
        'history'       => history_summary($state),
        'now'           => date('Y-m-d H:i:s'),
        'tz'            => date_default_timezone_get(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ---------- deploy log / rollback screen ----------

// Every version that stood in this directory, newest first, with the date it was
// deployed and a button that puts it back.


function download(string $url, string $dest, array $extraHeaders = []): array {
    @unlink($dest);
    if (function_exists('curl_init')) {
        $fp = fopen($dest, 'wb');
        if (!$fp) return [0, 'cannot open destination file'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'pull.php',
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER     => $extraHeaders,
        ]);
        $ok       = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errstr   = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $errno !== 0) {
            return [0, "curl errno {$errno}: {$errstr} (http {$httpCode})"];
        }
        if ($httpCode >= 400) {
            return [0, "http {$httpCode}"];
        }
        $size = (int)@filesize($dest);
        return [$size, $size > 0 ? '' : 'empty file'];
    }
    if (!ini_get('allow_url_fopen')) {
        return [0, 'no curl and allow_url_fopen=Off'];
    }
    $hdr = "User-Agent: pull.php\r\n";
    foreach ($extraHeaders as $h) $hdr .= $h . "\r\n";
    $ctx = stream_context_create(['http' => [
        'header'          => $hdr,
        'timeout'         => 300,
        'follow_location' => 1,
    ]]);
    $err = '';
    set_error_handler(function ($_, $msg) use (&$err) { $err = $msg; return true; });
    $ok = copy($url, $dest, $ctx);
    restore_error_handler();
    if (!$ok) return [0, $err ?: 'copy() failed'];
    $size = (int)@filesize($dest);
    return [$size, $size > 0 ? '' : 'empty file'];
}

function keepChildren(array $paths, string $directory): array {
    $prefix = $directory . '/';
    $children = [];
    foreach ($paths as $path) {
        if (strpos($path, $prefix) === 0) $children[] = substr($path, strlen($prefix));
    }
    return $children;
}

function copyTree(string $from, string $to, array $keepTopLevel, int &$copied): void {
    if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) return;
    foreach (new DirectoryIterator($from) as $f) {
        if ($f->isDot()) continue;
        $name = $f->getFilename();
        if (in_array($name, $keepTopLevel, true)) continue;
        $source = $f->getPathname();
        $destination = $to . '/' . $name;
        if ($f->isDir()) {
            copyTree($source, $destination, keepChildren($keepTopLevel, $name), $copied);
        } else {
            @copy($source, $destination);
            $copied++;
        }
    }
}

// Mirror mode: delete everything in $dst that has no counterpart in $src.
// Top-level names in $keepTopLevel are skipped entirely. Symlinks are never
// followed — the link itself is removed, whatever it points at stays untouched.
function purgeExtra(string $src, string $dst, array $keepTopLevel, int &$files, int &$dirs): void {
    if (!is_dir($src) || !is_dir($dst)) return;
    foreach (new DirectoryIterator($dst) as $f) {
        if ($f->isDot()) continue;
        $name = $f->getFilename();
        if (in_array($name, $keepTopLevel, true)) continue;
        $destination = $f->getPathname();
        $source = $src . '/' . $name;
        $nested = keepChildren($keepTopLevel, $name);
        if ($f->isLink()) {
            if (!file_exists($source)) { @unlink($destination); $files++; }
        } elseif ($f->isDir()) {
            if (is_dir($source)) {
                purgeExtra($source, $destination, $nested, $files, $dirs);
            } elseif ($nested === []) {
                rmTree($destination, $files, $dirs);
            }
        } elseif (!is_file($source)) {
            @unlink($destination);
            $files++;
        }
    }
}

function rmTree(string $dir, int &$files, int &$dirs): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) { @rmdir($f->getPathname()); $dirs++; }
        else { @unlink($f->getPathname()); $files++; }
    }
    if (@rmdir($dir)) $dirs++;
}

function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// ---------- password gate ----------

// Returns when the request is authorised; renders the unlock screen and exits otherwise.
// $mode is 'html' (unlock form), 'plain' (cron/curl) or 'json' (the ?status=1 poller).
function require_auth(string $hash, string $mode): void {
    if (isset($_GET['logout'])) {
        auth_cookie_clear();
        if ($mode === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ok' => false, 'auth' => false, 'error' => 'logged out']));
        }
        if ($mode === 'plain') {
            header('Content-Type: text/plain; charset=utf-8');
            exit("logged out\n");
        }
        render_login_form(['logged out — enter the password to continue']);
        exit;
    }

    $cookie = (string)($_COOKIE[AUTH_COOKIE] ?? '');
    if ($cookie !== '' && auth_token_valid($cookie, $hash)) return;

    // Password may also arrive from a form post, a query param, or a header (cron).
    $supplied = null;
    foreach ([
        $_POST['password'] ?? null,
                $_SERVER['HTTP_X_PULL_PASSWORD'] ?? null,
    ] as $candidate) {
        if (is_string($candidate) && $candidate !== '') { $supplied = $candidate; break; }
    }

    if ($supplied !== null && password_verify($supplied, $hash)) {
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        auth_cookie_set($hash, isset($_POST['remember']) || isset($_GET['remember']));
        return;
    }

    if ($supplied !== null) usleep(400000); // small brake on password guessing

    http_response_code(401);
    if ($mode === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        // The tracking screen reads this and stops polling instead of hammering the gate.
        exit(json_encode(['ok' => false, 'auth' => false, 'error' => 'password required']));
    }
    if ($mode === 'plain') {
        header('Content-Type: text/plain; charset=utf-8');
        exit(($supplied !== null ? "unauthorized: wrong password\n" : "unauthorized: password required\n")
            . "hint: send it as a header, e.g.\n"
            . "  curl -s -H \"X-Pull-Password: \$PULL_PASSWORD\" \"https://host/pull.php?plain=1\"\n");
    }
    render_login_form($supplied !== null ? ['wrong password'] : []);
    exit;
}

// Token = expiry + HMAC over it, keyed by the stored password hash. Changing the
// password changes the hash, which invalidates every cookie handed out before.
function auth_token(string $hash, int $expires): string {
    return $expires . '|' . hash_hmac('sha256', 'pull-auth|' . $expires, $hash);
}

function auth_token_valid(string $token, string $hash): bool {
    $parts = explode('|', $token, 2);
    if (count($parts) !== 2) return false;
    $expires = (int)$parts[0];
    if ($expires <= time()) return false;
    return hash_equals(hash_hmac('sha256', 'pull-auth|' . $expires, $hash), $parts[1]);
}

function auth_cookie_set(string $hash, bool $remember): void {
    $ttl     = $remember ? AUTH_REMEMBER_TTL : AUTH_SESSION_TTL;
    $expires = time() + $ttl;
    setcookie(AUTH_COOKIE, auth_token($hash, $expires), [
        'expires'  => $remember ? $expires : 0, // 0 = cookie dies with the browser session
        'path'     => auth_cookie_path(),
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_cookie_clear(): void {
    setcookie(AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => auth_cookie_path(),
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Scope the cookie to the directory the script lives in, not the whole host.
function auth_cookie_path(): string {
    if (defined('NEUROSAAS_ADMIN')) {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'));
        return rtrim(str_replace('\\', '/', dirname(dirname($script))), '/.') . '/';
    }
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/pull.php')));
    return ($dir === '' || $dir === '.' || $dir === '/') ? '/' : rtrim($dir, '/') . '/';
}

function auth_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}


function local_upload_available(): bool {
    foreach (['index.php', 'admin/index.php', 'admin/llm_connector/toolkit.php'] as $path) {
        if (!is_file(PULL_ROOT . '/' . $path)) return false;
    }
    return true;
}
function is_local_install(array $config): bool {
    return ($config['installation_mode'] ?? '') === 'local';
}
function render_local_setup(array $values, array $errors): void {
    page_start(ui('Files are already uploaded', 'Файлы уже загружены'));
    render_workflow_diagram();
    echo '<p>' . ui('Use the same administration without GitHub downloads. The first administrator needs no email confirmation. AI, mail and sending issues need their own network connections.', 'Откройте ту же админку без скачивания с GitHub. Первый администратор создаётся без подтверждения email. Для AI, почты и отправки задач нужен доступ к соответствующим сервисам.') . '</p>';
    foreach ($errors as $error) echo '<p class="notice error">' . h(setup_error($error)) . '</p>';
    echo '<section><form method="post">' . csrf_field() . '<input type="hidden" name="installation_mode" value="local">';
    echo '<p>Дополнительный пароль установки не нужен. Далее создайте учётную запись администратора. Подтверждение email не требуется.</p>';
    field('timezone', ui('Time zone', 'Часовой пояс'), $values['timezone'] ?? 'Europe/Moscow');
    echo '<button>' . ui('Open administration', 'Перейти к настройке админки') . '</button></form><p><a href="?">' . ui('Install from GitHub instead', 'Установка из GitHub') . '</a></p></section>';
    page_end();
}
function render_repository_connection(): void {
    echo '<section id="repository"><h2>' . ui('Connect GitHub later', 'Подключить GitHub') . '</h2><p>' . ui('Optional: enable repository updates and feedback. Local account and settings are preserved. Connecting does not install a version.', 'Необязательно: включите обновления из репозитория и отправку идей. Учётная запись и настройки сохранятся. Подключение само по себе не устанавливает версию.') . '</p><details><summary>' . ui('Configure connection', 'Настроить подключение') . '</summary><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="connect_repository">';
    repository_fields([]);
    echo '<button>' . ui('Save connection', 'Сохранить подключение') . '</button></form></details></section>';
}
function diagnostic_snapshot(): string {
    if (!class_exists('NeuroSaaS\\Diagnostics', false)) return '';
    return \NeuroSaaS\Diagnostics::snapshot();
}
function render_diagnostics_page(): void {
    page_start(ui('Local diagnostics', 'Локальная диагностика'));
    $snapshot = diagnostic_snapshot();
    echo '<section><p>' . ui('Stored on this server. Nothing is sent from this page. Exports include technical locations and error types, without customer payloads or credentials.', 'Журнал хранится на этом сервере. Эта страница ничего не отправляет. В выгрузке — места и типы ошибок, без содержимого запросов и секретов.') . '</p><label for="diagnostic-log">' . ui('Technical log: select and copy', 'Технический журнал: выделите и скопируйте') . '</label><textarea id="diagnostic-log" readonly rows="18">' . h($snapshot !== '' ? $snapshot : ui('Diagnostics become available after installation.', 'Диагностика станет доступна после установки.')) . '</textarea><p><a href="?feedback=1">' . ui('Request a change', 'Заказать доработку') . '</a></p></section>';
    page_end();
}
function handle_setup_post(string $configPath): void {
    $local = ($_POST['installation_mode'] ?? '') === 'local';
    if ($local && !local_upload_available()) { http_response_code(422); render_setup_form([], ['Upload the complete archive before choosing local installation.']); return; }
    $repo     = trim((string)($_POST['repo']     ?? ''));
    $branch   = trim((string)($_POST['branch']   ?? 'main'));
    $subdir   = trim((string)($_POST['subdir']   ?? '.'));
    $ghToken  = (string)($_POST['gh_token'] ?? '');
    $timezone = trim((string)($_POST['timezone'] ?? 'UTC'));
    $keepRaw  = (string)($_POST['keep_files'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $purge    = isset($_POST['purge']);
    $prRaw    = trim((string)($_POST['pr_number'] ?? ''));
    $autoPull = isset($_POST['auto_pull']);
    $intRaw   = trim((string)($_POST['auto_interval'] ?? ''));

    $errors = [];
    if ($password !== '' && strlen($password) < 6) {
        $errors[] = $local ? ui('Use a password of 12–512 characters.', 'Используйте пароль от 12 до 512 символов.') : 'password must be at least 6 characters';
    }
    $prNumber = (int)$prRaw;
    if ($prRaw !== '' && !preg_match('~^[0-9]+$~', $prRaw)) {
        $errors[] = 'pull request must be a number (e.g. 42), or empty to track the branch';
    } elseif ($prRaw !== '' && $prNumber < 1) {
        $errors[] = 'pull request number must be 1 or greater';
    }
    // An empty PR field means branch tracking — that is the pre-existing behaviour.
    $source = $prNumber > 0 ? 'pr' : 'branch';
    // GITHUB_TOKEN from the environment counts too — it wins over the config value.
    if ($source === 'pr' && $ghToken === '' && (string)(getenv('GITHUB_TOKEN') ?: '') === '') {
        $errors[] = 'pull request tracking needs a token with Pull requests: Read — use the link above to create one';
    }
    $interval = $intRaw === '' ? AUTO_INTERVAL_DEF : (int)$intRaw;
    if ($interval < AUTO_INTERVAL_MIN) {
        $errors[] = 'check interval must be at least ' . AUTO_INTERVAL_MIN . ' seconds';
    }
    if (!$local && ($repo === '' || !preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~', $repo))) {
        $errors[] = 'repo must look like "owner/name"';
    }
    if ($branch === '') $branch = 'main';
    if ($subdir === '') $subdir = '.';
    if ($timezone === '' || @timezone_open($timezone) === false) {
        $errors[] = 'invalid timezone (e.g. "Europe/Moscow", "UTC")';
    }

    $keepFiles = [];
    foreach (preg_split('/[\s,]+/', $keepRaw) ?: [] as $name) {
        $name = trim($name);
        if ($name !== '') $keepFiles[] = $name;
    }
    foreach (ALWAYS_KEEP as $must) {
        if (!in_array($must, $keepFiles, true)) $keepFiles[] = $must;
    }

    if ($errors) {
        render_setup_form([
            'installation_mode' => $local ? 'local' : 'github',
            'repo' => $repo, 'branch' => $branch, 'subdir' => $subdir,
            'purge' => $purge,
            'timezone' => $timezone, 'keep_files' => implode(', ', $keepFiles),
            'pr_number' => $prRaw, 'auto_pull' => $autoPull, 'auto_interval' => $interval,
        ], $errors);
        return;
    }

    $config = [
        'installation_mode' => $local ? 'local' : 'github',
        'repo'          => $local ? '' : $repo,
        'branch'        => $branch,
        'subdir'        => $subdir,
        'gh_token'      => $local ? '' : $ghToken,
        'keep_files'    => $keepFiles,
        'timezone'      => $timezone,
        'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : '',
        'bootstrap_session' => $password === '' ? hash('sha256',session_id()) : '',
        'purge'         => !$local && $purge,
        'source'        => $local ? 'branch' : $source,
        'pr_number'     => $local ? 0 : $prNumber,
        'auto_pull'     => !$local && $autoPull,
        'auto_interval' => $interval,
    ];

    if (!save_php_config($configPath, $config)) {
        render_setup_form([
            'installation_mode' => $local ? 'local' : 'github',
            'repo' => $repo, 'branch' => $branch, 'subdir' => $subdir,
            'purge' => $purge,
            'timezone' => $timezone, 'keep_files' => implode(', ', $keepFiles),
            'pr_number' => $prRaw, 'auto_pull' => $autoPull, 'auto_interval' => $interval,
        ], ['cannot write pull-config.php — check directory permissions']);
        return;
    }

    @chmod($configPath, 0600);
    render_setup_done($config);
}

// ---------- friendly, bilingual control panel ----------
function ui(string $en, string $ru): string { return ($GLOBALS['pull_language'] ?? 'en') === 'ru' ? $ru : $en; }
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function init_panel(): void {
    if (PHP_SAPI !== 'cli') {
        session_name('pull_panel');
        session_set_cookie_params(['httponly' => true, 'secure' => auth_is_https(), 'samesite' => 'Lax', 'path' => auth_cookie_path()]);
        if (!@session_start()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Cannot start a secure session. Ask your hosting provider to enable a writable PHP session directory. / Не удалось открыть защищённую сессию. Попросите хостинг проверить папку сессий PHP.');
        }
    }
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['ru', 'en'], true)) $_SESSION['pull_language'] = $_GET['lang'];
    $country = strtoupper((string)($_SERVER['GEOIP_COUNTRY_CODE'] ?? getenv('GEOIP_COUNTRY_CODE') ?: ''));
    if ($country === '' && getenv('PULL_TRUST_CLOUDFLARE') === '1') $country = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if ($country === '' && function_exists('geoip_country_code_by_name')) $country = (string)@geoip_country_code_by_name((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $GLOBALS['pull_language'] = $_SESSION['pull_language'] ?? 'ru';
    if (empty($_SESSION['pull_csrf'])) $_SESSION['pull_csrf'] = bin2hex(random_bytes(24));
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['pull_csrf']) . '">'; }
function check_csrf(): void {
    if (!hash_equals((string)($_SESSION['pull_csrf'] ?? ''), (string)($_POST['csrf'] ?? '')) || empty($_SESSION['pull_csrf'])) {
        http_response_code(403);
        page_start(ui('Please reload the page', 'Обновите страницу'));
        echo '<p>' . ui('This form has expired. Reload the page and try again.', 'Форма устарела. Обновите страницу и повторите действие.') . '</p>';
        page_end(); exit;
    }
}
function feedback_dialog_assets(): string {
    if (!is_file(PULL_ROOT.'/assets/feedback-dialog.js')) return '';
    $base = defined('NEUROSAAS_ADMIN') ? '../assets/' : 'assets/';
    return '<link rel="stylesheet" href="'.$base.'feedback-dialog.css"><script defer src="'.$base.'feedback-dialog.js"></script>';
}
function page_start(string $title): void {
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin'); header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="' . h($GLOBALS['pull_language']) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . h($title) . '</title>' . terminal_css() . feedback_dialog_assets() . '</head><body><main><header><a class="brand" href="?">' . ui('Your service', 'Ваш сервис') . ' <small>' . ui('control panel', 'панель управления') . '</small></a><nav><a href="?feedback=1">Заказать доработку</a> · <a href="?lang=ru">Русский</a> · <a href="?lang=en">English</a> · <a href="' . (defined('NEUROSAAS_ADMIN') ? '../' : './') . '">' . ui('Open service', 'Открыть сервис') . '</a></nav></header><h1>' . h($title) . '</h1>';
}
function page_end(): void {

    echo '<footer>' . ui('Need advice or a custom improvement?', 'Нужна консультация или доработка?') . ' <a href="https://dansury.github.io" target="_blank" rel="noopener">dansury.github.io</a> · <a href="https://t.me/dansury.vj" target="_blank" rel="noopener">Telegram: dansury.vj</a></footer></main></body></html>';
}
function terminal_css(): string {
    return <<<'CSS'
<style>
:root{color-scheme:light;--ink:#17243b;--muted:#56657a;--accent:#171717;--border:#d9e1ed}
*{box-sizing:border-box}body{margin:0;background:#fff;color:var(--ink);font:16px/1.6 system-ui,-apple-system,sans-serif}main{max-width:1040px;margin:auto;padding:28px 22px}header{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:38px}.brand{font-size:23px;font-weight:750;text-decoration:none}.brand small{display:block;color:var(--muted);font-size:12px;font-weight:500}a{color:var(--accent)}nav{font-size:14px}h1{font-size:32px;line-height:1.2}h2{font-size:22px;margin-top:0}h3{font-size:18px}section,.card{background:white;border:1px solid var(--border);border-radius:16px;padding:26px;margin:20px 0;box-shadow:0 5px 20px #1b355507}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px}.grid section{margin:0}p,.help{color:var(--muted)}label{display:block;font-weight:600;margin:16px 0 4px}label.check{display:flex;align-items:flex-start;gap:10px;font-weight:500}input:not([type=checkbox]),textarea,select{font:inherit;width:100%;padding:10px 12px;border:1px solid #bcc9dc;border-radius:8px;background:white;color:var(--ink)}input[type=checkbox]{width:18px;height:18px;margin-top:4px}input:focus,textarea:focus,select:focus{outline:3px solid #aaa;outline-offset:1px}.help{font-size:14px;margin:5px 0 14px}button,.button{display:inline-block;font:inherit;font-weight:600;background:var(--accent);color:white;border:0;border-radius:9px;padding:11px 18px;cursor:pointer;text-decoration:none;margin:12px 8px 0 0}button:disabled{opacity:.5;cursor:wait}button.secondary{background:#f1f1f1;color:#171717}details{border:1px solid var(--border);border-radius:10px;padding:16px;margin:16px 0}summary{cursor:pointer;font-weight:650}pre,code{overflow-wrap:anywhere;white-space:pre-wrap;font-size:13px}pre{background:#f3f6fb;padding:18px;border-radius:9px;max-height:400px;overflow:auto}.notice{border-left:4px solid var(--accent);background:#f5f5f5;padding:14px 18px;border-radius:7px}.error{border-color:#bb304b;background:#fff0f3;color:#86283b}.success{border-color:#14805d;background:#eaf8f2;color:#115c45}.status{font-size:20px;font-weight:650}footer{border-top:1px solid var(--border);margin-top:36px;padding:22px 0;color:var(--muted);font-size:14px}.row{padding:15px 0;border-bottom:1px solid var(--border)}.inline{display:inline-block;margin-right:10px}[hidden]{display:none!important}@media(max-width:600px){main{padding:18px 14px}section,.card{padding:19px}h1{font-size:27px}}
.open-service{animation:service-pulse 1.5s ease-in-out infinite}@keyframes service-pulse{50%{box-shadow:0 0 0 9px #00000020;transform:scale(1.025)}}@media(prefers-reduced-motion:reduce){.open-service{animation:none}}
</style>
CSS;
}
function field(string $name, string $label, string $value, string $help = '', string $type = 'text'): void {
    echo '<label for="f_' . h($name) . '">' . h($label) . '</label>';
    if ($type === 'textarea') echo '<textarea rows="5" id="f_' . h($name) . '" name="' . h($name) . '">' . h($value) . '</textarea>';
    else echo '<input id="f_' . h($name) . '" name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"' . ($type === 'password' ? ' autocomplete="new-password"' : '') . '>';
    if ($help !== '') echo '<p class="help">' . h($help) . '</p>';
}
function checkbox(string $name, string $label, bool $checked): void {
    echo '<label class="check"><input type="checkbox" name="' . h($name) . '" value="1"' . ($checked ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
}
function render_login_form(array $errors = []): void {
    page_start(ui('Sign in to manage your service', 'Вход в управление сервисом'));
    foreach ($errors as $e) echo '<p class="notice error">' . ui('Password required or incorrect. Please try again.', 'Введите пароль. Если пароль неверный, попробуйте ещё раз.') . '</p>';
    echo '<section><p>' . ui('Use the password chosen when this server was set up.', 'Используйте пароль, который указали при настройке этого сервера.') . '</p><form method="post">' . csrf_field();
    field('password', ui('Password', 'Пароль'), '', '', 'password');
    checkbox('remember', ui('Remember me for 30 days on this device', 'Запомнить меня на этом устройстве на 30 дней'), false);
    echo '<button>' . ui('Sign in', 'Войти') . '</button></form><p><a href="?account=reset">Забыли пароль?</a></p></section>'; page_end();
}
function render_setup_form(array $values = [], array $errors = []): void {
    if (local_upload_available() && (($values['installation_mode'] ?? $_GET['install'] ?? '') === 'local')) {
        render_local_setup($values, $errors); return;
    }
    page_start(ui('Connect your GitHub repository', 'Подключите репозиторий GitHub'));
    render_workflow_diagram();
    echo '<p>' . ui('This assistant copies your project to this server. Start with the repository and access key; each setting is explained below.', 'Этот помощник переносит проект на сервер. Начните с репозитория и ключа доступа — у каждого параметра есть пояснение.') . '</p>';
    foreach ($errors as $error) echo '<p class="notice error">' . h(setup_error($error)) . '</p>';
    if (local_upload_available()) echo '<section><h2>' . ui('Already uploaded all files?', 'Уже загрузили все файлы?') . '</h2><p>' . ui('Use the same administration without a repository or GitHub key.', 'Откройте ту же админку без репозитория и ключа GitHub.') . '</p><a class="button secondary" href="?install=local">' . ui('Files are already uploaded', 'Файлы уже загружены — настроить без GitHub') . '</a></section>';
    echo '<section><form method="post">' . csrf_field();
    repository_fields($values);
    checkbox('auto_pull', ui('Automatically install updates while the panel is open', 'Автоматически устанавливать обновления, пока панель открыта'), (bool)($values['auto_pull'] ?? true));
    field('auto_interval', ui('Check every, seconds', 'Проверять каждые, секунд'), (string)($values['auto_interval'] ?? 60), ui('Minimum 15 seconds. 60 seconds is a good starting point.', 'Минимум 15 секунд. Для начала подойдёт 60.'), 'number');
    echo '<details><summary>' . ui('Advanced settings', 'Дополнительные настройки') . '</summary>';
    field('subdir', ui('Project folder in repository', 'Папка проекта в репозитории'), $values['subdir'] ?? '.', ui('A dot means the entire repository; otherwise use a path such as public.', 'Точка означает весь репозиторий. Для отдельной папки укажите путь, например public.'));
    field('pr_number', ui('Preview pull request number', 'Номер pull request для предпросмотра'), (string)($values['pr_number'] ?? ''), ui('Optional: a pull request (PR) proposes changes before merge into the main branch. Use it to test a developer fix before release, preferably in a separate test installation: this installs unfinished code and replaces files here. Open Pull requests in your GitHub repository and the desired PR. For a URL ending in /pull/42, enter 42. For normal installation and updates, leave empty to follow the selected branch. Private repositories need Pull requests: Read permission. After testing, merge or closure, clear pr_number in pull-config.php to return to branch updates.', 'Необязательно. Pull request (PR) — предложенные изменения, ещё не добавленные в основную ветку. Поле нужно, чтобы проверить исправление разработчика до выпуска. Лучше использовать отдельную тестовую установку: предпросмотр устанавливает незавершённый код и заменяет файлы здесь. В своём репозитории на GitHub откройте Pull requests и нужный PR. Если ссылка заканчивается на /pull/42, введите 42. Для обычной установки и обновлений оставьте поле пустым: будет использоваться выбранная ветка. Для закрытого репозитория ключу нужно право Pull requests: Read. После проверки, слияния или закрытия PR очистите pr_number в pull-config.php для возврата к обновлениям из ветки.'));
    field('timezone', ui('Time zone', 'Часовой пояс'), $values['timezone'] ?? 'Europe/Moscow', ui('For example Europe/Moscow or UTC.', 'Например Europe/Moscow или UTC.'));
    field('keep_files', ui('Files and folders to preserve', 'Файлы и папки, которые нужно сохранить'), $values['keep_files'] ?? '', ui('Separate names with commas: uploads, data, .env. Panel settings and keys are always preserved.', 'Разделяйте имена запятыми: uploads, data, .env. Настройки панели и ключи всегда сохраняются.'));
    checkbox('purge', ui('Delete server files that no longer exist in the repository', 'Удалять с сервера файлы, которых больше нет в репозитории'), (bool)($values['purge'] ?? false));
    echo '<p class="help">' . ui('Deletion is permanent. Preserve uploads, data and local secrets above first.', 'Удаление необратимо. Сначала укажите выше папки загрузок, данных и локальные секреты.') . '</p></details><button>' . ui('Save and continue', 'Сохранить и продолжить') . '</button></form></section>';
    page_end();
}
function setup_error(string $message): string {
    $map = [
        'password must be at least 6 characters (or empty for no password)' => 'Пароль должен содержать не менее 6 символов (или оставьте пустым).',
        'repo must look like "owner/name"' => 'Укажите репозиторий в формате owner/name.',
        'invalid timezone (e.g. "Europe/Moscow", "UTC")' => 'Укажите правильный часовой пояс, например Europe/Moscow или UTC.',
        'pull request must be a number (e.g. 42), or empty to track the branch' => 'Номер pull request должен быть числом, например 42. Для ветки оставьте пустым.',
        'pull request number must be 1 or greater' => 'Номер pull request должен быть больше нуля.',
        'pull request tracking needs a token with Pull requests: Read — use the link above to create one' => 'Для предпросмотра нужен ключ с правом Pull requests: Read. Создайте его по ссылке выше.',
        'cannot write pull-config.php — check directory permissions' => 'Не удалось сохранить настройки. Проверьте права записи в папку на хостинге.',
    ];
    if (strpos($message, 'check interval must') === 0) return ui($message, 'Интервал проверки должен быть не менее 15 секунд.');
    return ui($message, $map[$message] ?? $message);
}
function render_setup_done(array $config): void {
    if (is_local_install($config)) { page_start(ui('Local setup saved', 'Локальная установка настроена')); echo '<section><p>' . ui('Files are ready. Create the administrator account without email confirmation.', 'Файлы готовы. Создайте учётную запись администратора без подтверждения email.') . '</p><a class="button" href="?">' . ui('Continue to administration', 'Продолжить настройку админки') . '</a><p><a href="../">' . ui('Open site', 'Открыть сайт') . '</a></p></section>'; page_end(); return; }
    page_start(ui('Your connection is ready', 'Подключение настроено'));
    echo '<section><p>' . ui('Settings saved. Open the panel to install your first version and set up AI connections.', 'Настройки сохранены. Откройте панель, чтобы установить первую версию и подключить нейросети.') . '</p><a class="button" href="?">' . ui('Open control panel', 'Открыть панель') . '</a></section>'; page_end();
}
function start_output(bool $plain): void {
    $GLOBALS['TERM_PLAIN'] = $plain;
    if ($plain) { header('Content-Type: text/plain; charset=utf-8'); return; }
    page_start(ui('Updating your service', 'Обновление сервиса'));
    echo '<section><progress data-deploy-progress max="12" value="0" style="width:100%"></progress><p role="status" data-deploy-step>Подготовка…</p><p data-deploy-result role="status" hidden></p><p>' . ui('Please wait. Your files are being updated. The result will appear below.', 'Подождите: файлы сервиса обновляются. Результат появится ниже.') . '</p><details><summary>' . ui('Technical details for support', 'Технические сведения для поддержки') . '</summary><pre>';
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}
function term(string $msg): void {
    echo !empty($GLOBALS['TERM_PLAIN']) ? $msg . "\n" : h($msg) . "\n";
    if (empty($GLOBALS['TERM_PLAIN'])) echo '<script>document.querySelector("[data-deploy-step]").textContent=' . json_encode($msg, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';var p=document.querySelector("[data-deploy-progress]");p.value=Math.min(p.max,p.value+1)</script>';
    if (empty($GLOBALS['TERM_PLAIN']) && strpos($msg,'STATUS:')===0) echo '<script>var r=document.querySelector("[data-deploy-result]");r.hidden=false;r.className="notice ' . (strpos($msg,'DONE')!==false?'success':'error') . '";r.textContent=' . json_encode($msg, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';</script>';
    if (ob_get_level()) @ob_flush(); flush();
}
function end_output(): void { if (empty($GLOBALS['TERM_PLAIN'])) { echo '</pre></details><a class="button" href="?">' . ui('Back to panel', 'Вернуться в панель') . '</a></section>'; page_end(); } }
function render_history_page(array $config): void {
    page_start(ui('Previous versions', 'Предыдущие версии'));
    echo '<p>' . ui('Restore a version that was previously installed. Automatic updates pause after a restore; install the latest version to resume.', 'Можно вернуть ранее установленную версию. Автообновление после возврата приостанавливается. Чтобы возобновить его, установите актуальную версию.') . '</p><section>';
    $state = state_read(); $versions = history_versions($state);
    if (!$versions) echo '<p>' . ui('No versions installed yet.', 'Пока нет установленных версий.') . '</p>';
    foreach ($versions as $entry) {
        echo '<div class="row"><strong>' . h($entry['at'] ?? '') . '</strong> · ' . h(short_sha($entry['sha'])) . ' · ' . h($entry['label'] ?? '');
        if (!empty($entry['pr_summary'])) echo '<p>' . nl2br(h($entry['pr_summary'])) . '</p>';
        if ($entry['sha'] === ($state['sha'] ?? '')) echo ' — ' . ui('Installed now', 'Установлена сейчас');
        else echo '<form method="post" action="?" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="deploy"><input type="hidden" name="' . (($entry['mode'] ?? '') === 'archive' ? 'rollback_archive' : 'rollback') . '" value="' . h($entry['sha']) . '"><button class="secondary">' . ui('Restore this version', 'Вернуть эту версию') . '</button></form>';
        echo '</div>';
    }
    echo '<p class="notice">После отката попросите нейросеть: «Восстанови файлы коммита ' . h((string)($state['mode'] ?? '') === 'archive' ? 'из выбранного ZIP-архива (его хеш ' . (string)($state['sha'] ?? '') . ')' : (string)($state['sha'] ?? '')) . ' новым коммитом в main через PR. Сохрани последующую историю; не меняй главную ветку и не делай force push».</p>';
    echo '</section><a href="?">' . ui('Back to panel', 'Вернуться в панель') . '</a>'; page_end();
}
function render_watch_page(array $config): void { render_dashboard($config); }
/** Original owner-supplied workflow; standalone installers retain a remote fallback. */
function render_workflow_diagram(): void {
    $local = 'admin/deployment/workflow.png';
    $src = is_file(PULL_ROOT . '/' . $local)
        ? (defined('NEUROSAAS_ADMIN') ? '../' : '') . $local
        : 'https://github.com/user-attachments/assets/d23f766f-42b6-494d-850f-00e0d38ee289';
    $caption = ui('Request a change → GitHub Issues → run an AI agent and merge its PR → GitHub code → update the service through pull.php.', '«Заказать доработку» → GitHub Issues → запуск ИИ-агента и слияние PR → код на GitHub → обновление сервиса через pull.php.');
    echo '<section class="workflow-diagram"><h2>' . ui('How improvements reach your service', 'Как доработки попадают в сервис') . '</h2><figure style="margin:0;padding:13px;background:#fff;color:#171717;min-width:0"><img src="' . h($src) . '" width="3228" height="1620" loading="lazy" decoding="async" style="display:block;width:100%;max-width:100%;height:auto" alt="' . h($caption) . '"><figcaption style="margin-top:13px;line-height:1.6">' . h($caption) . '</figcaption></figure></section>';
}

function render_dashboard(array $config, string $message = '', bool $error = false): void {
    page_start(ui('Administration', 'Управление сервисом'));
    render_workflow_diagram();
    echo '<p><a href="?webapp=1">Приложение и уведомления</a></p>';
    echo '<p><a href="?diagnostics=1">' . ui('Local diagnostics', 'Локальная диагностика') . '</a></p>';
    if (is_local_install($config)) echo '<p class="notice">' . ui('Local installation: files uploaded manually. GitHub checks are off.', 'Локальная установка: файлы загружены вручную. Проверки GitHub выключены.') . '</p>';
    if (!is_local_install($config)) echo '<p>' . h($config['repo']) . ' · ' . h($config['source'] === 'pr' ? 'PR #' . $config['pr_number'] : $config['branch']) . '</p>';
    if ($message !== '') echo '<p role="status" class="notice ' . ($error ? 'error' : 'success') . '">' . h($message) . '</p>';
    if (is_local_install($config)) {
        echo '<section><h2>' . ui('Updates', 'Обновления') . '</h2><p id="status">' . ui('Update files manually or connect GitHub below.', 'Загружайте обновления вручную или подключите GitHub ниже.') . '</p></section>';
        if (!empty(state_read()['sha'])) echo '<p><a href="?history=1">Предыдущие версии и откат</a></p>';
        render_repository_connection();
    } else {
    echo '<section><h2>' . ui('Updates', 'Обновления') . '</h2><p id="status" class="status" role="status">' . ui('Checking for updates…', 'Проверяем обновления…') . '</p><p id="version"></p><form method="post" action="?">' . csrf_field() . '<input type="hidden" name="action" value="deploy"><button>' . ui('Update', 'Обновить') . '</button><a href="?history=1">' . ui('Previous versions', 'Предыдущие версии') . '</a></form><details><summary>' . ui('Automatic update settings', 'Настройки автообновления') . '</summary><form method="post" action="?">' . csrf_field() . '<input type="hidden" name="action" value="auto">';
    checkbox('auto_pull', ui('Install new versions while this panel is open', 'Устанавливать новые версии, пока панель открыта'), $config['auto_pull']);
    field('auto_interval', ui('Interval in seconds', 'Интервал в секундах'), (string)$config['auto_interval'], ui('At least 15. Closing this page stops browser checks.', 'Не менее 15. После закрытия страницы проверки из браузера прекращаются.'), 'number');
    echo '<button class="secondary">' . ui('Save settings', 'Сохранить настройки') . '</button></form><p class="help">' . ui('For updates with the browser closed, ask your hosting provider to schedule the following cron command once a minute. If a password is set, pass it through the X-Pull-Password header.', 'Для обновлений при закрытом браузере попросите хостинг запускать эту команду cron раз в минуту. Если задан пароль, передайте его в заголовке X-Pull-Password.') . '</p><code>curl --fail -s -H "X-Pull-Password: YOUR_PANEL_PASSWORD" "https://YOUR_HOST/PATH/pull.php?check=1&amp;plain=1"</code></details></section>';
    }
    echo '<section><h2>Загрузить ZIP-архив</h2><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="archive"><label>Архив проекта<input type="file" name="archive" accept=".zip" required></label><p class="help">Настройки и данные сохраняются. Текущую версию можно вернуть через историю.</p><button>Установить архив</button></form></section>';
    echo '<section><h2>' . ui('Models, documents and mail', 'Модели, документы и почта') . '</h2><p><a class="button" href="?toolkit=1">' . ui('Open advanced settings', 'Открыть расширенные настройки') . '</a></p></section>';
    echo '<section id="security"><h2>' . ui('Panel password', 'Пароль панели') . '</h2><p>' . ui('Replace the password without exposing the current one.', 'Замените пароль, не раскрывая текущий.') . '</p><form method="post" action="?">' . csrf_field() . '<input type="hidden" name="action" value="password">';
    field('new_password', ui('New password', 'Новый пароль'), '', ui('At least 12 characters. It is saved only as a hash.', 'Не менее 12 символов. На сервере сохраняется только хеш.'), 'password');
    field('new_password_confirm', ui('Repeat new password', 'Повторите новый пароль'), '', '', 'password');
    echo '<button class="secondary">' . ui('Replace password', 'Заменить пароль') . '</button></form></section>';
    feedback_form();
    render_ai_settings();
    $js = ['local' => is_local_install($config), 'csrf' => $_SESSION['pull_csrf'], 'interval' => $config['auto_interval'], 'auto' => $config['auto_pull'], 'ru' => $GLOBALS['pull_language'] === 'ru'];
    echo '<script>window.panelConfig=' . json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>' . panel_js();
    page_end();
}
function feedback_form(): void {
    global $config;
    $repo = (string)($config['repo'] ?? '');
    $issuesUrl = preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~', $repo) ? 'https://github.com/' . $repo . '/issues' : '';
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    echo '<section id="feedback"><h2>' . ui('Ideas for improvement', 'Идеи для доработки') . '</h2><p>' . ui('Your message becomes an issue in the connected GitHub repository. Do not include passwords or private customer information.', 'Сообщение станет задачей в подключённом репозитории GitHub. Не указывайте пароли и личные данные клиентов.') . '</p>';
    if ($issuesUrl !== '') echo '<p><a target="_blank" rel="noopener" href="' . h($issuesUrl) . '">' . ui('Open repository issues', 'Открыть все задачи репозитория') . '</a></p>';
    echo '<form method="post" enctype="multipart/form-data" action="?feedback=1">' . csrf_field() . '<input type="hidden" name="action" value="feedback">';
    field('title', ui('Title (optional)', 'Название (необязательно)'), $title, ui('Leave empty to use a dated title.', 'Можно не заполнять: будет подставлено название с датой.'));
    field('description', ui('What should change?', 'Что нужно изменить?'), $description, ui('10–10,000 characters. Do not include secrets or customer personal data.', 'От 10 до 10 000 символов. Не указывайте секреты и персональные данные клиентов.'), 'textarea');
    $snapshot = diagnostic_snapshot();
    if ($snapshot !== '') {
        $id = bin2hex(random_bytes(16));
        $snapshots = $_SESSION['diagnostic_snapshots'] ?? [];
        $snapshots[$id] = $snapshot;
        $_SESSION['diagnostic_snapshots'] = array_slice($snapshots, -5, null, true);
        checkbox('attach_diagnostics', ui('Attach the technical log shown below', 'Приложить технический журнал ниже'), isset($_POST['attach_diagnostics']));
        echo '<input type="hidden" name="diagnostic_snapshot" value="' . h($id) . '"><p class="help">' . ui('Optional. Only this preview is sent with your idea. It contains error types and code locations, without request contents, customer data or keys.', 'Необязательно. Вместе с идеей будет отправлен только этот снимок: типы ошибок и места в коде, без содержимого запросов, данных клиентов и ключей.') . '</p><details><summary>' . ui('Preview diagnostic attachment', 'Посмотреть прикладываемый журнал') . '</summary><textarea readonly rows="10" aria-label="' . ui('Diagnostic attachment preview', 'Предпросмотр журнала') . '">' . h($snapshot) . '</textarea></details>';
    }
    if (is_local_install($config ?? [])) echo '<p class="notice">' . ui('Connect GitHub in the panel before sending. The text and log remain local until you submit.', 'Войдите в GitHub кнопкой выше или попросите администратора настроить серверный ключ обращений и FEEDBACK_GITHUB_REPO. Отправка выполняется только кнопкой ниже.') . '</p>';
    feedback_media_controls();
    echo '<button>' . ui('Send idea', 'Отправить идею') . '</button></form></section>';
}
function render_feedback(string $message = '', bool $error = false, string $url = '', bool $celebrate = false, bool $tokenHelp = false): void {
    global $config;
    $repo = (string)($config['repo'] ?? '');
    $issuesUrl = preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~', $repo) ? 'https://github.com/' . $repo . '/issues' : '';

    page_start(ui('Ideas for improvement', 'Идеи для доработки'));
    if (is_file(PULL_ROOT.'/admin/deployment/feedback-auth.php')) { require_once PULL_ROOT.'/admin/deployment/feedback-auth.php'; FeedbackAuth::controls(); }
    if ($message !== '') echo '<p role="status" class="notice ' . ($error ? 'error' : 'success') . '">' . h($message) . ($url !== '' ? ' <a target="_blank" rel="noopener" href="' . h($url) . '">' . ui('Open created issue', 'Открыть созданную задачу') . '</a>' : '') . '</p>';
    if ($tokenHelp && $repo !== '') echo '<p class="help"><a target="_blank" rel="noopener" href="' . h(pat_url($repo, ($config['source'] ?? '') === 'pr')) . '">' . ui('Create or replace the GitHub access key', 'Создать или заменить ключ доступа GitHub') . '</a></p>';
    if ($celebrate && $issuesUrl !== '') {
        $prompt = 'resolve ' . $issuesUrl;
        echo '<section class="success"><h2>' . ui('Your first idea is in the repository!', 'Первая идея уже в репозитории!') . '</h2><p>' . ui('You can send another idea or give an AI coding assistant this prompt:', 'Можно отправить ещё одну идею или передать AI-агенту эту команду:') . '</p><pre>' . h($prompt) . '</pre><a class="button secondary" target="_blank" rel="noopener" href="' . h($issuesUrl) . '">' . ui('Open issue list', 'Открыть список задач') . '</a></section>';
    }
    feedback_form();
    page_end();
}
function send_feedback(array $config, string $token): void {
    require_once PULL_ROOT.'/admin/deployment/feedback-auth.php';
    try { $token = FeedbackAuth::token($token); } catch (Throwable $ex) { render_feedback($ex->getMessage(), true); return; }
    if (($config['repo'] ?? '') === '') $config['repo'] = (string)getenv('FEEDBACK_GITHUB_REPO');
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['description'] ?? ''));
    if ($title === '') $title = 'Идея для доработки — ' . date('Y-m-d H:i');
    $length = static fn(string $v): int => preg_match_all('/./us', $v) ?: 0;
    if ($length($title) > 200 || $length($body) < 10 || $length($body) > 10000) {
        http_response_code(422);
        render_feedback(ui('Check the title and description lengths shown below.', 'Проверьте длину названия и описания: ограничения указаны под полями.'), true);
        return;
    }
    if (is_local_install($config) || !preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~D', $config['repo'])) {
        http_response_code(422);
        render_feedback(ui('Connect your GitHub repository in the panel before sending.', 'Перед отправкой подключите свой репозиторий GitHub в панели.'), true); return;
    }
    if ($token === '') {
        http_response_code(422);
        render_feedback(ui('Add a GitHub key with Issues: Read and write to pull-config.php or GITHUB_TOKEN on your server.', 'Для отправки этой формой подключите файл feedback.key в блоке выше или задайте серверный FEEDBACK_GITHUB_TOKEN с правом Issues: write. Кнопка входа позволяет создать задачу непосредственно на GitHub.'), true, '', false, true);
        return;
    }
    if (time() - (int)($_SESSION['feedback_at'] ?? 0) < 60) {
        http_response_code(429);
        render_feedback(ui('Please wait a minute before sending another message.', 'Подождите минуту перед следующим сообщением.'), true);
        return;
    }
    if (isset($_POST['attach_diagnostics'])) {
        $id = (string)($_POST['diagnostic_snapshot'] ?? '');
        $snapshot = $_SESSION['diagnostic_snapshots'][$id] ?? null;
        if (!is_string($snapshot) || strlen($snapshot) > 14000) {
            http_response_code(422);
            render_feedback(ui('Reload and review the diagnostic attachment before sending.', 'Обновите страницу и проверьте журнал перед отправкой.'), true); return;
        }
        $body .= "\n\n### Диагностика — приложена пользователем\n\n```text\n" . str_replace("`", "'", $snapshot) . "\n```";
    }
    try { $body .= feedback_screenshots($config['repo'], $token); } catch (Throwable $e) { http_response_code(422); render_feedback($e->getMessage(),true); return; }
    // A failed request also starts the cooldown, avoiding accidental repeated submissions.
    $_SESSION['feedback_at'] = time();
    [$code, $data] = github_create_feedback($config['repo'], $token, $title, $body);
    $url = (string)($data['html_url'] ?? '');
    if ($code !== 201 || strpos($url, 'https://github.com/' . $config['repo'] . '/issues/') !== 0) {
        http_response_code(502);
        render_feedback(ui('Could not send feedback (GitHub HTTP ', 'Не удалось отправить отзыв (GitHub HTTP ') . $code . '). ' . ui('Check the key, repository access, Issues: Read and write permission, and that Issues are enabled. Retry after a minute.', 'Проверьте ключ, доступ к репозиторию, право Issues: Read and write и включены ли Issues. Повторите через минуту.'), true, '', false, true);
        return;
    }
    $celebrate = empty($_SESSION['feedback_welcome_shown']);
    $_SESSION['feedback_welcome_shown'] = true;
    unset($_POST['title'], $_POST['description']);
    render_feedback(ui('Thank you! Your message was sent.', 'Спасибо! Идея отправлена.'), false, $url, $celebrate);
}
function github_create_feedback(string $repo, string $token, string $title, string $body): array {
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: NeuroSaaS-feedback', 'X-GitHub-Api-Version: 2022-11-28', 'Content-Type: application/json', 'Authorization: Bearer ' . $token];
    $payload = json_encode(['title' => $title, 'body' => $body . "\n\n---\nSubmitted through the service feedback form."], JSON_UNESCAPED_UNICODE);
    $url = 'https://api.github.com/repos/' . $repo . '/issues'; $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false]);
        $response = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    } else {
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0]]);
        $response = @file_get_contents($url, false, $context);
        foreach ($http_response_header ?? [] as $line) if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $code = (int)$m[1];
    }
    return [$code, json_decode((string)$response, true) ?: []];
}
function ai_config(): array {
    require_once PULL_ROOT . '/admin/llm_connector/adapter.php';
    $saved = is_file(PULL_ROOT . '/ai-config.php') ? require PULL_ROOT . '/ai-config.php' : [];
    $cfg = array_replace_recursive(LLM::defaults(), is_array($saved) ? $saved : []);
    if (isset($saved['order']) && is_array($saved['order'])) $cfg['order'] = $saved['order'];
    return $cfg;
}
function render_ai_settings(): void {
    echo '<section><h2>' . ui('AI connections', 'Подключение нейросетей') . '</h2><p>' . ui('Connect one or more providers. If the first provider fails, the next configured provider is tried.', 'Подключите одного или нескольких провайдеров. Если первый не ответит, запрос перейдёт к следующему настроенному.') . '</p>';
    echo '<p class="notice">' . ui('These providers answer requests from an already deployed service through an API. Building the service itself needs a separate interactive coding subscription such as ChatGPT, Claude or Kimi.', 'Эти нейросети отвечают по API внутри уже развёрнутого сервиса. Для разработки самого сервиса нужна отдельная интерактивная подписка — например ChatGPT, Claude или Kimi.') . '</p>';
    if (!is_file(PULL_ROOT . '/admin/llm_connector/adapter.php')) { echo '<p>' . ui('Install the latest version first to load the AI connection module.', 'Сначала установите актуальную версию, чтобы загрузить модуль подключения нейросетей.') . '</p></section>'; return; }
    $cfg = ai_config();
    echo '<form method="post" action="?">' . csrf_field() . '<input type="hidden" name="action" value="ai">';
    echo '<fieldset><legend>Основной и резервные провайдеры</legend><p class="help">При ошибке сервис попробует следующий включённый провайдер с сохранённым ключом.</p>';
    for ($i=0; $i<3; $i++) {
        echo '<label for="provider-order-'.$i.'">'.($i===0?'Основной провайдер':'Резерв '.$i).'</label><select id="provider-order-'.$i.'" name="provider_order[]"><option value="">Не использовать</option>';
        foreach (LLM::PROVIDERS as $provider) echo '<option value="'.h($provider).'"'.(($cfg['order'][$i]??'')===$provider?' selected':'').'>'.h(ucfirst($provider)).'</option>';
        echo '</select>';
    }
    echo '</fieldset>';
    $providers = ['genrouter', 'openrouter', 'yandex'];
    foreach ($providers as $provider) {
        $p = $cfg[$provider];
        echo '<details' . ($provider === 'genrouter' ? ' open' : '') . '><summary>' . h(ucfirst($provider)) . '</summary>';
        if ($provider === 'openrouter') echo '<p class="notice">' . ui('OpenRouter may not work from Russian hosting providers. Test it from this server or configure a proxy for this provider.', 'OpenRouter может не работать на российских хостингах. Проверьте доступ с этого сервера или настройте прокси для этого провайдера.') . '</p>';
        if ($provider === 'genrouter') echo '<p class="notice">' . ui('Start here: add an API key, choose a model, then save the provider order for fallback. This key is used by the deployed service.', 'Начните здесь: добавьте API-ключ, выберите модель и сохраните порядок резервирования. Этот ключ используется уже развёрнутым сервисом.') . '</p>';
        echo '<p class="help"><a target="_blank" rel="noopener" href="' . ($provider === 'yandex' ? 'https://console.yandex.cloud/' : ($provider === 'genrouter' ? 'https://genrouter.ru/?ref=d97a170843' : 'https://openrouter.ai/settings/keys')) . '">' . ui('Get an API key in the provider account', 'Получить API-ключ в кабинете провайдера') . '</a></p>';
        checkbox($provider . '_enabled', ui('Use this provider', 'Использовать провайдера'), $p['enabled']);
        field($provider . '_key', ui('API key', 'API-ключ'), '', ($p['key'] !== '' ? ui('Key saved. Leave blank to keep it.', 'Ключ сохранён. Оставьте поле пустым, чтобы его не менять.') : ui('Paste the provider API key.', 'Вставьте API-ключ провайдера.')), 'password');
        checkbox($provider . '_clear_key', ui('Remove saved key', 'Удалить сохранённый ключ'), false);
        if ($provider === 'yandex') {
            field('yandex_folder', ui('Yandex Cloud folder ID', 'ID каталога Yandex Cloud'), $p['folder'], ui('Copy folder ID from the cloud console. It is not your cloud ID.', 'Скопируйте ID каталога из консоли облака. Это не ID облака.'));
            echo '<label for="f_yandex_route">' . ui('Yandex API route', 'API Yandex') . '</label><select id="f_yandex_route" name="yandex_route"><option value="native">Foundation Models (YandexGPT)</option><option value="openai"' . ($p['route'] === 'openai' ? ' selected' : '') . '>OpenAI-compatible (Qwen / Llama / other)</option></select>';
            field('yandex_openai_base', ui('OpenAI-compatible base URL', 'Базовый URL OpenAI-совместимого API'), $p['openai_base'], ui('Default: https://ai.api.cloud.yandex.net/v1. Use this route for Qwen, Llama and other open models; YandexGPT uses the Foundation Models route above.', 'По умолчанию: https://ai.api.cloud.yandex.net/v1. Используйте этот маршрут для Qwen, Llama и других открытых моделей; для YandexGPT выберите маршрут Foundation Models выше.'));
        }
        field($provider . '_model', ui('Model identifier', 'Идентификатор модели'), $p['model'], ui('Use the exact identifier from the model catalog. Save before testing or loading the catalog.', 'Укажите точный идентификатор из каталога моделей. Сохраните настройки перед проверкой или загрузкой каталога.'));
        if ($provider === 'openrouter') {
        field($provider . '_proxy', ui('Proxy for this provider (optional)', 'Прокси для этого провайдера (необязательно)'), '', ($p['proxy'] !== '' ? ui('Proxy saved. Leave blank to keep it.', 'Прокси сохранён. Оставьте пустым, чтобы не менять.') : ui('For example http://user:password@host:port. Leave empty for a direct connection.', 'Например http://user:password@host:port. Пустое поле — прямое соединение.')), 'password');
        checkbox($provider . '_clear_proxy', ui('Remove saved proxy', 'Удалить сохранённый прокси'), false);
        }
        echo '</details>';
    }
    echo '<button>' . ui('Save AI connections', 'Сохранить подключения') . '</button></form><p class="help">' . ui('Testing sends a small paid request. Catalog loading does not generate text. Save changes first.', 'Проверка отправляет небольшой платный запрос. Загрузка каталога не генерирует текст. Сначала сохраните изменения.') . '</p><form method="post" action="?">' . csrf_field() . '<input type="hidden" name="action" value="ai_test"><label>' . ui('Provider', 'Провайдер') . '<select name="provider"><option>genrouter</option><option>openrouter</option><option>yandex</option></select></label><button class="secondary" name="operation" value="test">' . ui('Test connection', 'Проверить подключение') . '</button><button class="secondary" name="operation" value="models">' . ui('Load model catalog', 'Загрузить каталог моделей') . '</button></form></section>';
}
function save_php_config(string $path, array $config): bool {
    $temp = tempnam(dirname($path), '.pull-config-');
    if ($temp === false) return false;
    chmod($temp, 0600);
    $ok = file_put_contents($temp, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX) !== false && rename($temp, $path);
    if (is_file($temp)) unlink($temp);
    if ($ok && function_exists('opcache_invalidate')) opcache_invalidate($path, true);
    return $ok;
}
function handle_panel_post(array $config, string $token): void {
    $action = (string)($_POST['action'] ?? '');
if (in_array($action, ['github_repositories','github_branches'], true)) { repository_discovery($token); exit; }
    if ($action === 'archive' || isset($_POST['rollback_archive'])) { archive_deploy($config); exit; }
    if ($action === 'feedback_key') { require_once PULL_ROOT.'/admin/deployment/feedback-auth.php'; FeedbackAuth::importKey(); exit; }
if ($action === 'feedback_transcribe') { feedback_transcribe(); exit; }
    if ($action === 'feedback') { send_feedback($config, $token); exit; }
    if (is_local_install($config) && $action === 'auto') { http_response_code(409); render_dashboard($config, ui('Connect GitHub first.', 'Сначала подключите GitHub.'), true); exit; }
    if ($action === 'connect_repository') {
        $repo = trim((string)($_POST['repo'] ?? ''));
        $branch = trim((string)($_POST['branch'] ?? 'main'));
        $key = trim((string)($_POST['gh_token'] ?? ''));
        if (!preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~D', $repo) || $branch === '' || strlen($branch) > 200 || preg_match('/[\\x00-\\x20\\x7f]/', $branch . $key)) {
            http_response_code(422); render_dashboard($config, ui('Check repository, branch and key.', 'Проверьте репозиторий, ветку и ключ.'), true); exit;
        }
        $path = PULL_ROOT . '/pull-config.php';
        $raw = require $path;
        $next = array_replace($raw, ['installation_mode' => 'github', 'repo' => $repo, 'branch' => $branch, 'gh_token' => $key, 'source' => 'branch', 'pr_number' => 0, 'auto_pull' => true]);
        if (!save_php_config($path, $next)) { http_response_code(422); render_dashboard($config, ui('Cannot save connection.', 'Не удалось сохранить подключение.'), true); exit; }
        header('Location: ?', true, 303); exit;
    }
    $ok = true; $message = ui('Settings saved.', 'Настройки сохранены.');
    if ($action === 'auto') {
        $interval = filter_var($_POST['auto_interval'] ?? '', FILTER_VALIDATE_INT);
        if ($interval === false || $interval < AUTO_INTERVAL_MIN || $interval > 86400) { $ok = false; $message = ui('Interval must be between 15 and 86400 seconds.', 'Интервал должен быть от 15 до 86400 секунд.'); }
        else { $config['auto_pull'] = isset($_POST['auto_pull']); $config['auto_interval'] = $interval; $raw = require PULL_ROOT . '/pull-config.php'; $ok = save_php_config(PULL_ROOT . '/pull-config.php', array_replace($raw, ['auto_pull' => $config['auto_pull'], 'auto_interval' => $interval])); }
    } elseif ($action === 'password') {
        $password = (string)($_POST['new_password'] ?? '');
        $confirmation = (string)($_POST['new_password_confirm'] ?? '');
        if (strlen($password) < (defined('NEUROSAAS_ADMIN') ? 12 : 6) || strlen($password) > 512 || !hash_equals($password, $confirmation)) {
            $ok = false;
            $message = ui('Enter the same new password twice; use at least 12 characters.', 'Введите одинаковый новый пароль дважды: не менее 12 символов.');
        } else {
            $raw = require PULL_ROOT . '/pull-config.php';
            $raw['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $ok = save_php_config(PULL_ROOT . '/pull-config.php', $raw);
        }
    } elseif ($action === 'ai' || $action === 'ai_test') {
        try {
            $cfg = ai_config();
            if ($action === 'ai') {
                $order = array_values(array_filter(array_map('trim', isset($_POST['provider_order']) && is_array($_POST['provider_order']) ? $_POST['provider_order'] : explode(',', (string)($_POST['order'] ?? '')))));
            if (count($order) !== count(array_unique($order))) throw new RuntimeException('Выберите разные резервные провайдеры.');
                if (!$order || array_diff($order, LLM::PROVIDERS)) throw new RuntimeException('order');
                $cfg['order'] = array_values(array_unique($order));
                foreach (LLM::PROVIDERS as $provider) {
                    $p = &$cfg[$provider]; $p['enabled'] = isset($_POST[$provider . '_enabled']); $p['model'] = trim((string)($_POST[$provider . '_model'] ?? ''));
                    foreach (['key', 'proxy'] as $secret) {
                        $value = trim((string)($_POST[$provider . '_' . $secret] ?? ''));
                        if (isset($_POST[$provider . '_clear_' . $secret])) $p[$secret] = '';
                        elseif ($value !== '') $p[$secret] = $value;
                        if (preg_match('/[\r\n]/', $p[$secret])) throw new RuntimeException('invalid secret');
                    }
                    if ($p['proxy'] !== '' && !preg_match('~^(https?|socks5h?)://[^\s]+$~', $p['proxy'])) throw new RuntimeException('proxy');
                    if ($provider === 'yandex') {
                        $p['folder'] = trim((string)($_POST['yandex_folder'] ?? ''));
                        $p['route'] = ($_POST['yandex_route'] ?? '') === 'openai' ? 'openai' : 'native';
                        $p['openai_base'] = rtrim(trim((string)($_POST['yandex_openai_base'] ?? '')), '/');
                        if ($p['openai_base'] === '') $p['openai_base'] = 'https://ai.api.cloud.yandex.net/v1';
                        if (!preg_match('~^https://[^\\s/]+(?:/[^\\s]*)?$~', $p['openai_base'])) throw new RuntimeException('yandex endpoint');
                    }
                    unset($p);
                }
                $ok = save_php_config(PULL_ROOT . '/ai-config.php', $cfg);
                if ($ok && class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) sync_genrouter_toolkit($cfg);
            } else {
                $provider = (string)($_POST['provider'] ?? '');
                if (!in_array($provider, LLM::PROVIDERS, true)) throw new RuntimeException('provider');
                $llm = new LLM($cfg);
                if (($_POST['operation'] ?? '') === 'models') {
                    $models = $llm->models($provider);
                    page_start(ui('Available models', 'Доступные модели')); echo '<section><p>' . ui('Copy an identifier into the provider model field.', 'Скопируйте идентификатор в поле модели провайдера.') . '</p><pre>' . h(implode("\n", $models)) . '</pre><a href="?">' . ui('Back to panel', 'Вернуться в панель') . '</a></section>'; page_end(); exit;
                }
                $llm->test($provider); $message = ui('Connection successful.', 'Подключение работает.');
            }
        } catch (Throwable $e) {
            $ok = false; $message = ui('Check the provider settings, key, model, folder, provider order and network access. ', 'Проверьте настройки, ключ, модель, каталог, порядок провайдеров и доступ к сети. ');
            if ($action === 'ai_test') $message .= ui('The provider did not return a valid response.', 'Провайдер не вернул корректный ответ.');
        }
    } else { $ok = false; $message = ui('Unknown action.', 'Неизвестное действие.'); }
    if (!$ok && $message === ui('Settings saved.', 'Настройки сохранены.')) $message = ui('Could not save settings. Check server directory permissions.', 'Не удалось сохранить настройки. Проверьте права записи в папку на сервере.');
    if (!$ok) http_response_code(422);
    render_dashboard($config, $message, !$ok); exit;
}
function panel_js(): string {
    return <<<'JS'
<script>
(()=>{
 const c=window.panelConfig, t=(en,ru)=>c.ru?ru:en, status=document.querySelector('#status'),version=document.querySelector('#version');
 let busy=false, stopped=false;
 async function check(){
  if(c.local||busy||stopped)return;busy=true;
  try{
   const r=await fetch('?status=1',{cache:'no-store'});
   if(r.status===401){stopped=true;throw Error(t('Session expired. Reload and sign in.','Сессия истекла. Обновите страницу и войдите.'));}
   if(!r.ok)throw Error(t('Cannot check for updates. Try again later.','Не удалось проверить обновления. Попробуйте позже.'));
   const s=await r.json();
   if(!s.ok)throw Error(t('GitHub is unavailable. Check the repository, key and permissions.','GitHub недоступен. Проверьте репозиторий, ключ и права доступа.'));
   version.textContent=s.deployed_short?t('Installed version: ','Установленная версия: ')+s.deployed_short+' · '+s.deployed_at:t('No version installed yet.','Пока ни одна версия не установлена.');
   status.textContent=s.pinned?t('Updates paused after restoring a version.','Обновления приостановлены после возврата версии.'):s.changed?t('A new version is ready.','Доступна новая версия.'):t('Your service is up to date.','У вас актуальная версия.');
   if(s.auto&&s.changed&&!s.pinned){
    status.textContent=t('Installing the new version…','Устанавливаем новую версию…');
    const data=new URLSearchParams({action:'deploy',csrf:c.csrf,automatic:'1'});
    const deploy=await fetch('?plain=1',{method:'POST',body:data});const output=await deploy.text();
    if(!deploy.ok||!/STATUS:\s*DONE/.test(output))throw Error(t('Update failed. Use Install latest version to view details.','Обновление не удалось. Нажмите «Установить актуальную версию», чтобы увидеть подробности.'));
    status.textContent=t('Update installed.','Обновление установлено.');
   }
  }catch(e){status.textContent=e.message;}finally{busy=false;if(!stopped)setTimeout(check,c.interval*1000);}
 }
 check();
})();
</script>
JS;
}


/** Read-only GitHub discovery with browser POST/CSRF; never return the key. */
function repository_discovery(string $fallback): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $key = trim((string)($_POST['gh_token'] ?? '')) ?: $fallback;
    if ($key === '' || preg_match('/[\r\n]/',$key)) { http_response_code(422); echo json_encode(['error'=>'Вставьте ключ доступа GitHub.']); return; }
    $branch = ($_POST['action'] ?? '') === 'github_branches';
    $repo = trim((string)($_POST['repo'] ?? ''));
    if ($branch && !preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~D',$repo)) { http_response_code(422); echo json_encode(['error'=>'Выберите репозиторий.']); return; }
    $items=[];
    for ($page=1; $page<=10; $page++) {
        $path=$branch ? '/repos/'.$repo.'/branches?per_page=100&page='.$page : '/user/repos?per_page=100&sort=updated&page='.$page;
        [$data,$error]=gh_api($path,$key);
        if ($error!=='' || !is_array($data)) { http_response_code(502); echo json_encode(['error'=>'Не удалось загрузить список GitHub. Проверьте ключ и доступ к репозиторию.']); return; }
        foreach($data as $item) {
            if ($branch && isset($item['name'])) $items[]=['value'=>$item['name'],'label'=>$item['name']];
            elseif (!$branch && isset($item['full_name'])) $items[]=['value'=>$item['full_name'],'label'=>$item['full_name'],'default_branch'=>$item['default_branch'] ?? 'main'];
        }
        if(count($data)<100)break;
    }
    echo json_encode(['items'=>$items],JSON_UNESCAPED_UNICODE);
}

/** Same token-first selector for initial setup and authenticated connection. */
function repository_fields(array $values): void {
    echo '<a id="patbtn" class="button" href="'.h(pat_url((string)($values['repo'] ?? ''),true)).'" target="_blank" rel="noopener">Создать ключ доступа</a><p class="help">Выберите свой репозиторий. Права уже указаны: Contents — Read and write (скриншоты), Issues — Read and write, Pull requests — Read.</p>';
    field('gh_token','Ключ доступа GitHub','','Вставьте только ключ: репозитории и ветки загрузятся ниже. Ключ остаётся на сервере.','password');
    echo '<p data-repo-status role="status"></p><label for="f_repo">Репозиторий</label><select name="repo" id="f_repo"><option value="'.h($values['repo'] ?? '').'">'.h($values['repo'] ?? 'Вставьте ключ доступа').'</option></select><label for="f_branch">Ветка</label><select name="branch" id="f_branch"><option value="main">main</option></select>';
    echo <<<'HTML'
<script>(()=>{const f=document.currentScript.closest('form'),k=f.querySelector('[name=gh_token]'),repo=f.querySelector('[name=repo]'),branch=f.querySelector('[name=branch]'),status=f.querySelector('[data-repo-status]');let seq=0;
const request=async(action)=>{const data=new FormData();data.set('action',action);data.set('csrf',f.querySelector('[name=csrf]').value);data.set('gh_token',k.value);data.set('repo',repo.value);const res=await fetch('?',{method:'POST',body:data,credentials:'same-origin'});const d=await res.json();if(!res.ok||d.error)throw Error(d.error||'Ошибка GitHub');return d.items||[]};
async function branches(){const id=++seq;status.textContent='Загружаем ветки…';try{const items=await request('github_branches');if(id!==seq)return;branch.replaceChildren(...items.map(x=>new Option(x.label,x.value)));if(items.some(x=>x.value==='main'))branch.value='main';status.textContent='Выберите ветку и продолжите.'}catch(e){if(id===seq)status.textContent=e.message}}
k.addEventListener('change',async()=>{const id=++seq;status.textContent='Загружаем репозитории…';try{const items=await request('github_repositories');if(id!==seq)return;repo.replaceChildren(...items.map(x=>new Option(x.label,x.value)));status.textContent=items.length?'Выберите репозиторий.':'Ключ не видит репозиториев.';if(items.length)branches()}catch(e){if(id===seq)status.textContent=e.message}});repo.addEventListener('change',branches);})();</script>
HTML;
}

/** Validate every ZIP member before extraction or any live-file mutation. */
function validate_archive(ZipArchive $zip): void {
    $total=0;
    for($i=0;$i<$zip->numFiles;$i++) {
        $entry=$zip->statIndex($i); $name=str_replace('\\','/',(string)$entry['name']);
        if($name==='' || $name[0]==='/' || preg_match('~^[A-Za-z]:|(?:^|/)\.\.(?:/|$)|\x00~',$name)) throw new RuntimeException('Небезопасный путь в архиве');
        $opsys=0;$attrs=0;$zip->getExternalAttributesIndex($i,$opsys,$attrs);
        if(($attrs>>16&0170000)===0120000) throw new RuntimeException('Символические ссылки в архиве запрещены');
        $total+=(int)$entry['size'];
        if((int)$entry['size']>100*1024*1024 || $total>500*1024*1024) throw new RuntimeException('Архив слишком велик после распаковки');
    }
}
function archive_store(string $path): string {
    $sha=sha1_file($path);$dir=PULL_ROOT.'/pull-archives';
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Не удалось сохранить архив для отката');
    $data=['zip'=>base64_encode((string)file_get_contents($path))];
    if(!save_php_config($dir.'/'.$sha.'.php',$data))throw new RuntimeException('Архив отката не сохранён');
    return $sha;
}
function archive_deploy(array $config): void {
    $lock=fopen(PULL_ROOT.'/pull-deploy.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){http_response_code(409);render_dashboard($config,'Обновление уже выполняется',true);return;}
    $tmp=sys_get_temp_dir().'/neurosaas-archive-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
    $started=microtime(true);start_output(false);
    try {
        $wanted=(string)($_POST['rollback_archive'] ?? '');
        if($wanted!=='') {
            $known=false;foreach(history_versions(state_read())as$entry)if(($entry['mode']??'')==='archive'&&($entry['sha']??'')===$wanted)$known=true;
            if(!$known||!preg_match('/^[a-f0-9]{40}$/D',$wanted))throw new RuntimeException('Архив отсутствует в истории');
            $stored=require PULL_ROOT.'/pull-archives/'.$wanted.'.php';$bytes=base64_decode((string)($stored['zip']??''),true);
            if($bytes===false||sha1($bytes)!==$wanted)throw new RuntimeException('Архив отката повреждён');
            file_put_contents($tmp.'/source.zip',$bytes);$label='Восстановлен архив '.short_sha($wanted);
        } else {
            $file=$_FILES['archive']??[];
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size']??0)>100*1024*1024)throw new RuntimeException('Загрузите ZIP до 100 МБ');
            if(!move_uploaded_file((string)$file['tmp_name'],$tmp.'/source.zip'))throw new RuntimeException('Архив не загружен');
            $label=substr(basename((string)($file['name']??'archive.zip')),0,120);
        }
        term('Проверяем архив');$zip=new ZipArchive();if($zip->open($tmp.'/source.zip')!==true)throw new RuntimeException('Не удалось открыть ZIP');
        validate_archive($zip);mkdir($tmp.'/files');if(!$zip->extractTo($tmp.'/files'))throw new RuntimeException('Не удалось распаковать ZIP');$zip->close();
        $entries=array_values(array_diff(scandir($tmp.'/files'),['.','..']));
        $src=count($entries)===1&&is_dir($tmp.'/files/'.$entries[0])?$tmp.'/files/'.$entries[0]:$tmp.'/files';
        if(!is_file($src.'/index.php')&&!is_file($src.'/index.html'))throw new RuntimeException('В архиве нет index.php или index.html');
        $keep=array_values(array_unique(array_merge(ALWAYS_KEEP,(array)$config['keep_files'])));
        // Snapshot the prior manual install too, so its first update is reversible.
        if(empty(state_read()['sha'])) {
            $backup=new ZipArchive();$backup->open($tmp.'/before.zip',ZipArchive::CREATE);
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PULL_ROOT,FilesystemIterator::SKIP_DOTS));
            foreach($it as$file){$rel=substr($file->getPathname(),strlen(PULL_ROOT)+1);$skip=false;foreach($keep as$path)if($rel===$path||strpos($rel,$path.'/')===0)$skip=true;if(!$skip&&$file->isFile()&&!$file->isLink())$backup->addFile($file->getPathname(),$rel);}
            $backup->close();$before=archive_store($tmp.'/before.zip');state_record_deploy(['sha'=>$before,'mode'=>'archive','ref'=>'archive','label'=>'До первого обновления архивом','at'=>date('Y-m-d H:i:s'),'status'=>'ok'],false);
        }
        $sha=archive_store($tmp.'/source.zip');term('Копируем файлы, сохраняя настройки и данные');$copied=0;copyTree($src,PULL_ROOT,$keep,$copied);
        state_record_deploy(['sha'=>$sha,'mode'=>'archive','ref'=>'archive','label'=>$label,'at'=>date('Y-m-d H:i:s'),'status'=>'ok','files'=>$copied,'rollback'=>$wanted!==''],true);
        term('STATUS: DONE · файлов: '.$copied);term('Автообновления приостановлены до явной установки версии из GitHub');
    } catch(Throwable $e){http_response_code(422);term('STATUS: ERROR · '.$e->getMessage());}
    finally{cleanup($tmp);flock($lock,LOCK_UN);fclose($lock);end_output();}
}

/** GitHub requests for screenshots use the same user token as issue creation. */
function feedback_github(string $method,string $endpoint,string $token,?array $data=null): array {
    $ch=curl_init('https://api.github.com/'.$endpoint);
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/vnd.github+json','User-Agent: NeuroSaaS-feedback','Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false]);
    if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data));
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return [$code,json_decode((string)$body,true)?:[]];
}
function feedback_screenshots(string $repo,string $token): string {
    if (empty($_FILES['screenshots']['name']) || !array_filter((array)$_FILES['screenshots']['name'])) return '';
    require_once PULL_ROOT.'/admin/deployment/feedback-media.php';
    $host=(string)($_SERVER['HTTP_HOST']??'');
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D',$host)) throw new RuntimeException('Настройте публичный адрес панели.');
    $local=in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true);
    $base=(string)getenv('FEEDBACK_PUBLIC_BASE_URL');
    if ($base==='') $base=($local && empty($_SERVER['HTTPS'])?'http://':'https://').$host.(defined('NEUROSAAS_ADMIN')?'/admin/':'/pull.php');
    return FeedbackMedia::attach($_FILES['screenshots']??[], $base);
}
/** Audio is sent only after an explicit record/recognize action. */
function feedback_transcribe(): void {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    try {
        require_once PULL_ROOT.'/admin/deployment/audio.php';
        $cfg = require PULL_ROOT.'/admin/llm_connector/config.php';
        $f = $_FILES['audio'] ?? [];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Запись не загружена.');
        $text = RouterSpeech::transcribe($cfg, (string)$f['tmp_name'], (string)($f['type'] ?? ''), (string)($_POST['audio_model'] ?? ''));
        require_once PULL_ROOT.'/admin/deployment/speech.php';
        require_once PULL_ROOT.'/admin/llm_connector/llm.php';
        ToolkitLLM::init($cfg);
        $formatted=FeedbackSpeech::format($text,static function($prompt,$transcript){return ToolkitLLM::chatText($prompt,$transcript,null,0.0);});
        echo json_encode($formatted,JSON_UNESCAPED_UNICODE);
    } catch(Throwable $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
}
function feedback_media_controls(): void {
    if (!is_file(PULL_ROOT.'/admin/deployment/audio.php') || !is_file(PULL_ROOT.'/admin/llm_connector/config.php')) { echo '<p>Завершите установку сервиса для подключения вложений и голосового ввода.</p>'; return; }
    require_once PULL_ROOT.'/admin/deployment/audio.php';
    $cfg = require PULL_ROOT.'/admin/llm_connector/config.php';
    $models = RouterSpeech::models($cfg); $ready = (bool)$models;
    echo '<label for="feedback-audio-model">Голосовая модель</label><select id="feedback-audio-model" name="audio_model"' . ($ready ? '' : ' disabled') . '>';
    foreach ($models as $id=>$row) echo '<option value="' . h($id) . '"' . (($cfg['LLM_AUDIO_MODEL'] ?? '') === $id ? ' selected' : '') . '>' . h(ucfirst($row['provider']) . ' · ' . $row['label']) . '</option>';
    echo '</select><p class="help">Модели с поддержкой аудио из каталога провайдера. <a href="?toolkit=1" target="_blank" rel="noopener">Подключить ключ или обновить каталог GenRouter</a></p>';
    echo '<label>Скриншоты (вставьте из буфера; ссылки доступны получателям задачи 30 дней)<input type="file" name="screenshots[]" accept="image/png,image/jpeg,image/webp" multiple></label><p data-shot-status role="status"></p><button type="button" data-dictate' . ($ready ? '' : ' hidden') . '>Надиктовать</button><p data-voice-status role="status"></p>';
    echo <<<'HTML'
<script>(()=>{const f=document.currentScript.closest('form'),files=f.querySelector('[name="screenshots[]"]'),body=f.querySelector('[name=description]'),btn=f.querySelector('[data-dictate]'),status=f.querySelector('[data-voice-status]');let rec=null,timer;
f.addEventListener('paste',e=>{const images=[...e.clipboardData.files].filter(x=>x.type.startsWith('image/'));if(!images.length)return;e.preventDefault();const list=new DataTransfer();[...files.files,...images].slice(0,5).forEach(x=>list.items.add(x));files.files=list.files;f.querySelector('[data-shot-status]').textContent='Приложено изображений: '+list.files.length;});
btn.addEventListener('click',async()=>{if(rec&&rec.state==='recording'){rec.stop();return;}try{const stream=await navigator.mediaDevices.getUserMedia({audio:true}),chunks=[];rec=new MediaRecorder(stream);const started=Date.now();rec.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};rec.onstop=async()=>{clearInterval(timer);stream.getTracks().forEach(x=>x.stop());btn.disabled=true;status.textContent='Распознаём…';const data=new FormData();data.set('audio_model',f.querySelector('[name=audio_model]').value);data.set('action','feedback_transcribe');data.set('csrf',f.querySelector('[name=csrf]').value);data.set('audio',new Blob(chunks,{type:rec.mimeType}),'voice.webm');try{const res=await fetch('?',{method:'POST',body:data}),d=await res.json();if(!res.ok||d.error)throw Error(d.error||'Ошибка распознавания');body.value+=(body.value?'\n':'')+d.text;body.dispatchEvent(new Event('input',{bubbles:true}));status.textContent=d.warning||'Расшифровка добавлена.'}catch(e){status.textContent=e.message}finally{btn.disabled=false;btn.textContent='Надиктовать'}};rec.start();btn.textContent='Остановить';timer=setInterval(()=>{const left=Math.max(0,30-Math.floor((Date.now()-started)/1000));status.textContent='Осталось секунд: '+left;if(!left&&rec.state==='recording')rec.stop()},300)}catch(e){status.textContent='Для записи нужен HTTPS и доступ к микрофону.'}});})();</script>
HTML;
}


/** Keep the original host adapter and advanced toolkit on the same GenRouter key. */
function sync_genrouter_toolkit(array $cfg): void {
    require_once PULL_ROOT.'/admin/llm_connector/settings_store.php';
    $settings=require PULL_ROOT.'/admin/llm_connector/config.php';
    $store=new SettingsStore((string)$settings['DB_PATH']);
    foreach (['genrouter'=>'GENROUTER_API_KEY','openrouter'=>'OPENROUTER_API_KEY','yandex'=>'YANDEX_API_KEY'] as $provider=>$key) {
        $value=(string)($cfg[$provider]['key']??'');
        if ($value!=='' || isset($_POST[$provider.'_clear_key'])) $store->setSetting($key,$value);
    }
    if (!empty($cfg['yandex']['folder'])) $store->setSetting('YANDEX_FOLDER_ID',(string)$cfg['yandex']['folder']);
    $store->setSetting('YANDEX_FALLBACK_MODEL',(string)($cfg['yandex']['model']??''));
    if (!empty($cfg['openrouter']['model'])) $store->setSetting('LLM_FALLBACK_MODEL',(string)$cfg['openrouter']['model']);
    if(!empty($cfg['genrouter']['model'])){ $store->setSetting('GENROUTER_MODEL',(string)$cfg['genrouter']['model']); if(($cfg['order'][0]??'')==='genrouter')$store->setSetting('LLM_DEFAULT_MODEL','genrouter:'.(string)$cfg['genrouter']['model']); }
    $store->setSetting('LLM_PROVIDER_PRIORITY',implode(',',(array)$cfg['order']));
    $store->setSetting('LLM_PROVIDER',(string)($cfg['order'][0]??'genrouter'));
}

NEUROSAAS_ENGINE_20;
eval(substr($neurosaas_engine, 5));
