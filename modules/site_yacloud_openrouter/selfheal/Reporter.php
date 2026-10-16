<?php
/**
 * Reporter — module errors → issues of the canonical repository.
 * spec: spec/selfheal.md §6
 *
 * The point of the whole module: the developer can keep it working without any
 * contact with the people who installed it. So an error has to travel — but
 * never at the expense of the host:
 *
 *   recording  is a couple of SQLite writes, always local, never network;
 *   sending    happens AFTER the response is delivered to the browser
 *              (fastcgi_finish_request), under a hard time budget, at most a
 *              couple of reports per request;
 *   failure    is invisible: a dead network just leaves the row queued with an
 *              exponential backoff.
 *
 * Volume control, because a loop must not produce ten thousand issues:
 *   - identical errors share a FINGERPRINT (normalized message + place);
 *   - a fingerprint maps to one issue for the life of the install;
 *   - a known fingerprint adds a comment at most once per COMMENT_COOLDOWN;
 *   - MAX_PER_FP_PER_DAY recordings of one fingerprint per day;
 *   - MAX_NEW_PER_HOUR recordings per hour for fingerprints that have no issue
 *     yet — an already-filed bug stays exempt, so a known recurring failure
 *     never starves a genuinely new one out of the hourly budget.
 */

declare(strict_types=1);

namespace Selfheal;

use Throwable;

final class Reporter {
    public const LABEL = 'auto-report';
    /** Recordings per hour for fingerprints not yet filed as an issue. */
    public const MAX_NEW_PER_HOUR   = 6;
    public const MAX_PER_FP_PER_DAY = 3;
    public const COMMENT_COOLDOWN   = 21600;   // 6h between comments on one issue
    public const SEND_BUDGET_SEC    = 6;       // total wall clock for a flush
    public const API = 'https://api.github.com';

    private State $state;
    private Consent $consent;
    private array $opts;
    private bool $flushScheduled = false;
    private ?string $lastRef = null;

    public function __construct(State $state, Consent $consent, array $opts = []) {
        $this->state   = $state;
        $this->consent = $consent;
        $this->opts    = $opts;
    }

    /** Fingerprint of the most recent recording — the "ref" shown to users. */
    public function lastRef(): ?string { return $this->lastRef; }

    /* ─────────────────────────── recording ─────────────────────────── */

    /**
     * Record one error. Returns its fingerprint (usable as a support ref) or
     * '' when nothing was recorded. Never throws, never blocks on network.
     */
    public function record(string $level, string $message, array $meta = [], array $context = [], array $trace = []): string {
        try {
            if (!$this->consent->mayRecord()) return '';
            $fp = $this->fingerprint($message, $meta);
            $this->lastRef = $fp;

            // Rate limits — a storm costs one row, not one issue per event.
            if ($this->state->countSince(time() - 86400, $fp) >= self::MAX_PER_FP_PER_DAY) return $fp;
            // The hourly budget counts recordings, not distinct bugs, and only
            // gates fingerprints we have not filed yet.
            if ($this->state->countSince(time() - 3600) >= self::MAX_NEW_PER_HOUR
                && $this->state->issueFor($fp) === null) return $fp;

            $payload = [
                'fp'      => $fp,
                'level'   => $level,
                'message' => Scrub::text(mb_substr($message, 0, 2000)),
                'where'   => Scrub::paths((string) ($meta['file'] ?? '')) . ':' . (int) ($meta['line'] ?? 0),
                'type'    => (string) ($meta['type'] ?? 'error'),
                'env'     => $this->env(),
                'context' => Scrub::context($context),
                'trace'   => Scrub::trace($trace, ($this->opts['trace_scope'] ?? 'module') === 'module'),
            ];
            $title = '[auto] ' . mb_substr(preg_replace('~\s+~u', ' ', $payload['message']) ?? '', 0, 120);

            $this->state->enqueue($fp, $level, $title, $payload);
            $this->state->trimQueue((int) ($this->opts['queue_keep'] ?? 300));
            $this->scheduleFlush();
            return $fp;
        } catch (Throwable $e) {
            return '';                       // reporting may never break anything
        }
    }

    /**
     * Stable id for "the same bug". Numbers, hex blobs, quoted strings and
     * paths are normalized away so one bug is one issue across installs.
     */
    public function fingerprint(string $message, array $meta = []): string {
        $norm = mb_strtolower(Scrub::paths($message));
        $norm = (string) preg_replace('~[0-9a-f]{8,}~', '#', $norm);      // hashes, ids
        $norm = (string) preg_replace('~\d+~', 'N', $norm);               // numbers
        $norm = (string) preg_replace('~[\'"][^\'"]{0,80}[\'"]~', 'S', $norm);
        $norm = trim((string) preg_replace('~\s+~u', ' ', $norm));
        $place = basename((string) ($meta['file'] ?? '')) . ':' . (int) ($meta['line'] ?? 0);
        return substr(hash('sha256', Contract::MODULE . '|' . Contract::major(Contract::API_VERSION) . '|' . $norm . '|' . $place), 0, 16);
    }

    /* ─────────────────────────── sending ─────────────────────────── */

    /** Send after the response is flushed, so nobody waits for GitHub. */
    private function scheduleFlush(): void {
        if ($this->flushScheduled) return;
        if (!$this->consent->maySend()) return;
        if (!$this->canSend()) return;
        $this->flushScheduled = true;
        register_shutdown_function(function (): void {
            try {
                if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
                // Deliver the page first; the report goes out on our own time.
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
                $this->flush((int) ($this->opts['flush_per_request'] ?? 2));
            } catch (Throwable $e) { /* nothing to do and nobody to tell */ }
        });
    }

    public function canSend(): bool {
        return Vault::hasToken() || Vault::relay() !== '';
    }

    /**
     * Push queued reports out. Returns a small summary for the admin panel.
     * Safe to call from a cron / admin button as well as from the shutdown hook.
     */
    public function flush(int $limit = 2): array {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        if (!$this->consent->maySend()) { $out['skipped'] = 1; return $out; }
        if (!$this->canSend())          { $out['skipped'] = 1; return $out; }
        $deadline = microtime(true) + self::SEND_BUDGET_SEC;

        foreach ($this->state->pending($limit) as $row) {
            if (microtime(true) > $deadline) break;
            $id      = (int) $row['id'];
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) { $this->state->markSent($id, null, 'нечитаемый payload'); continue; }
            try {
                [$ok, $issue, $note] = $this->deliver((string) $row['fp'], (string) $row['title'], $payload);
            } catch (Throwable $e) {
                $ok = false; $issue = null; $note = 'исключение при отправке';
            }
            if ($ok) {
                $this->state->markSent($id, $issue, $note);
                if ($issue !== null) $this->state->rememberIssue((string) $row['fp'], $issue, true);
                $out['sent']++;
            } else {
                $this->state->markFailed($id, (int) $row['tries'] + 1, $note);
                $out['failed']++;
                $out['errors'][] = $note;
            }
        }
        $this->state->set('last_flush_at', (string) time());
        return $out;
    }

    /** One report → relay, or straight to the issue tracker. */
    private function deliver(string $fp, string $title, array $payload): array {
        $relay = Vault::relay();
        if ($relay !== '') {
            [$status, , , $err] = Http::json('POST', $relay, [], [
                'module' => Contract::MODULE, 'version' => Contract::version(),
                'fp' => $fp, 'title' => $title, 'report' => $payload,
            ], 8);
            if ($status >= 200 && $status < 300) return [true, null, 'relay'];
            return [false, null, 'relay: ' . ($err !== '' ? $err : 'HTTP ' . $status)];
        }

        $token = Vault::token();
        if ($token === '') return [false, null, 'нет учётных данных для отправки'];
        $repo  = Contract::repo();

        // Known bug → comment on the existing issue, at most once per cooldown.
        $known = $this->state->issueFor($fp);
        if ($known !== null && (int) $known['issue'] > 0) {
            $issue = (int) $known['issue'];
            if (time() - (int) ($known['bumped'] ?? 0) < self::COMMENT_COOLDOWN) {
                return [true, $issue, 'повтор известной ошибки, комментарий не нужен'];
            }
            [$st, , , $err] = Http::json('POST', self::API . '/repos/' . $repo . '/issues/' . $issue . '/comments',
                $this->headers($token), ['body' => $this->comment($payload)], 8);
            if ($st >= 200 && $st < 300) return [true, $issue, 'комментарий к #' . $issue];
            if ($st === 404 || $st === 410) { /* issue gone — fall through and open a new one */ }
            else return [false, $issue, 'комментарий: ' . ($err !== '' ? $err : 'HTTP ' . $st)];
        }

        // Unknown here, but maybe already reported from another install.
        $found = $this->searchIssue($repo, $fp, $token);
        if ($found !== null) {
            $this->state->rememberIssue($fp, $found, false);
            [$st] = Http::json('POST', self::API . '/repos/' . $repo . '/issues/' . $found . '/comments',
                $this->headers($token), ['body' => $this->comment($payload)], 8);
            return [true, $found, $st >= 200 && $st < 300 ? 'комментарий к #' . $found : 'найден #' . $found];
        }

        [$st, $body, , $err] = Http::json('POST', self::API . '/repos/' . $repo . '/issues',
            $this->headers($token), [
                'title'  => $title,
                'body'   => $this->body($payload),
                'labels' => [self::LABEL, 'v' . Contract::version()],
            ], 10);
        if ($st >= 200 && $st < 300 && is_array($body) && isset($body['number'])) {
            return [true, (int) $body['number'], 'создан #' . (int) $body['number']];
        }
        // A missing label must not cost us the report — retry without labels.
        if ($st === 422) {
            [$st2, $body2] = Http::json('POST', self::API . '/repos/' . $repo . '/issues',
                $this->headers($token), ['title' => $title, 'body' => $this->body($payload)], 10);
            if ($st2 >= 200 && $st2 < 300 && is_array($body2) && isset($body2['number'])) {
                return [true, (int) $body2['number'], 'создан #' . (int) $body2['number'] . ' (без метки)'];
            }
        }
        return [false, null, 'создание issue: ' . ($err !== '' ? $err : 'HTTP ' . $st)];
    }

    /** Has this fingerprint already been filed (by any install)? */
    private function searchIssue(string $repo, string $fp, string $token): ?int {
        $q   = rawurlencode('repo:' . $repo . ' is:issue in:body "fp:' . $fp . '"');
        [$st, $body] = Http::json('GET', self::API . '/search/issues?per_page=1&q=' . $q, $this->headers($token), null, 8);
        if ($st !== 200 || !is_array($body)) return null;
        $item = $body['items'][0] ?? null;
        return is_array($item) && isset($item['number']) ? (int) $item['number'] : null;
    }

    private function headers(string $token): array {
        return [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
    }

    /* ─────────────────────────── issue text ─────────────────────────── */

    private function body(array $p): string {
        $lines = [];
        $lines[] = '> Автоматический отчёт модуля `' . Contract::MODULE . '`. Создан кодом, не человеком.';
        $lines[] = '';
        $lines[] = '**' . (string) ($p['message'] ?? '') . '**';
        $lines[] = '';
        $lines[] = '| | |';
        $lines[] = '|---|---|';
        $lines[] = '| место | `' . (string) ($p['where'] ?? '') . '` |';
        $lines[] = '| уровень | ' . (string) ($p['level'] ?? '') . ' / ' . (string) ($p['type'] ?? '') . ' |';
        foreach ((array) ($p['env'] ?? []) as $k => $v) {
            $lines[] = '| ' . $k . ' | ' . (is_scalar($v) ? (string) $v : json_encode($v)) . ' |';
        }
        $lines[] = '';
        $ctx = (array) ($p['context'] ?? []);
        if ($ctx) {
            $lines[] = '<details><summary>Контекст</summary>';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = (string) json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = '```';
            $lines[] = '</details>';
            $lines[] = '';
        }
        $tr = (array) ($p['trace'] ?? []);
        if ($tr) {
            $lines[] = '<details><summary>Стек (кадры модуля)</summary>';
            $lines[] = '';
            $lines[] = '```';
            foreach ($tr as $line) $lines[] = (string) $line;
            $lines[] = '```';
            $lines[] = '</details>';
            $lines[] = '';
        }
        $lines[] = '---';
        $lines[] = 'Отпечаток для дедупликации: `fp:' . (string) ($p['fp'] ?? '') . '`';
        $lines[] = '';
        $lines[] = '<sub>Секреты, пути и персональные данные вычищены на стороне установки '
                 . '(`selfheal/Scrub.php`). Отчёты отправлены с согласия оператора и отключаются '
                 . 'в админке в один клик.</sub>';
        return implode("\n", $lines);
    }

    private function comment(array $p): string {
        $env = (array) ($p['env'] ?? []);
        return 'Ошибка повторилась.' . "\n\n"
            . '- версия модуля: `' . (string) ($env['module'] ?? '?') . '`' . "\n"
            . '- PHP: `' . (string) ($env['php'] ?? '?') . '`' . "\n"
            . '- место: `' . (string) ($p['where'] ?? '') . '`' . "\n"
            . '- установка: `' . (string) ($env['install'] ?? '?') . '`' . "\n"
            . '- когда: `' . gmdate('Y-m-d\TH:i:s\Z') . '`' . "\n\n"
            . '<sub>Автоматический комментарий; повторы одной ошибки объединяются не чаще раза в '
            . (int) round(self::COMMENT_COOLDOWN / 3600) . ' ч.</sub>';
    }

    /** Everything a report says about the environment — nothing more. */
    private function env(): array {
        $env = [
            'module'  => Contract::version(),
            'api'     => Contract::API_VERSION,
            'php'     => PHP_VERSION,
            'os'      => PHP_OS,
            'sapi'    => PHP_SAPI,
            'install' => $this->installId(),
            'app'     => mb_substr((string) ($this->opts['app'] ?? ''), 0, 60),
        ];
        if ($this->consent->shareHost()) {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
            if ($host !== '') $env['host'] = preg_replace('~[^\w.:-]~', '', $host);
        }
        return $env;
    }

    /**
     * Anonymous, stable install id: lets the developer tell "one site breaking
     * repeatedly" from "every site breaking once" without identifying anyone.
     */
    public function installId(): string {
        $id = (string) $this->state->get('install_id', '');
        if ($id === '') {
            try { $id = substr(bin2hex(random_bytes(8)), 0, 12); }
            catch (Throwable $e) { $id = substr(hash('sha256', (string) mt_rand() . microtime()), 0, 12); }
            $this->state->set('install_id', $id);
        }
        return $id;
    }
}
