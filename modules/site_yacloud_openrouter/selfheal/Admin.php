<?php
/**
 * Admin — the operator's view: the consent question, the update notice,
 *         «Обновить» / «Откатить», and the local report list.
 * spec: spec/selfheal.md §11
 *
 * Rendered as a self-contained HTML block with scoped class names (`sh-…`) and
 * inline styles only, so it inherits nothing and pollutes nothing in whatever
 * admin panel it is dropped into:
 *
 *   $done = Selfheal\SelfHeal::handleAdminPost($_POST);   // before any output
 *   ...
 *   echo Selfheal\SelfHeal::adminNotice();
 *
 * Every action is a POST carrying a short-lived nonce signed with the
 * per-install secret, so no GET, no session and no host CSRF plumbing needed.
 */

declare(strict_types=1);

namespace Selfheal;

final class Admin {
    public const FIELD = 'selfheal_action';
    public const NONCE = 'selfheal_nonce';

    /** POST handler. Returns null when this POST belongs to the host. */
    public static function handle(array $rt, array $post): ?array {
        $action = (string) ($post[self::FIELD] ?? '');
        if ($action === '') return null;

        /** @var Keys|null $keys */
        $keys = $rt['keys'] ?? null;
        /** @var Consent|null $consent */
        $consent = $rt['consent'] ?? null;
        /** @var Updater|null $updater */
        $updater = $rt['updater'] ?? null;
        /** @var Reporter|null $reporter */
        $reporter = $rt['reporter'] ?? null;
        /** @var State|null $state */
        $state = $rt['state'] ?? null;
        if ($keys === null || $consent === null) return ['ok' => false, 'note' => 'модуль не инициализирован'];

        if (!$keys->grants((string) ($post[self::NONCE] ?? ''), 'admin')) {
            return ['ok' => false, 'note' => 'форма устарела — откройте страницу заново'];
        }

        switch ($action) {
            case 'reporting_on':
                $consent->decide(true, 'admin');
                return ['ok' => true, 'note' => 'отправка отчётов разработчику включена'];

            case 'reporting_off':
                $consent->decide(false, 'admin');
                return ['ok' => true, 'note' => 'отправка отчётов разработчику выключена'];

            case 'share_host_on':
                $consent->setShareHost(true);
                return ['ok' => true, 'note' => 'адрес сайта будет указываться в отчётах'];

            case 'share_host_off':
                $consent->setShareHost(false);
                return ['ok' => true, 'note' => 'адрес сайта больше не указывается в отчётах'];

            case 'flush':
                if ($reporter === null) return ['ok' => false, 'note' => 'репортер недоступен'];
                $r = $reporter->flush(10);
                return ['ok' => (int) $r['failed'] === 0, 'note' => 'отправлено: ' . (int) $r['sent']
                    . ', не удалось: ' . (int) $r['failed']
                    . ((int) $r['skipped'] > 0 ? ' (отправка выключена или нет учётных данных)' : '')];

            case 'forget_reports':
                if ($state === null) return ['ok' => false, 'note' => 'хранилище недоступно'];
                $state->clearQueue();
                return ['ok' => true, 'note' => 'локальная очередь отчётов очищена'];

            case 'check':
                if ($updater === null) return ['ok' => false, 'note' => 'обновления недоступны'];
                $s = $updater->status(true);
                return ['ok' => $s['error'] === '', 'note' => $s['error'] !== ''
                    ? 'проверка: ' . $s['error']
                    : ('установлено ' . $s['current'] . ', в репозитории ' . ($s['remote'] !== '' ? $s['remote'] : '—')
                       . ($s['newer'] ? ' — есть обновление' : ' — актуально'))];

            case 'update':
                if ($updater === null) return ['ok' => false, 'note' => 'обновления недоступны'];
                return $updater->apply(false);

            case 'rollback':
                if ($updater === null) return ['ok' => false, 'note' => 'обновления недоступны'];
                return $updater->rollback((string) ($post['selfheal_backup'] ?? '') ?: null);

            case 'unpin':
                if ($updater === null) return ['ok' => false, 'note' => 'обновления недоступны'];
                $updater->unpin();
                return ['ok' => true, 'note' => 'закрепление снято — обновления снова предлагаются'];

            case 'dismiss':
                if ($updater === null) return ['ok' => false, 'note' => 'обновления недоступны'];
                $updater->dismissNotice();
                return ['ok' => true, 'note' => 'уведомление скрыто до следующей версии'];
        }
        return ['ok' => false, 'note' => 'неизвестное действие'];
    }

    /**
     * The whole block. $opts:
     *   full   bool  also render diagnostics + the report list (default true)
     *   result array the return value of handle(), rendered as a flash line
     */
    public static function notice(array $rt, array $opts = []): string {
        /** @var Consent|null $consent */
        $consent = $rt['consent'] ?? null;
        /** @var Updater|null $updater */
        $updater = $rt['updater'] ?? null;
        /** @var Keys|null $keys */
        $keys = $rt['keys'] ?? null;
        if ($consent === null || $updater === null || $keys === null) return '';

        $full   = (bool) ($opts['full'] ?? true);
        $nonce  = $keys->adminNonce();
        $st     = $updater->status(false);
        $diag   = SelfHeal::diagnostics();
        $result = (array) ($opts['result'] ?? []);

        $h = static function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $o = [];
        $o[] = '<div class="sh-box" style="border:1px solid rgba(127,127,127,.35);border-radius:10px;'
             . 'padding:14px 16px;margin:18px 0;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';
        $o[] = '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;justify-content:space-between;">';
        $o[] = '<b>Самоподдерживающийся модуль «' . $h(Contract::MODULE) . '»</b>';
        $o[] = '<span style="opacity:.7;font-size:12px;">версия ' . $h($st['current'])
             . ' · API ' . $h($st['api']) . ' · установка ' . $h($diag['install_id'] ?? '') . '</span>';
        $o[] = '</div>';

        if ($result) {
            $okFlag = !empty($result['ok']);
            $o[] = '<p style="margin:10px 0 0;padding:8px 10px;border-radius:6px;'
                 . 'background:' . ($okFlag ? 'rgba(46,160,67,.15)' : 'rgba(248,81,73,.15)') . ';">'
                 . ($okFlag ? '✔ ' : '⚠ ') . $h((string) ($result['note'] ?? '')) . '</p>';
        }

        /* ── 1. the consent question, while it is still open ── */
        if ($consent->undecided() && !$consent->pinnedByEnv()) {
            $o[] = '<div style="margin-top:12px;padding:12px;border-radius:8px;background:rgba(255,196,0,.12);">';
            $o[] = '<p style="margin:0 0 8px;"><b>Нужен ваш ответ.</b> ' . $h(Consent::question()) . '</p>';
            $o[] = '<p style="margin:0 0 8px;opacity:.8;font-size:13px;">Пока ответа нет, отчёты пишутся '
                 . '<b>только на этот сервер</b> и никуда не уходят.</p>';
            $o[] = self::form($nonce, [
                ['reporting_on',  'Да, отправлять разработчику', true],
                ['reporting_off', 'Нет, не отправлять', false],
            ]);
            $o[] = '</div>';
        }

        /* ── 2. the update notice ── */
        if ($st['newer'] && $st['dismissed'] !== $st['remote']) {
            $o[] = '<div style="margin-top:12px;padding:12px;border-radius:8px;background:rgba(56,139,253,.14);">';
            $o[] = '<p style="margin:0 0 8px;"><b>Доступно обновление модуля: ' . $h($st['current'])
                 . ' → ' . $h($st['remote']) . '.</b></p>';
            if ((string) $st['notes'] !== '') $o[] = '<p style="margin:0 0 8px;">' . $h($st['notes']) . '</p>';
            if ($st['blockers']) {
                $o[] = '<p style="margin:0 0 8px;color:#b54708;">Автоматически не ставится: '
                     . $h(implode('; ', (array) $st['blockers'])) . '</p>';
            }
            $buttons = [];
            if (!empty($st['update'])) $buttons[] = ['update', 'Обновить до ' . $st['remote'], true];
            if ($st['backups'])        $buttons[] = ['rollback', 'Откатить на ' . (string) $st['backups'][0]['version'], false];
            $buttons[] = ['dismiss', 'Скрыть до следующей версии', false];
            $o[] = self::form($nonce, $buttons);
            $o[] = '</div>';
        }

        if (!$full) { $o[] = '</div>'; return implode("\n", $o); }

        /* ── 3. steady state: what is on, what is off, and the buttons ── */
        $reportLine = [
            Consent::ON  => '<b style="color:#1a7f37;">включена</b> — ошибки модуля уходят в issues репозитория '
                          . $h(Contract::repo()),
            Consent::OFF => '<b>выключена</b> — ничего не отправляется',
            Consent::ASK => '<b>не решено</b> — отчёты копятся локально',
        ][$consent->state()];

        $o[] = '<table style="margin-top:12px;border-collapse:collapse;font-size:13px;width:100%;">';
        $rows = [
            'Отправка отчётов'  => $reportLine . ($consent->pinnedByEnv() ? ' <span style="opacity:.7">(задано через ENV)</span>' : ''),
            'Адрес сайта в отчётах' => $consent->shareHost() ? 'указывается' : 'не указывается',
            'Учётные данные'    => [
                'sealed' => 'зашитый в код зашифрованный токен (' . $h($diag['credential_fp'] ?? '') . ')',
                'local'  => 'локальный токен оператора, token.local.php (' . $h($diag['credential_fp'] ?? '') . ')',
                'env'    => 'токен из переменной окружения',
                'none'   => ($diag['relay'] ?? '') !== '' ? 'через relay-эндпоинт' : '<span style="color:#b54708;">не настроены — отправка невозможна</span>',
            ][(string) ($diag['credential'] ?? 'none')] ?? '—',
            'Перехват ошибок'   => (!empty($diag['capture']) ? 'включён' : 'выключен')
                                 . ', область: ' . $h((string) ($diag['scope'] ?? 'module'))
                                 . ' · поймано за запрос: ' . (int) ($diag['captured'] ?? 0),
            'Локальная очередь' => 'в очереди ' . (int) ($diag['queued'] ?? 0)
                                 . ', отправлено ' . (int) ($diag['sent'] ?? 0)
                                 . ', с ошибкой отправки ' . (int) ($diag['failing'] ?? 0),
            'Обновления'        => 'отслеживается ' . $h((string) $st['repo']) . ' · ' . $h((string) $st['channel'])
                                 . ($st['remote'] !== '' ? ', в репозитории ' . $h((string) $st['remote']) : '')
                                 . ($st['checked_at'] > 0 ? ', проверено ' . $h(date('Y-m-d H:i', (int) $st['checked_at'])) : ', ещё не проверялось')
                                 . (!empty($st['pinned']) ? ' · закреплён отказ от ' . $h((string) $st['pinned']) : '')
                                 . (empty($st['writable']) ? ' · <span style="color:#b54708;">каталог закрыт на запись</span>' : ''),
        ];
        if ((string) $st['error'] !== '')      $rows['Ошибка проверки'] = '<span style="color:#b54708;">' . $h((string) $st['error']) . '</span>';
        if ((string) $st['last_result'] !== '') $rows['Последнее действие'] = $h((string) $st['last_result']);
        foreach ($rows as $k => $v) {
            $o[] = '<tr><td style="padding:3px 10px 3px 0;vertical-align:top;opacity:.7;white-space:nowrap;">'
                 . $h($k) . '</td><td style="padding:3px 0;">' . $v . '</td></tr>';
        }
        $o[] = '</table>';

        $buttons = [['check', 'Проверить обновления', false]];
        if (!empty($st['update'])) $buttons[] = ['update', 'Обновить до ' . $st['remote'], true];
        if ($st['backups'])        $buttons[] = ['rollback', 'Откатить', false];
        if (!empty($st['pinned'])) $buttons[] = ['unpin', 'Снять закрепление', false];
        if ($consent->state() === Consent::ON) {
            $buttons[] = ['flush', 'Отправить очередь сейчас', false];
            $buttons[] = ['reporting_off', 'Отключить отправку', false];
        } elseif ($consent->state() === Consent::OFF) {
            $buttons[] = ['reporting_on', 'Включить отправку', false];
        }
        $buttons[] = [$consent->shareHost() ? 'share_host_off' : 'share_host_on',
                      $consent->shareHost() ? 'Не указывать адрес сайта' : 'Указывать адрес сайта', false];
        if ((int) ($diag['queued'] ?? 0) > 0) $buttons[] = ['forget_reports', 'Забыть локальные отчёты', false];
        $o[] = self::form($nonce, $buttons);

        /* ── 4. what exactly is queued / was sent ── */
        $reports = SelfHeal::recentReports(10);
        if ($reports) {
            $o[] = '<details style="margin-top:10px;"><summary style="cursor:pointer;">Последние отчёты ('
                 . count($reports) . ')</summary>';
            $o[] = '<table style="margin-top:8px;border-collapse:collapse;font-size:12px;width:100%;">';
            foreach ($reports as $r) {
                $stateTxt = $r['sent_at'] !== null
                    ? 'отправлен' . ($r['issue'] !== null ? ' → #' . (int) $r['issue'] : '')
                    : ((int) $r['tries'] > 0 ? 'попыток: ' . (int) $r['tries'] : 'в очереди');
                $o[] = '<tr><td style="padding:2px 8px 2px 0;opacity:.6;white-space:nowrap;">'
                     . $h(date('m-d H:i', (int) $r['ts'])) . '</td>'
                     . '<td style="padding:2px 8px 2px 0;">' . $h(mb_substr((string) $r['title'], 0, 90)) . '</td>'
                     . '<td style="padding:2px 0;opacity:.7;white-space:nowrap;">' . $h($stateTxt) . '</td></tr>';
            }
            $o[] = '</table></details>';
        }

        $o[] = '<p style="margin:10px 0 0;font-size:12px;opacity:.65;">Модуль изолирован: свой namespace '
             . '<code>Selfheal\\</code>, своя база <code>' . $h((string) ($diag['state_db'] ?? '')) . '</code>, '
             . 'обработчики ошибок вызывают обработчики вашего проекта и ничего не подменяют. '
             . 'Обновление ставится только после проверки синтаксиса всех файлов и всегда с резервной копией.</p>';
        $o[] = '</div>';
        return implode("\n", $o);
    }

    /** One POST form with a row of buttons; $primary highlights the main one. */
    private static function form(string $nonce, array $buttons): string {
        $h = static function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $o = ['<form method="post" style="margin:8px 0 0;display:flex;flex-wrap:wrap;gap:8px;">'];
        $o[] = '<input type="hidden" name="' . self::NONCE . '" value="' . $h($nonce) . '">';
        foreach ($buttons as $b) {
            [$action, $label, $primary] = [$b[0] ?? '', $b[1] ?? '', $b[2] ?? false];
            $style = 'padding:7px 13px;border-radius:7px;cursor:pointer;font-size:13px;border:1px solid rgba(127,127,127,.4);'
                   . ($primary ? 'background:#1f6feb;color:#fff;border-color:#1f6feb;' : 'background:transparent;color:inherit;');
            $o[] = '<button type="submit" name="' . self::FIELD . '" value="' . $h($action) . '" style="' . $style . '">'
                 . $h($label) . '</button>';
        }
        $o[] = '</form>';
        return implode('', $o);
    }
}
