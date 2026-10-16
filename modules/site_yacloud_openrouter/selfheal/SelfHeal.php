<?php
/**
 * SelfHeal — the public façade. Host projects call this class and nothing else.
 * spec: spec/selfheal.md §1
 *
 * Every method here is listed in Contract::PUBLIC_API and is covered by the
 * semver promise of Contract::API_VERSION. Everything else in this directory
 * is internal and changes without notice.
 *
 *   require_once __DIR__ . '/selfheal/bootstrap.php';
 *   Selfheal\SelfHeal::boot(['app' => 'my-project']);
 *
 * boot() is optional for reading state and mandatory for automatic capture.
 * Calling any method before boot() is safe — it boots lazily with defaults.
 *
 * Nothing in this class throws. Ever. A self-maintaining module that takes the
 * host down with it is worse than no module at all, so every entry point is
 * wrapped and every failure degrades to "the feature is off".
 */

declare(strict_types=1);

namespace Selfheal;

use Throwable;

final class SelfHeal {
    private static ?State $state = null;
    private static ?Consent $consent = null;
    private static ?Reporter $reporter = null;
    private static ?Guard $guard = null;
    private static ?Keys $keys = null;
    private static ?Updater $updater = null;
    private static array $opts = [];
    private static bool $booted = false;
    private static string $bootError = '';

    /* ─────────────────────────── lifecycle ─────────────────────────── */

    /**
     * Wire the module up.
     *
     * $opts (all optional):
     *   app          string  name of the host project, shown in reports
     *   db           string  path to OUR state file (default data/selfheal.db)
     *   capture      bool    install the chained error handlers (default true)
     *   exceptions   bool    also install an exception handler (default false —
     *                        uncaught exceptions already arrive via shutdown)
     *   scope        string  'module' (default) | 'all' — whose errors to report
     *   trace_scope  string  'module' (default) | 'all' — whose frames to send
     *   secrets      array   extra strings to mask in everything outgoing
     *   flush_per_request int reports pushed per request (default 2)
     *   work_dir     string  staging/backup directory for the updater
     */
    public static function boot(array $opts = []): bool {
        if (self::$booted) return true;
        self::$booted = true;
        try {
            self::$opts = $opts + [
                'app'         => '',
                'capture'     => true,
                'exceptions'  => false,
                'scope'       => 'module',
                'trace_scope' => 'module',
            ];
            $db = (string) ($opts['db'] ?? (SELFHEAL_MODULE_ROOT . '/data/selfheal.db'));
            self::$state    = new State($db);
            self::$consent  = new Consent(self::$state);
            self::$keys     = new Keys(self::$state);
            self::$reporter = new Reporter(self::$state, self::$consent, self::$opts);
            self::$guard    = new Guard(self::$reporter, self::$opts);
            self::$updater  = new Updater(self::$state, self::$opts);

            foreach ((array) ($opts['secrets'] ?? []) as $s) Scrub::addSecret((string) $s);
            Scrub::addSecret(Vault::token());          // our own credential, never in a report

            if (!empty(self::$opts['capture'])) self::$guard->install();
            return true;
        } catch (Throwable $e) {
            self::$bootError = mb_substr($e->getMessage(), 0, 200);
            return false;
        }
    }

    public static function booted(): bool { return self::$booted && self::$state !== null; }

    /** Lazy boot so no public method can fail just because boot() was skipped. */
    private static function ready(): bool {
        if (!self::$booted) self::boot(['capture' => false]);
        return self::$state !== null;
    }

    public static function version(): string { return Contract::version(); }
    public static function apiVersion(): string { return Contract::API_VERSION; }
    public static function module(): string { return Contract::MODULE; }
    public static function supports(string $feature): bool { return Contract::supports($feature); }

    /** Host-side compatibility gate: `if (!SelfHeal::fits('^1.0')) { ... }`. */
    public static function fits(string $constraint): bool { return Contract::fits($constraint); }

    /* ─────────────────────────── errors ─────────────────────────── */

    /** Report an exception. Returns a short reference to show the user. */
    public static function capture(Throwable $e, array $context = []): string {
        if (!self::ready() || self::$guard === null) return '';
        return self::$guard->capture($e, $context);
    }

    /** Report something noteworthy that is not an exception. */
    public static function note(string $message, array $context = []): string {
        if (!self::ready() || self::$guard === null) return '';
        return self::$guard->note($message, $context);
    }

    /**
     * Sign a user-facing error text, as the module promises to.
     * The user always learns what happens to their error — including when
     * sending is switched off, so the notice can never be quietly misleading.
     */
    public static function signError(string $userMessage, ?string $ref = null): string {
        $userMessage = rtrim($userMessage);
        if (!self::ready() || self::$consent === null) return $userMessage;
        $ref = $ref !== null && $ref !== '' ? $ref : (self::$reporter !== null ? (string) self::$reporter->lastRef() : '');
        $tail = $ref !== '' ? ' Код обращения: ' . $ref . '.' : '';

        switch (self::$consent->state()) {
            case Consent::ON:
                $sig = 'Ошибка зафиксирована и отправлена разработчику модуля «' . Contract::MODULE
                     . '» — он поддерживает модуль автоматически.' . $tail
                     . ' Отправку отчётов можно отключить в админке.';
                break;
            case Consent::OFF:
                $sig = 'Ошибка зафиксирована только на этом сервере: отправка отчётов разработчику отключена.' . $tail;
                break;
            default:
                $sig = 'Ошибка зафиксирована на этом сервере. Отправка отчётов разработчику пока не разрешена —'
                     . ' решение принимается в админке.' . $tail;
        }
        $custom = (string) (self::$opts['sign_suffix'] ?? '');
        if ($custom !== '') $sig = $custom . ($ref !== '' ? $tail : '');
        return $userMessage === '' ? $sig : $userMessage . "\n\n" . $sig;
    }

    /** capture() + signError() in one call — the usual thing to show a user. */
    public static function userMessage(Throwable $e, string $prefix = '', array $context = []): string {
        $ref  = self::capture($e, $context);
        $text = $prefix !== '' ? $prefix : 'Не удалось выполнить операцию.';
        return self::signError($text, $ref);
    }

    /** Push queued reports now (cron, admin button). */
    public static function flush(int $limit = 5): array {
        if (!self::ready() || self::$reporter === null) return ['sent' => 0, 'failed' => 0, 'skipped' => 1, 'errors' => []];
        return self::$reporter->flush($limit);
    }

    /* ─────────────────────────── consent ─────────────────────────── */

    /** 'ask' | 'on' | 'off'. */
    public static function reporting(): string {
        if (!self::ready() || self::$consent === null) return Consent::ASK;
        return self::$consent->state();
    }

    public static function setReporting(bool $on, string $by = 'api'): void {
        if (!self::ready() || self::$consent === null) return;
        self::$consent->decide($on, $by);
    }

    public static function reportingQuestion(): string { return Consent::question(); }

    /* ─────────────────────────── capability keys ─────────────────────────── */

    /** Mint an opaque key for a neighbouring module. '' when caps are unknown. */
    public static function issueKey(array $capabilities, int $ttlSeconds = 0): string {
        if (!self::ready() || self::$keys === null) return '';
        return self::$keys->issue($capabilities, $ttlSeconds);
    }

    public static function grants(string $key, string $capability): bool {
        if (!self::ready() || self::$keys === null) return false;
        return self::$keys->grants($key, $capability);
    }

    /* ─────────────────────────── updates ─────────────────────────── */

    public static function updateStatus(bool $force = false): array {
        if (!self::ready() || self::$updater === null) return ['current' => Contract::version(), 'update' => false, 'error' => 'модуль не инициализирован'];
        return self::$updater->status($force);
    }

    /** Apply the tracked release. Requires a key carrying 'updates.apply'. */
    public static function applyUpdate(string $key, bool $force = false): array {
        if (!self::ready() || self::$updater === null) return ['ok' => false, 'note' => 'модуль не инициализирован'];
        if (!self::grants($key, 'updates.apply')) return ['ok' => false, 'note' => 'нет права updates.apply'];
        return self::$updater->apply($force);
    }

    /** Restore the previous version. Requires 'updates.apply'. */
    public static function rollback(string $key, ?string $backup = null): array {
        if (!self::ready() || self::$updater === null) return ['ok' => false, 'note' => 'модуль не инициализирован'];
        if (!self::grants($key, 'updates.apply')) return ['ok' => false, 'note' => 'нет права updates.apply'];
        return self::$updater->rollback($backup);
    }

    /* ─────────────────────────── admin surface ─────────────────────────── */

    /**
     * Ready-made HTML block: the consent question while it is open, the
     * "new version" notice with «Обновить» / «Откатить», and a short status.
     * Returns '' when there is nothing to say, so it can be echoed blindly.
     */
    public static function adminNotice(array $opts = []): string {
        if (!self::ready()) return '';
        try { return Admin::notice(self::runtime(), $opts); }
        catch (Throwable $e) { return ''; }
    }

    /** Handle our own admin POST. Returns null when the POST was not ours. */
    public static function handleAdminPost(array $post): ?array {
        if (!self::ready()) return null;
        try { return Admin::handle(self::runtime(), $post); }
        catch (Throwable $e) { return ['ok' => false, 'note' => 'действие не выполнено']; }
    }

    /* ─────────────────────────── introspection ─────────────────────────── */

    /** Everything an operator (or a support chat) needs in one array. */
    public static function diagnostics(): array {
        if (!self::ready() || self::$state === null) {
            return ['booted' => false, 'error' => self::$bootError];
        }
        $stats = self::$state->queueStats();
        return [
            'booted'        => true,
            'module'        => Contract::MODULE,
            'version'       => Contract::version(),
            'api_version'   => Contract::API_VERSION,
            'repo'          => Contract::repo(),
            'channel'       => Contract::channel(),
            'php'           => PHP_VERSION,
            'state_db'      => Scrub::paths(self::$state->path()),
            'state_ok'      => self::$state->ready(),
            'install_id'    => self::$reporter !== null ? self::$reporter->installId() : '',
            'reporting'     => self::reporting(),
            'reporting_env' => self::$consent !== null && self::$consent->pinnedByEnv(),
            'decided_at'    => self::$consent !== null ? self::$consent->decidedAt() : '',
            'share_host'    => self::$consent !== null && self::$consent->shareHost(),
            'credential'    => Vault::source(),
            'credential_fp' => Vault::fingerprint(),
            'relay'         => Vault::relay() !== '' ? 'настроен' : '',
            'can_send'      => self::$reporter !== null && self::$reporter->canSend(),
            'capture'       => self::$guard !== null && self::$guard->installed(),
            'captured'      => self::$guard !== null ? self::$guard->seen() : 0,
            'queued'        => (int) $stats['queued'],
            'sent'          => (int) $stats['sent'],
            'failing'       => (int) $stats['failing'],
            'last_flush_at' => (string) self::$state->get('last_flush_at', ''),
            'scope'         => (string) (self::$opts['scope'] ?? 'module'),
            'features'      => Contract::FEATURES,
        ];
    }

    /** Recent local reports for the admin panel. */
    public static function recentReports(int $limit = 20): array {
        if (!self::ready() || self::$state === null) return [];
        return self::$state->recent($limit);
    }

    /* ─────────────────────────── internal wiring ─────────────────────────── */

    /** Internal bundle handed to Admin — NOT part of the public contract. */
    public static function runtime(): array {
        return [
            'state' => self::$state, 'consent' => self::$consent, 'reporter' => self::$reporter,
            'guard' => self::$guard, 'keys' => self::$keys, 'updater' => self::$updater,
            'opts' => self::$opts,
        ];
    }

    /**
     * Bridge used by the module's own DiagLog: an error already logged locally
     * becomes a report without DiagLog having to know anything about us.
     * Internal; hosts call capture()/note() instead.
     */
    public static function forward(string $level, string $channel, string $message, array $context = []): void {
        try {
            if (!self::$booted || self::$reporter === null) return;   // no lazy boot: stay invisible
            if ($level !== 'error') return;
            self::$reporter->record('error', $message, ['file' => 'diag:' . $channel, 'line' => 0, 'type' => 'diag'], $context);
        } catch (Throwable $e) { /* never interfere */ }
    }

    /** Test seam: forget the booted runtime. Not for production use. */
    public static function resetForTests(): void {
        self::$state = null; self::$consent = null; self::$reporter = null;
        self::$guard = null; self::$keys = null; self::$updater = null;
        self::$opts = []; self::$booted = false; self::$bootError = '';
        Scrub::forgetSecrets();
    }
}
