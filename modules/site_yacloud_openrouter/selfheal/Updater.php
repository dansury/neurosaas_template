<?php
/**
 * Updater — version check, staged update, one-click rollback.
 * spec: spec/selfheal.md §10
 *
 * The promise is "an update can never break the install", so nothing is
 * overwritten until the new code has been proven loadable:
 *
 *   check()   reads module.json of the tracked ref (cached, short timeout,
 *             cannot block a page) and compares versions.
 *   apply()   1. refuses a MAJOR api_version jump — those need a human
 *             2. refuses when the release wants a newer PHP than this one
 *             3. downloads the zipball of an exact commit
 *             4. extracts it to a staging directory OUTSIDE the module
 *             5. lints EVERY php file in the staging copy (token_get_all with
 *                TOKEN_PARSE — no shell, no exec needed)
 *             6. backs the current module up in full
 *             7. copies the release over the module, skipping `preserve`
 *                paths (data/, the sealed token, pull-config.php, .env)
 *             8. on ANY failure in step 7 restores the backup and reports why
 *   rollback() puts the newest backup back and PINS the version, so the
 *             updater does not immediately re-install what was just rejected.
 *
 * Operator files are never touched: state, database, sealed token and local
 * configuration survive every update and every rollback.
 */

declare(strict_types=1);

namespace Selfheal;

use Throwable;
use ZipArchive;

final class Updater {
    public const CHECK_TTL = 21600;        // 6h between GitHub questions
    public const KEEP_BACKUPS = 3;
    public const API = 'https://api.github.com';

    private State $state;
    private array $opts;
    private string $work;

    public function __construct(State $state, array $opts = []) {
        $this->state = $state;
        $this->opts  = $opts;
        $this->work  = rtrim((string) ($opts['work_dir'] ?? (SELFHEAL_MODULE_ROOT . '/data/selfheal')), '/');
    }

    /* ─────────────────────────── status ─────────────────────────── */

    /**
     * What the admin panel renders. Cheap: uses the cached check unless
     * $force, so calling it on every page view costs nothing.
     */
    public function status(bool $force = false): array {
        $remote = $this->check($force);
        $cur    = Contract::version();
        $newer  = $remote['version'] !== '' && version_compare($remote['version'], $cur, '>');
        $pinned = (string) $this->state->get('pinned_version', '');

        $blockers = [];
        if ($newer && !Contract::autoUpdatable((string) $remote['api_version'])) {
            $blockers[] = 'мажорная смена API (' . Contract::API_VERSION . ' → ' . $remote['api_version']
                . ') — обновление только вручную, чтобы не сломать вызывающий код';
        }
        if ($newer && $remote['php'] !== '' && version_compare(PHP_VERSION, (string) $remote['php'], '<')) {
            $blockers[] = 'нужен PHP ' . $remote['php'] . ', на сервере ' . PHP_VERSION;
        }
        if ($newer && !$this->writable()) {
            $blockers[] = 'каталог модуля закрыт на запись — обновление невозможно';
        }
        if ($newer && $pinned !== '' && version_compare($remote['version'], $pinned, '<=')) {
            $blockers[] = 'версия ' . $pinned . ' закреплена после отката — обновление до неё не предлагается';
        }

        return [
            'current'     => $cur,
            'api'         => Contract::API_VERSION,
            'remote'      => (string) $remote['version'],
            'remote_api'  => (string) $remote['api_version'],
            'sha'         => (string) $remote['sha'],
            'notes'       => (string) $remote['notes'],
            'update'      => $newer && !$blockers,
            'newer'       => $newer,
            'blockers'    => $blockers,
            'checked_at'  => (int) $remote['checked_at'],
            'error'       => (string) $remote['error'],
            'pinned'      => $pinned,
            'repo'        => Contract::repo(),
            'channel'     => Contract::channel(),
            'backups'     => $this->backups(),
            'writable'    => $this->writable(),
            'dismissed'   => (string) $this->state->get('notice_dismissed', ''),
            'last_result' => (string) $this->state->get('last_update_note', ''),
        ];
    }

    /** True while the admin panel should shout about a new version. */
    public function shouldNotify(): bool {
        $s = $this->status(false);
        if (!$s['newer']) return false;
        return $s['dismissed'] !== $s['remote'];
    }

    public function dismissNotice(): void {
        $this->state->set('notice_dismissed', (string) $this->status(false)['remote']);
    }

    /* ─────────────────────────── remote check ─────────────────────────── */

    /** module.json of the tracked ref, cached. Never throws. */
    public function check(bool $force = false): array {
        $blank = ['version' => '', 'api_version' => '', 'php' => '', 'sha' => '', 'notes' => '', 'checked_at' => 0, 'error' => ''];
        $cached = json_decode((string) $this->state->get('remote_check', ''), true);
        if (!$force && is_array($cached) && (time() - (int) ($cached['checked_at'] ?? 0)) < self::CHECK_TTL) {
            return array_merge($blank, $cached);
        }

        $out  = $blank;
        $out['checked_at'] = time();
        try {
            $repo = Contract::repo();
            $ref  = Contract::channel();
            $url  = 'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode($ref) . '/module.json';
            [$st, $json, , $err] = Http::json('GET', $url, [], null, 8);
            if ($st !== 200 || !is_array($json)) {
                $out['error'] = 'module.json не прочитан: ' . ($err !== '' ? $err : 'HTTP ' . $st);
                $this->state->set('remote_check', (string) json_encode($out));
                return $out;
            }
            $out['version']     = (string) ($json['version'] ?? '');
            $out['api_version'] = (string) ($json['api_version'] ?? '');
            $out['php']         = (string) ($json['requires']['php'] ?? '');
            $out['notes']       = mb_substr((string) ($json['notes'] ?? ''), 0, 500);

            // Pin the exact commit so the download matches what we just read.
            [$cs, $commit] = Http::json('GET', self::API . '/repos/' . $repo . '/commits/' . rawurlencode($ref)
                . '?per_page=1', ['Accept: application/vnd.github+json'], null, 8);
            if ($cs === 200 && is_array($commit) && isset($commit['sha'])) {
                $out['sha'] = substr((string) $commit['sha'], 0, 40);
            }
        } catch (Throwable $e) {
            $out['error'] = 'проверка не удалась';
        }
        $this->state->set('remote_check', (string) json_encode($out));
        return $out;
    }

    /* ─────────────────────────── apply ─────────────────────────── */

    /** Install the tracked release. Returns ['ok'=>bool,'note'=>string,...]. */
    public function apply(bool $force = false): array {
        $lock = $this->lock();
        if ($lock === null) return $this->result(false, 'обновление уже выполняется');

        try {
            $s = $this->status(true);
            if (!$s['newer'] && !$force)   return $this->result(false, 'уже актуальная версия ' . $s['current']);
            if ($s['blockers'] && !$force) return $this->result(false, implode('; ', $s['blockers']));
            if (!$this->writable())        return $this->result(false, 'каталог модуля закрыт на запись');
            $sha = (string) $s['sha'];
            if ($sha === '') return $this->result(false, 'не определён коммит для загрузки');

            // 3. download
            $zip = $this->work . '/dl/' . $sha . '.zip';
            $url = self::API . '/repos/' . Contract::repo() . '/zipball/' . $sha;
            [$ok, $bytes, $err] = Http::download($url, $zip, ['Accept: application/vnd.github+json'], 60);
            if (!$ok) return $this->result(false, 'загрузка не удалась: ' . $err);
            if ($bytes < 1024) { @unlink($zip); return $this->result(false, 'архив подозрительно мал'); }

            // 4. extract to staging, outside the module
            $stage = $this->work . '/stage/' . $sha;
            $this->rmTree($stage);
            $src = $this->extract($zip, $stage);
            @unlink($zip);
            if ($src === null) return $this->result(false, 'архив не распакован (нужен ext-zip)');

            // 5. prove the new code is loadable BEFORE touching anything
            [$lintOk, $lintNote] = $this->verify($src, (string) $s['remote']);
            if (!$lintOk) { $this->rmTree($stage); return $this->result(false, 'проверка новой версии: ' . $lintNote); }

            // 6. full backup of what runs now
            $backup = $this->work . '/backup/' . $s['current'] . '-' . gmdate('Ymd-His');
            if (!$this->copyTree(SELFHEAL_MODULE_ROOT, $backup, true)) {
                $this->rmTree($stage);
                return $this->result(false, 'не удалось сделать резервную копию — обновление отменено');
            }

            // 7. swap; any failure rolls straight back
            $installed = $this->fileList($src);
            if (!$this->copyTree($src, SELFHEAL_MODULE_ROOT, true)) {
                $this->restore($backup);
                $this->rmTree($stage);
                return $this->result(false, 'копирование не удалось — версия ' . $s['current'] . ' восстановлена');
            }
            $this->removeStale($installed);

            $this->state->set('installed_files', (string) json_encode($installed));
            $this->state->set('previous_version', (string) $s['current']);
            $this->state->set('applied_sha', $sha);
            $this->state->set('applied_at', gmdate('Y-m-d\TH:i:s\Z'));
            $this->state->forget('pinned_version');
            $this->state->forget('notice_dismissed');
            $this->rmTree($stage);
            $this->trimBackups();

            return $this->result(true, 'установлена версия ' . $s['remote'] . ' (было ' . $s['current'] . ')', [
                'from' => $s['current'], 'to' => $s['remote'], 'sha' => substr($sha, 0, 8), 'backup' => basename($backup),
            ]);
        } catch (Throwable $e) {
            return $this->result(false, 'сбой обновления: ' . mb_substr($e->getMessage(), 0, 200));
        } finally {
            $this->unlock($lock);
        }
    }

    /** Put the newest backup back and pin the version we came from. */
    public function rollback(?string $which = null): array {
        $lock = $this->lock();
        if ($lock === null) return $this->result(false, 'обновление уже выполняется');
        try {
            $backups = $this->backups();
            if (!$backups) return $this->result(false, 'резервных копий нет — откатывать некуда');
            $name = $which !== null && $which !== '' ? basename($which) : (string) $backups[0]['name'];
            $dir  = $this->work . '/backup/' . $name;
            if (!is_dir($dir)) return $this->result(false, 'резервная копия ' . $name . ' не найдена');
            if (!$this->writable()) return $this->result(false, 'каталог модуля закрыт на запись');

            $wasVersion = Contract::version();
            // Safety net for the rollback itself.
            $safety = $this->work . '/backup/pre-rollback-' . gmdate('Ymd-His');
            $this->copyTree(SELFHEAL_MODULE_ROOT, $safety, true);

            if (!$this->copyTree($dir, SELFHEAL_MODULE_ROOT, true)) {
                $this->restore($safety);
                return $this->result(false, 'откат не удался — версия ' . $wasVersion . ' оставлена');
            }
            // Do not re-install what was just rejected.
            $this->state->set('pinned_version', $wasVersion);
            $this->state->set('rolled_back_at', gmdate('Y-m-d\TH:i:s\Z'));
            $this->state->forget('remote_check');
            $this->trimBackups();
            return $this->result(true, 'выполнен откат на копию ' . $name . '; версия ' . $wasVersion . ' закреплена как отклонённая');
        } catch (Throwable $e) {
            return $this->result(false, 'сбой отката: ' . mb_substr($e->getMessage(), 0, 200));
        } finally {
            $this->unlock($lock);
        }
    }

    /** Drop the pin so a future release installs again. */
    public function unpin(): void { $this->state->forget('pinned_version'); }

    public function backups(): array {
        $dir = $this->work . '/backup';
        if (!is_dir($dir)) return [];
        $out = [];
        foreach ((array) @scandir($dir) as $name) {
            if ($name === '.' || $name === '..' || !is_dir($dir . '/' . $name)) continue;
            $mf = json_decode((string) @file_get_contents($dir . '/' . $name . '/module.json'), true);
            $out[] = [
                'name'    => (string) $name,
                'version' => is_array($mf) ? (string) ($mf['version'] ?? '?') : '?',
                'at'      => (int) @filemtime($dir . '/' . $name),
            ];
        }
        usort($out, static function (array $a, array $b): int { return $b['at'] <=> $a['at']; });
        return $out;
    }

    /* ─────────────────────────── verification ─────────────────────────── */

    /**
     * Every PHP file in the release must parse, and the manifest must say what
     * check() said. token_get_all(TOKEN_PARSE) throws ParseError on bad syntax,
     * so this needs no shell access — it works on locked-down shared hosting.
     */
    private function verify(string $dir, string $expectVersion): array {
        $mfFile = $dir . '/module.json';
        if (!is_file($mfFile)) return [false, 'в релизе нет module.json'];
        $mf = json_decode((string) @file_get_contents($mfFile), true);
        if (!is_array($mf)) return [false, 'module.json релиза не читается'];
        if ($expectVersion !== '' && (string) ($mf['version'] ?? '') !== $expectVersion) {
            return [false, 'версия в архиве (' . (string) ($mf['version'] ?? '?') . ') не совпадает с объявленной'];
        }
        if (!Contract::autoUpdatable((string) ($mf['api_version'] ?? ''))) {
            return [false, 'API релиза несовместим с текущим'];
        }
        $files = $this->fileList($dir);
        if (count($files) < 8) return [false, 'в архиве всего ' . count($files) . ' файлов — похоже на неполную загрузку'];

        $checked = 0;
        foreach ($files as $rel) {
            if (substr($rel, -4) !== '.php') continue;
            $src = (string) @file_get_contents($dir . '/' . $rel);
            if ($src === '') return [false, $rel . ' пуст'];
            try {
                token_get_all($src, TOKEN_PARSE);
            } catch (Throwable $e) {
                return [false, 'синтаксическая ошибка в ' . $rel];
            }
            $checked++;
        }
        // The entry point must be there, or the host's one-liner breaks.
        if (!is_file($dir . '/selfheal/bootstrap.php')) return [false, 'в релизе нет selfheal/bootstrap.php'];
        return [true, 'проверено файлов PHP: ' . $checked];
    }

    /* ─────────────────────────── filesystem ─────────────────────────── */

    /** Paths that belong to the operator and survive every update. */
    private function preserved(): array {
        $list = (array) (Contract::manifest()['preserve'] ?? []);
        $list[] = 'data';
        // The operator's own credential survives; the SHIPPED one must not, or
        // the developer could never rotate it (see the notice in Vault.php).
        $list[] = 'selfheal/' . Vault::LOCAL_TOKEN_FILE;
        $out = [];
        foreach ($list as $p) {
            $p = trim(str_replace('\\', '/', (string) $p), '/');
            if ($p !== '') $out[$p] = true;
        }
        return $out;
    }

    private function isPreserved(string $rel): bool {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        foreach (array_keys($this->preserved()) as $p) {
            if ($rel === $p || strpos($rel, $p . '/') === 0) return true;
        }
        return false;
    }

    /** Relative paths of every regular file, preserved paths excluded. */
    private function fileList(string $root): array {
        $out  = [];
        $root = rtrim($root, '/');
        $walk = function (string $dir, string $prefix) use (&$walk, &$out, $root): void {
            foreach ((array) @scandir($dir) as $name) {
                if ($name === '.' || $name === '..' || $name === '.git') continue;
                $full = $dir . '/' . $name;
                $rel  = $prefix === '' ? $name : $prefix . '/' . $name;
                if ($this->isPreserved($rel)) continue;
                if (is_dir($full)) { $walk($full, $rel); continue; }
                if (is_file($full)) $out[] = $rel;
            }
        };
        if (is_dir($root)) $walk($root, '');
        sort($out);
        return $out;
    }

    /** Copy a tree; $skipPreserved keeps operator files out of the way. */
    private function copyTree(string $from, string $to, bool $skipPreserved = false): bool {
        $from = rtrim($from, '/');
        if (!is_dir($from)) return false;
        if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) return false;
        foreach ($this->fileList($from) as $rel) {
            if ($skipPreserved && $this->isPreserved($rel)) continue;
            $dst = $to . '/' . $rel;
            $dir = dirname($dst);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
            if (!@copy($from . '/' . $rel, $dst)) return false;
        }
        return true;
    }

    /** Files the previous release owned that this one dropped. */
    private function removeStale(array $nowInstalled): void {
        $prev = json_decode((string) $this->state->get('installed_files', ''), true);
        if (!is_array($prev) || !$prev) return;                 // first update deletes nothing
        $keep = array_flip($nowInstalled);
        foreach ($prev as $rel) {
            $rel = (string) $rel;
            if (isset($keep[$rel]) || $this->isPreserved($rel)) continue;
            $full = SELFHEAL_MODULE_ROOT . '/' . $rel;
            if (is_file($full)) @unlink($full);
        }
    }

    private function restore(string $backup): void {
        if (is_dir($backup)) $this->copyTree($backup, SELFHEAL_MODULE_ROOT, true);
    }

    private function extract(string $zip, string $stage): ?string {
        if (!class_exists('ZipArchive')) return null;
        $za = new ZipArchive();
        if ($za->open($zip) !== true) return null;
        if (!is_dir($stage) && !@mkdir($stage, 0775, true) && !is_dir($stage)) { $za->close(); return null; }
        $ok = $za->extractTo($stage);
        $za->close();
        if (!$ok) return null;
        // GitHub wraps everything in one <repo>-<sha> directory.
        foreach ((array) @scandir($stage) as $name) {
            if ($name === '.' || $name === '..') continue;
            if (is_dir($stage . '/' . $name) && is_file($stage . '/' . $name . '/module.json')) return $stage . '/' . $name;
        }
        return is_file($stage . '/module.json') ? $stage : null;
    }

    private function rmTree(string $dir): void {
        if (!is_dir($dir)) return;
        // Stay inside our own work directory — never delete anything else.
        if (strpos(realpath($dir) ?: $dir, realpath($this->work) ?: $this->work) !== 0) return;
        foreach ((array) @scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $dir . '/' . $name;
            is_dir($full) ? $this->rmTree($full) : @unlink($full);
        }
        @rmdir($dir);
    }

    private function trimBackups(): void {
        $all = $this->backups();
        for ($i = self::KEEP_BACKUPS; $i < count($all); $i++) {
            $this->rmTree($this->work . '/backup/' . $all[$i]['name']);
        }
    }

    private function writable(): bool {
        return is_writable(SELFHEAL_MODULE_ROOT) && is_writable(SELFHEAL_DIR);
    }

    /* ─────────────────────────── plumbing ─────────────────────────── */

    private function result(bool $ok, string $note, array $extra = []): array {
        $this->state->set('last_update_note', ($ok ? 'ok: ' : 'ошибка: ') . $note);
        $this->state->set('last_update_at', (string) time());
        return array_merge(['ok' => $ok, 'note' => $note], $extra);
    }

    /** @return resource|null */
    private function lock() {
        $dir = $this->work;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        $fh = @fopen($dir . '/update.lock', 'c');
        if ($fh === false) return null;
        if (!@flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return null; }
        if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
        return $fh;
    }

    private function unlock($fh): void {
        if (!is_resource($fh)) return;
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}
