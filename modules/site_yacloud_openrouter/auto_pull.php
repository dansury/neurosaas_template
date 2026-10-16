<?php
/**
 * AutoPull — silent "is the deployed code still the latest?" check on every page.
 * spec: spec/auto_pull.md — canonical source: repository site_yacloud_openrouter.
 *
 * During active development the operator ticks one checkbox in the admin panel and
 * every page view asks GitHub for the head of the tracked ref. Same commit — the page
 * renders as usual and nothing is printed. New commit — pull.php (already installed
 * next to the site, with its own pull-config.php) deploys it over HTTP, and the browser
 * is sent back to the very URL it asked for, now served by the new code.
 *
 * Credentials are never duplicated: repo, branch/PR, GitHub token and the pull.php
 * password all come from pull-config.php in the site root.
 */

final class AutoPull {
    /** Written next to the app data, never into the web root a deploy overwrites. */
    public const STATE_FILE = 'auto-pull.json';

    /** How long a failed check/deploy stops the automation, seconds. */
    public const COOLDOWN_SEC = 120;

    /** Ask GitHub for the head — short, the page waits for it. */
    public const HEAD_TIMEOUT_SEC = 8;

    /** Run pull.php — a deploy unpacks an archive, so it gets room. */
    public const DEPLOY_TIMEOUT_SEC = 300;

    /**
     * Give up waiting for pull.php after this many seconds of complete silence.
     * A host that serves one PHP request at a time keeps ours busy, so its answer
     * never starts — but pull.php sets ignore_user_abort(true), so it deploys
     * anyway once a worker frees up. Waiting out the full timeout would freeze the
     * page for nothing; we stop listening and say so.
     */
    public const DEPLOY_SILENCE_SEC = 20;

    /**
     * Page hook. Call it before any output, as early in the bootstrap as possible.
     *
     * $opts:
     *   enabled   bool    master switch from the app's own settings (default false)
     *   root      string  directory holding pull.php + pull-config.php (default: auto)
     *   state_dir string  writable directory for auto-pull.json (default: system temp)
     *   interval  int     seconds between checks, 0 = every page view (default 0)
     *   pull_url  string  explicit URL of pull.php when it cannot be derived
     *   redirect  bool    reload the current URL after a deploy (default true)
     */
    public static function run(array $opts): void {
        static $done = false;
        if ($done) return;
        $done = true;

        if (empty($opts['enabled'])) return;
        if (PHP_SAPI === 'cli') return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;  // never mid-form

        $state = self::stateRead($opts);
        $now   = time();
        if ((int)($state['cooldown_until'] ?? 0) > $now) return;
        $interval = max(0, (int)($opts['interval'] ?? 0));
        if ($interval > 0 && $now - (int)($state['checked_at'] ?? 0) < $interval) return;

        $report = self::check($opts, false);
        // A page gets sent back to itself so the new code answers it; XHR, the service
        // worker and API traffic just carry on — their answer is data, not a page.
        if (!empty($report['deployed_now']) && ($opts['redirect'] ?? true)
            && !self::isBackgroundRequest() && !headers_sent()) {
            header('Cache-Control: no-store');
            header('Location: ' . (string)($_SERVER['REQUEST_URI'] ?? '/'), true, 302);
            exit;
        }
    }

    /**
     * The check itself, also the admin "check now" button ($force deploys a commit
     * that is already deployed as well). Never prints, never throws: the outcome is
     * the returned report and the same report is remembered in the state file.
     *
     * Report: checked_at, head, deployed, changed, deployed_now, ok, error, note, output.
     */
    public static function check(array $opts, bool $force = false): array {
        $state  = self::stateRead($opts);
        $report = [
            'checked_at' => time(), 'head' => '', 'deployed' => (string)($state['deployed'] ?? ''),
            'changed' => false, 'deployed_now' => false, 'ok' => false,
            'error' => '', 'note' => '', 'output' => '',
        ];

        $root = self::root($opts);
        $cfg  = self::pullConfig($root);
        if ($cfg === null) {
            return self::finish($opts, $state, $report, 'pull-config.php не найден рядом с pull.php (' . $root . ')');
        }
        if ($cfg['repo'] === '') {
            return self::finish($opts, $state, $report, 'в pull-config.php не указан репозиторий');
        }

        [$head, $err] = self::head($cfg);
        if ($head === '') {
            return self::finish($opts, $state, $report, 'GitHub не ответил: ' . $err);
        }
        $report['head'] = $head;

        // What is live: pull.php's own journal when it keeps one, our note otherwise.
        [$deployed, $pinned] = self::deployedSha($root, $state);
        $report['deployed'] = $deployed;
        $report['changed']  = ($deployed !== $head);

        if ($pinned && !$force) {
            $report['ok']   = true;
            $report['note'] = 'откат закреплён в pull-state.json — автообновление стоит';
            return self::finish($opts, $state, $report, '');
        }
        if (!$report['changed'] && !$force) {
            $report['ok']   = true;
            $report['note'] = 'актуально';
            return self::finish($opts, $state, $report, '');
        }

        // One deploy at a time: parallel page views just render the current code.
        $lock = self::lock($opts);
        if ($lock === null) {
            $report['ok']   = true;
            $report['note'] = 'деплой уже идёт в соседнем запросе';
            return self::finish($opts, $state, $report, '', false);
        }

        [$ok, $output, $derr] = self::deploy($root, $cfg, $opts);
        self::unlock($lock);

        $report['output'] = $output;
        if ($ok === null) {
            // Answer never arrived, but pull.php runs to the end on its own. The
            // commit counts as deployed — the page just cannot show it yet.
            $report['ok']       = true;
            $report['deployed'] = $head;
            $report['note']     = 'деплой идёт в фоне (хостинг занят этим же запросом) — страница обновится при следующем заходе';
            // Пауза, чтобы соседние заходы не запустили второй деплой поверх идущего.
            return self::finish($opts, $state, $report, '', true, self::COOLDOWN_SEC);
        }
        if (!$ok) {
            return self::finish($opts, $state, $report, 'pull.php не выложил обновление: ' . $derr);
        }

        $report['ok']           = true;
        $report['deployed']     = $head;
        $report['deployed_now'] = true;
        $report['note']         = 'выложен ' . substr($head, 0, 7);
        return self::finish($opts, $state, $report, '');
    }

    /**
     * Options out of an app config array (AUTOPULL_ENABLED / _INTERVAL / _URL),
     * so a consumer's bootstrap is one line. $extra wins — that is where the app
     * passes its own writable state_dir.
     */
    public static function options(array $cfg, array $extra = []): array {
        $on = (string)($cfg['AUTOPULL_ENABLED'] ?? '0');
        return array_merge([
            'enabled'  => ($on !== '' && $on !== '0'),
            'interval' => (int)($cfg['AUTOPULL_INTERVAL'] ?? 0),
            'pull_url' => (string)($cfg['AUTOPULL_URL'] ?? ''),
        ], $extra);
    }

    /** What the admin panel shows: the last check as it was remembered. */
    public static function status(array $opts): array {
        $state = self::stateRead($opts);
        return [
            'checked_at'  => (int)($state['checked_at'] ?? 0),
            'head'        => (string)($state['head'] ?? ''),
            'deployed'    => (string)($state['deployed'] ?? ''),
            'deployed_at' => (int)($state['deployed_at'] ?? 0),
            'error'       => (string)($state['error'] ?? ''),
            'note'        => (string)($state['note'] ?? ''),
            'cooldown_until' => (int)($state['cooldown_until'] ?? 0),
        ];
    }

    // ---------- pull.php side ----------

    /** Where pull.php lives: the option, else the first parent directory holding it. */
    public static function root(array $opts): string {
        if (!empty($opts['root'])) return rtrim((string)$opts['root'], '/');
        $starts = [];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) $starts[] = (string)$_SERVER['DOCUMENT_ROOT'];
        $starts[] = __DIR__;
        foreach ($starts as $start) {
            $dir = rtrim(str_replace('\\', '/', $start), '/');
            for ($i = 0; $i < 6 && $dir !== '' && $dir !== '/'; $i++) {
                if (is_file($dir . '/pull-config.php')) return $dir;
                $dir = dirname($dir);
            }
        }
        return rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__)), '/');
    }

    /** pull-config.php as the two pull.php generations write it. Null = no config. */
    public static function pullConfig(string $root): ?array {
        $path = $root . '/pull-config.php';
        if (!is_file($path)) return null;
        $raw = @include $path;
        if (!is_array($raw)) return null;
        $pr = (int)($raw['pr_number'] ?? 0);
        return [
            'repo'          => trim((string)($raw['repo'] ?? '')),
            'branch'        => trim((string)($raw['branch'] ?? 'main')) ?: 'main',
            'source'        => ((string)($raw['source'] ?? '') === 'pr' || $pr > 0) ? 'pr' : 'branch',
            'pr_number'     => max(0, $pr),
            'token'         => (string)(getenv('GITHUB_TOKEN') ?: ($raw['gh_token'] ?? '')),
            'password_hash' => (string)($raw['password_hash'] ?? ''),
            'secret'        => (string)($raw['secret'] ?? ''),   // pre-password pull.php
        ];
    }

    /** Head commit of the tracked ref: [sha, error]. */
    private static function head(array $cfg): array {
        if ($cfg['source'] === 'pr') {
            [$body, $err] = self::api('/repos/' . $cfg['repo'] . '/pulls/' . $cfg['pr_number'], $cfg['token'], false);
            if ($body === null) return ['', $err];
            $data = json_decode($body, true);
            $sha  = is_array($data) ? (string)($data['head']['sha'] ?? '') : '';
            return $sha !== '' ? [$sha, ''] : ['', 'у pull request #' . $cfg['pr_number'] . ' нет head-коммита'];
        }
        // The sha media type answers with the bare commit id — the cheapest question there is.
        [$body, $err] = self::api('/repos/' . $cfg['repo'] . '/commits/' . rawurlencode($cfg['branch']), $cfg['token'], true);
        if ($body === null) return ['', $err];
        $sha = trim($body);
        if (preg_match('~^[0-9a-f]{40}$~i', $sha)) return [$sha, ''];
        $data = json_decode($body, true);           // a host that rewrites Accept still gets JSON
        $sha  = is_array($data) ? (string)($data['sha'] ?? '') : '';
        return $sha !== '' ? [$sha, ''] : ['', 'непонятный ответ api.github.com'];
    }

    /** GET api.github.com: [body, error]. */
    private static function api(string $path, string $token, bool $shaOnly): array {
        $headers = [
            'Accept: ' . ($shaOnly ? 'application/vnd.github.sha' : 'application/vnd.github+json'),
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: auto-pull',
        ];
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;

        $url  = 'https://api.github.com' . $path;
        $body = '';
        $code = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => self::HEAD_TIMEOUT_SEC,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $body  = (string)curl_exec($ch);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($errno !== 0) return [null, 'curl ' . $errno . ': ' . $err];
        } else {
            if (!ini_get('allow_url_fopen')) return [null, 'нет curl и allow_url_fopen=Off'];
            $ctx = stream_context_create(['http' => [
                'header' => implode("\r\n", $headers), 'timeout' => self::HEAD_TIMEOUT_SEC,
                'ignore_errors' => true, 'follow_location' => 1,
            ]]);
            $body = (string)@file_get_contents($url, false, $ctx);
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $code = (int)$m[1];
            }
        }
        if ($code === 401) return [null, '401 — токен в pull-config.php недействителен'];
        if ($code === 403) return [null, '403 — лимит запросов или токену сюда нельзя'];
        if ($code === 404) return [null, '404 — репозиторий или ветка недоступны токену'];
        if ($code >= 400)  return [null, 'http ' . $code];
        return [$body, ''];
    }

    /** Live commit: pull-state.json when pull.php keeps one, our own note otherwise. */
    private static function deployedSha(string $root, array $state): array {
        $path = $root . '/pull-state.json';
        if (is_file($path)) {
            $raw = json_decode((string)@file_get_contents($path), true);
            if (is_array($raw) && (string)($raw['sha'] ?? '') !== '') {
                return [(string)$raw['sha'], !empty($raw['pinned'])];
            }
        }
        return [(string)($state['deployed'] ?? ''), false];
    }

    /**
     * Runs pull.php over HTTP with the credentials from pull-config.php.
     * @return array{0: bool|null, 1: string, 2: string} ok (null = started but
     *         unconfirmed, see DEPLOY_SILENCE_SEC), last of the output, error.
     */
    private static function deploy(string $root, array $cfg, array $opts): array {
        if (!is_file($root . '/pull.php')) return [false, '', 'pull.php не найден в ' . $root];

        $url     = self::pullUrl($root, $opts);
        $headers = ['User-Agent: auto-pull'];
        // The password itself is never stored — but the cookie pull.php trusts is a
        // signature keyed by the stored hash, and that hash is in pull-config.php.
        if ($cfg['password_hash'] !== '') {
            $expires = time() + 300;
            $token   = $expires . '|' . hash_hmac('sha256', 'pull-auth|' . $expires, $cfg['password_hash']);
            $headers[] = 'Cookie: pull_auth=' . $token;
        } elseif ($cfg['secret'] !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'token=' . rawurlencode($cfg['secret']);
        }
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'plain=1';

        if (!function_exists('curl_init')) return [false, '', 'нет curl — некому запустить pull.php'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::DEPLOY_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        // pull.php prints as it works, so total silence means it has not started —
        // the host is busy with THIS request. Stop listening instead of freezing the
        // page for the whole timeout; the deploy itself runs on regardless
        // (pull.php sets ignore_user_abort(true)).
        $since = microtime(true);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_XFERINFOFUNCTION,
            static function ($handle, $dlTotal, $dlNow) use ($since): int {
                return ($dlNow <= 0 && microtime(true) - $since > self::DEPLOY_SILENCE_SEC) ? 1 : 0;
            });
        $body  = (string)curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tail = trim(mb_substr($body, -600));
        // 42 — our own "silence" abort above, 28 — the whole timeout ran out
        // mid-deploy. In both cases pull.php keeps working without us.
        if ($errno === 42 || $errno === CURLE_OPERATION_TIMEDOUT) {
            return [null, $tail, 'ответа pull.php не дождались'];
        }
        if ($errno !== 0)  return [false, $tail, 'curl ' . $errno . ': ' . $err];
        if ($code === 401) return [false, $tail, 'pull.php просит пароль — в pull-config.php нет его хеша'];
        if ($code === 403) return [false, $tail, 'pull.php: 403 (IP не в списке разрешённых)'];
        if ($code >= 400)  return [false, $tail, 'pull.php ответил http ' . $code];
        // Newer pull.php ends with a STATUS: line; the older one has no such contract.
        if (preg_match('~STATUS:\s*(\w[\w-]*)~i', $body, $m) && strtoupper($m[1]) === 'FAILED') {
            return [false, $tail, 'pull.php: STATUS FAILED'];
        }
        return [true, $tail, ''];
    }

    /** URL of pull.php: the option, else its path under the document root. */
    private static function pullUrl(string $root, array $opts): string {
        $explicit = trim((string)($opts['pull_url'] ?? ''));
        if ($explicit !== '') return $explicit;

        $path    = '/pull.php';
        $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $rootAbs = realpath($root);
        if ($docRoot !== false && $rootAbs !== false && strpos($rootAbs, $docRoot) === 0) {
            $rel  = trim(str_replace('\\', '/', substr($rootAbs, strlen($docRoot))), '/');
            $path = ($rel === '' ? '' : '/' . $rel) . '/pull.php';
        }
        $https  = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        return ($https ? 'https' : 'http') . '://' . $host . $path;
    }

    // ---------- state ----------

    private static function statePath(array $opts): string {
        $dir = trim((string)($opts['state_dir'] ?? ''));
        if ($dir === '') $dir = sys_get_temp_dir();
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/' . self::STATE_FILE;
    }

    private static function stateRead(array $opts): array {
        $path = self::statePath($opts);
        if (!is_file($path)) return [];
        $raw = json_decode((string)@file_get_contents($path), true);
        return is_array($raw) ? $raw : [];
    }

    /** Remembers the outcome and returns the report. $error also starts the cooldown. */
    private static function finish(array $opts, array $state, array $report, string $error, bool $write = true, int $cooldown = 0): array {
        if ($error !== '') {
            $report['ok']    = false;
            $report['error'] = $error;
        }
        if (!$write) return $report;

        $state['checked_at'] = $report['checked_at'];
        $state['head']       = $report['head'];
        $state['deployed']   = $report['deployed'];
        $state['error']      = $report['error'];
        $state['note']       = $report['note'];
        $state['output']     = $report['output'];
        if ($report['deployed_now']) $state['deployed_at'] = $report['checked_at'];
        $pause = $error !== '' ? self::COOLDOWN_SEC : $cooldown;
        $state['cooldown_until'] = $pause > 0 ? time() + $pause : 0;

        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json !== false) {
            @file_put_contents(self::statePath($opts), $json . "\n", LOCK_EX);
            @chmod(self::statePath($opts), 0600);
        }
        return $report;
    }

    /** @return resource|null */
    private static function lock(array $opts) {
        $path = self::statePath($opts) . '.lock';
        $fh   = @fopen($path, 'c');
        if ($fh === false) return null;
        if (!@flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return null; }
        return $fh;
    }

    /** @param resource $fh */
    private static function unlock($fh): void {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }

    /** XHR, service worker and API traffic — a redirect there would answer the wrong thing. */
    private static function isBackgroundRequest(): bool {
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') return true;
        $dest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
        if ($dest !== '' && $dest !== 'document' && $dest !== 'iframe') return true;
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return $accept !== '' && strpos($accept, 'text/html') === false;
    }
}
