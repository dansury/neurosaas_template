<?php
/**
 * Guard — error capture that cannot change the host's behaviour.
 * spec: spec/selfheal.md §9
 *
 * Installing global handlers inside somebody else's project is the single most
 * dangerous thing this module does, so it is done in the most conservative way
 * PHP allows:
 *
 *  1. set_error_handler CHAINS: we record, then call whatever handler was there
 *     before us and return ITS verdict; with no previous handler we return
 *     false, which means "carry on with PHP's normal error handling". Nothing
 *     is swallowed, no display_errors / error_reporting / log_errors is touched.
 *
 *  2. NO exception handler is installed by default. An uncaught exception
 *     already reaches us through error_get_last() in the shutdown function, so
 *     there is nothing to gain and a host handler to break. Opt in with
 *     ['exceptions' => true] if you know your project has none.
 *
 *  3. register_shutdown_function only READS error_get_last(); it never prints,
 *     never exits, never sends headers.
 *
 *  4. SCOPE: by default only errors whose file lives inside the module are
 *     reported. The host's own bugs are its own business — and flooding the
 *     developer's tracker with them would make the whole mechanism useless.
 *
 *  5. Everything runs inside try/catch(Throwable). A failure in the guard is
 *     discarded silently: the module may never become the reason a page dies.
 */

declare(strict_types=1);

namespace Selfheal;

use Throwable;

final class Guard {
    /** Levels worth a report; notices and deprecations are noise. */
    public const REPORTABLE = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR
                            | E_USER_ERROR | E_RECOVERABLE_ERROR | E_WARNING | E_USER_WARNING;

    /** Fatals visible in error_get_last() at shutdown. */
    public const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING
                       | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;

    private Reporter $reporter;
    private array $opts;
    private bool $installed = false;
    /** @var callable|null handler that was in place before us */
    private $previous = null;
    /** @var callable|null exception handler that was in place before us */
    private $previousException = null;
    private int $seen = 0;

    public function __construct(Reporter $reporter, array $opts = []) {
        $this->reporter = $reporter;
        $this->opts     = $opts;
    }

    public function installed(): bool { return $this->installed; }
    public function seen(): int { return $this->seen; }

    public function install(): void {
        if ($this->installed) return;
        $this->installed = true;

        try {
            // (1) chained error handler — our verdict is always the host's verdict.
            $this->previous = set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0) {
                try {
                    if (($no & self::REPORTABLE) !== 0 && $this->mine($file)) {
                        $this->seen++;
                        $this->reporter->record(
                            ($no & (E_WARNING | E_USER_WARNING)) !== 0 ? 'warn' : 'error',
                            $msg,
                            ['file' => $file, 'line' => $line, 'type' => self::levelName($no)],
                            ['errno' => $no],
                            $this->frames()
                        );
                    }
                } catch (Throwable $e) { /* never interfere */ }

                if ($this->previous !== null) {
                    return call_user_func($this->previous, $no, $msg, $file, $line);
                }
                return false;                 // → PHP handles it exactly as before
            });

            // (2) opt-in only, and still delegating.
            if (!empty($this->opts['exceptions'])) {
                $this->previousException = set_exception_handler(function ($e): void {
                    try {
                        if ($e instanceof Throwable && $this->mine((string) $e->getFile())) {
                            $this->capture($e);
                        }
                    } catch (Throwable $ignore) { /* never interfere */ }
                    if ($this->previousException !== null) {
                        call_user_func($this->previousException, $e);
                        return;
                    }
                    // No host handler: reproduce PHP's own behaviour and stop.
                    if ($e instanceof Throwable) {
                        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage()
                            . ' in ' . $e->getFile() . ':' . $e->getLine());
                    }
                });
            }

            // (3) fatals + uncaught exceptions, read-only.
            register_shutdown_function(function (): void {
                try {
                    $last = error_get_last();
                    if (!is_array($last)) return;
                    if ((((int) ($last['type'] ?? 0)) & self::FATAL) === 0) return;
                    if (!$this->mine((string) ($last['file'] ?? ''))) return;
                    $this->seen++;
                    $this->reporter->record('error', (string) ($last['message'] ?? 'fatal'), [
                        'file' => (string) ($last['file'] ?? ''),
                        'line' => (int) ($last['line'] ?? 0),
                        'type' => 'fatal:' . self::levelName((int) ($last['type'] ?? 0)),
                    ], ['shutdown' => true]);
                } catch (Throwable $e) { /* never interfere */ }
            });
        } catch (Throwable $e) {
            // Could not install → the module simply has no automatic capture.
            $this->installed = false;
        }
    }

    /**
     * Explicit capture — the main, fully predictable path. Module code (and a
     * host that wants to) calls this from its own catch blocks.
     * Returns the fingerprint, usable as a support reference.
     */
    public function capture(Throwable $e, array $context = []): string {
        try {
            $this->seen++;
            $ctx = $context;
            $ctx['exception'] = get_class($e);
            if ($e->getCode() !== 0) $ctx['code'] = $e->getCode();
            $prev = $e->getPrevious();
            if ($prev !== null) $ctx['caused_by'] = get_class($prev) . ': ' . mb_substr($prev->getMessage(), 0, 300);
            return $this->reporter->record('error', $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'type' => get_class($e),
            ], $ctx, $e->getTrace());
        } catch (Throwable $ignore) {
            return '';
        }
    }

    /** A message worth the developer's attention that is not an exception. */
    public function note(string $message, array $context = []): string {
        try {
            $at = $this->frames(1);
            $top = $at[0] ?? [];
            return $this->reporter->record('warn', $message, [
                'file' => (string) ($top['file'] ?? ''),
                'line' => (int) ($top['line'] ?? 0),
                'type' => 'note',
            ], $context, $at);
        } catch (Throwable $e) { return ''; }
    }

    /**
     * Is this our file? `scope => 'all'` widens it to the whole project, which
     * is opt-in precisely because it means reporting the host's bugs too.
     */
    private function mine(string $file): bool {
        if (($this->opts['scope'] ?? 'module') === 'all') return true;
        if ($file === '') return false;
        $root = (string) SELFHEAL_MODULE_ROOT;
        return strpos($file, $root . DIRECTORY_SEPARATOR) === 0 || $file === $root;
    }

    /** Caller frames without our own bookkeeping. */
    private function frames(int $drop = 2): array {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        return array_slice($bt, $drop);
    }

    public static function levelName(int $no): string {
        $map = [
            E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE', E_CORE_ERROR => 'E_CORE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR', E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED', E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];
        return $map[$no] ?? ('E_' . $no);
    }
}
