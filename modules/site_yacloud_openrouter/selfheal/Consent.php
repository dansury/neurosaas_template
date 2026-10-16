<?php
/**
 * Consent — whether this install may send error reports to the developer.
 * spec: spec/selfheal.md §7
 *
 * Three states, and the default matters:
 *
 *   ask (default on a fresh install)
 *        Errors are recorded LOCALLY and nothing leaves the server. The admin
 *        panel shows the question; selfheal_install.php asks it out loud; the
 *        instructions in AGENTS.md require the assistant installing the module
 *        to ask the operator in their own words. Reporting can never switch
 *        itself on.
 *   on   Reports go to the issue tracker of the canonical repository. Every
 *        error text shown to an end user says so (see SelfHeal::signError).
 *   off  Nothing is sent, ever. The queue is not even written. The module
 *        keeps working in full; only automatic maintenance stops being
 *        possible, which is exactly what the operator asked for.
 *
 * ENV can pin the answer for a whole fleet: SELFHEAL_REPORTING=on|off|ask.
 * An ENV value wins over the stored one and hides the question.
 */

declare(strict_types=1);

namespace Selfheal;

final class Consent {
    public const ASK = 'ask';
    public const ON  = 'on';
    public const OFF = 'off';

    private const KEY       = 'reporting';
    private const KEY_HOST  = 'share_host';
    private const KEY_WHEN  = 'reporting_decided_at';
    private const KEY_BY    = 'reporting_decided_by';

    private State $state;

    public function __construct(State $state) { $this->state = $state; }

    /** Effective state: ENV pin first, then the operator's stored answer. */
    public function state(): string {
        $env = strtolower(trim((string) (getenv('SELFHEAL_REPORTING') ?: '')));
        if (in_array($env, [self::ON, self::OFF, self::ASK], true)) return $env;
        $stored = strtolower((string) $this->state->get(self::KEY, self::ASK));
        return in_array($stored, [self::ON, self::OFF], true) ? $stored : self::ASK;
    }

    public function pinnedByEnv(): bool {
        $env = strtolower(trim((string) (getenv('SELFHEAL_REPORTING') ?: '')));
        return in_array($env, [self::ON, self::OFF, self::ASK], true);
    }

    public function maySend(): bool { return $this->state() === self::ON; }

    /** In 'ask' we still record locally, so nothing is lost if they say yes. */
    public function mayRecord(): bool { return $this->state() !== self::OFF; }

    public function undecided(): bool { return $this->state() === self::ASK; }

    /** Record the operator's answer. $by is free-form ("setup.php", "installer"). */
    public function decide(bool $on, string $by = 'admin'): void {
        $this->state->set(self::KEY, $on ? self::ON : self::OFF);
        $this->state->set(self::KEY_WHEN, gmdate('Y-m-d\TH:i:s\Z'));
        $this->state->set(self::KEY_BY, mb_substr($by, 0, 60));
    }

    /** Back to the question — used by the installer when re-run. */
    public function reset(): void {
        $this->state->forget(self::KEY);
        $this->state->forget(self::KEY_WHEN);
        $this->state->forget(self::KEY_BY);
    }

    public function decidedAt(): string { return (string) $this->state->get(self::KEY_WHEN, ''); }
    public function decidedBy(): string { return (string) $this->state->get(self::KEY_BY, ''); }

    /**
     * Whether a report may name the site it came from. Useful to the developer,
     * so it defaults to on, but it is the one identifying field in a report and
     * the operator can drop it without switching reporting off entirely.
     */
    public function shareHost(): bool {
        $env = strtolower(trim((string) (getenv('SELFHEAL_SHARE_HOST') ?: '')));
        if ($env === '0' || $env === 'off' || $env === 'false') return false;
        if ($env === '1' || $env === 'on'  || $env === 'true')  return true;
        return (string) $this->state->get(self::KEY_HOST, '1') !== '0';
    }

    public function setShareHost(bool $on): void { $this->state->set(self::KEY_HOST, $on ? '1' : '0'); }

    /** The question, in the words the operator should see. */
    public static function question(): string {
        return 'Отправлять разработчику модуля автоматические отчёты об ошибках '
             . 'этого модуля? Разработчик обещает поддерживать модуль в рабочем состоянии '
             . 'и без отчётов узнать о поломке не может. В отчёт попадают только текст ошибки, '
             . 'файл и строка внутри модуля, версии PHP и модуля — ни данных пользователей, '
             . 'ни ключей, ни содержимого запросов. Отключить можно в любой момент.';
    }
}
