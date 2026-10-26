<?php
declare(strict_types=1);

namespace NeuroSaaS;

// This module is reached only through the host's IP, session and CSRF gates.
if (!defined('NEUROSAAS_ADMIN') && !defined('PULL_LIBRARY_ONLY')) {
    http_response_code(404);
    exit;
}

final class AdminAccount
{
    public const TTL = 600;
    public const ATTEMPTS = 5;

    private static function root(): string { return dirname(__DIR__, 2); }

    /** The PHP envelope prevents HTTP disclosure even without Apache deny rules. */
    public static function transaction(callable $operation)
    {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать защищённую папку учётной записи.');
        }
        @chmod($dir, 0700);
        $lock = fopen($dir . '/lock.php', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Учётная запись занята. Повторите действие.');
        }
        @chmod($dir . '/lock.php', 0600);
        try {
            $path = $dir . '/state.php';
            $state = is_file($path) ? require $path : [];
            if (!is_array($state)) throw new \RuntimeException('Не удалось прочитать учётную запись.');
            $result = $operation($state);
            if (!\save_php_config($path, $state)) throw new \RuntimeException('Не удалось сохранить учётную запись.');
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Injectable only from PHP tests; never from request parameters or environment. */
    public static function issue(array &$state, string $purpose, string $email, string $hash,
                                 string $session, callable $send, ?int $clock = null): void
    {
        $now = $clock ?? time();
        $state['sent'] = array_values(array_filter($state['sent'] ?? [], static fn($t) => $t > $now - 3600));
        if (count($state['sent']) >= 10 || $now - (int)($state['last_sent'] ?? 0) < 60) {
            throw new \RuntimeException('Письмо уже запрошено. Подождите минуту; не более 10 писем в час.');
        }
        $code = (string)random_int(100000, 999999);
        // Keep one active challenge for the installation; a resend revokes the previous one.
        $state['challenge'] = [
            'purpose' => $purpose, 'email' => $email, 'password_hash' => $hash,
            'session' => hash('sha256', $session), 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires' => $now + self::TTL, 'attempts' => self::ATTEMPTS,
        ];
        $send($email, $code);
        $state['sent'][] = $now;
        $state['last_sent'] = $now;
    }

    /** Invalid attempts mutate state and must be persisted by the caller. */
    public static function consume(array &$state, string $purpose, string $code,
                                   string $session, ?int $clock = null): ?array
    {
        $now = $clock ?? time();
        $challenge = $state['challenge'] ?? null;
        if (!is_array($challenge) || $challenge['purpose'] !== $purpose
            || !hash_equals($challenge['session'], hash('sha256', $session))) return null;
        if ($challenge['expires'] <= $now || $challenge['attempts'] <= 0) {
            unset($state['challenge']);
            return null;
        }
        $state['challenge']['attempts']--;
        if (!preg_match('/^[0-9]{6}$/D', $code) || !password_verify($code, $challenge['code_hash'])) {
            if ($state['challenge']['attempts'] <= 0) unset($state['challenge']);
            return null;
        }
        unset($state['challenge']);
        return $challenge;
    }

    private static function deliver(string $email, string $code): void
    {
        $module = dirname(__DIR__) . '/llm_connector';
        $cfg = require $module . '/config.php';
        $text = "Код подтверждения NeuroSaaS: " . $code . "\nКод действует 10 минут. Никому его не сообщайте.";
        if (!empty($cfg['SMTP_USER']) && !empty($cfg['SMTP_PASS'])) {
            require_once $module . '/mailer.php';
            try {
                \Mailer::sendCustom($cfg, $email, 'Код подтверждения NeuroSaaS',
                    '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>', $text);
            } catch (\Throwable $e) {
                throw new \RuntimeException('SMTP delivery failed');
            }
            return;
        }
        if (!function_exists('mail') || !@mail($email,
            '=?UTF-8?B?' . base64_encode('Код подтверждения NeuroSaaS') . '?=',
            $text, "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8")) {
            throw new \RuntimeException('Не удалось отправить письмо. Попросите хостинг включить отправку почты или задайте SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS и SMTP_FROM в окружении PHP. Затем повторите запрос.');
        }
    }

    public static function verified(string $configPath): bool
    {
        $raw = require $configPath;
        return !empty($raw['admin_email_verified']) && filter_var($raw['admin_email'] ?? '', FILTER_VALIDATE_EMAIL);
    }

    /** Enrollment grants management access; email ownership is proved separately by recovery. */
    public static function enrolled(string $configPath): bool
    {
        $raw = require $configPath;
        return (!empty($raw['admin_account_created']) || !empty($raw['admin_email_verified']))
            && filter_var($raw['admin_email'] ?? '', FILTER_VALIDATE_EMAIL)
            && !empty($raw['password_hash']);
    }

    public static function gate(string $configPath, bool $reset = false): void
    {
        if (!$reset && self::enrolled($configPath)) return;
        $message = '';
        $error = false;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)($_POST['account_action'] ?? '');
            try {
                if ((!$reset && $action === 'create') || ($reset && $action === 'send')) {
                    $email = strtolower(trim((string)($_POST['email'] ?? '')));
                    $password = (string)($_POST['account_password'] ?? '');
                    $confirm = (string)($_POST['account_password_confirm'] ?? '');
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
                        throw new \RuntimeException('Введите корректный email.');
                    }
                    if (strlen($password) < 12 || strlen($password) > 512 || !hash_equals($password, $confirm)) {
                        throw new \RuntimeException('Введите одинаковый пароль дважды: от 12 до 512 символов.');
                    }
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    self::transaction(static function (array &$state) use ($configPath, $reset, $email, $hash): void {
                        $raw = require $configPath;
                        if (!$reset) {
                            if (self::enrolled($configPath)) {
                                throw new \RuntimeException('Учётная запись уже создана. Войдите с паролем.');
                            }
                            $raw['admin_email'] = $email;
                            $raw['admin_account_created'] = true;
                            $raw['admin_email_verified'] = false;
                            $raw['password_hash'] = $hash;
                            if (!\save_php_config($configPath, $raw)) {
                                throw new \RuntimeException('Не удалось сохранить учётную запись. Повторите создание.');
                            }
                            unset($state['challenge']);
                            return;
                        }
                        $known = self::enrolled($configPath)
                            && hash_equals(strtolower((string)($raw['admin_email'] ?? '')), $email);
                        self::issue($state, 'reset', $email, $hash, session_id(),
                            static function (string $to, string $code) use ($known): void {
                                if ($known) self::deliver($to, $code);
                            });
                        if (!$known) unset($state['challenge']);
                    });
                    if (!$reset) {
                        \auth_cookie_clear();
                        session_regenerate_id(true);
                        $_SESSION['pull_csrf'] = bin2hex(random_bytes(24));
                        header('Location: ?account_done=1', true, 303);
                        exit;
                    }
                    $message = 'Если адрес зарегистрирован в этом сервисе, мы отправили код. Введите его ниже.';
                } elseif ($reset && $action === 'verify') {
                    $accepted = self::transaction(static function (array &$state) use ($configPath): bool {
                        $challenge = self::consume($state, 'reset', trim((string)($_POST['code'] ?? '')), session_id());
                        if ($challenge === null) return false;
                        $raw = require $configPath;
                        if (!self::enrolled($configPath)
                            || !hash_equals(strtolower((string)($raw['admin_email'] ?? '')), $challenge['email'])) return false;
                        $raw['admin_email_verified'] = true;
                        $raw['password_hash'] = $challenge['password_hash'];
                        if (!\save_php_config($configPath, $raw)) {
                            throw new \RuntimeException('Не удалось сохранить пароль. Повторите запрос кода.');
                        }
                        return true;
                    });
                    if (!$accepted) throw new \RuntimeException('Код неверный, устарел или уже использован. После пяти ошибок запросите новое письмо.');
                    \auth_cookie_clear();
                    session_regenerate_id(true);
                    $_SESSION['pull_csrf'] = bin2hex(random_bytes(24));
                    header('Location: ?account_done=1', true, 303);
                    exit;
                }
            } catch (\Throwable $e) {
                $error = true;
                // Never expose transport exceptions containing a recipient or SMTP credentials.
                $message = $e instanceof \RuntimeException && strpos($e->getMessage(), 'SMTP') === false
                    ? $e->getMessage()
                    : 'Не удалось отправить письмо. Проверьте почту хостинга или переменные SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS и SMTP_FROM в окружении PHP. Повторите запрос.';
            }
        }
        \page_start($reset ? 'Восстановление пароля' : 'Создайте учётную запись администратора');
        echo '<section><p>' . ($reset
            ? 'Укажите email учётной записи и новый пароль. Для восстановления доступа потребуется код из письма.'
            : 'Укажите email и пароль. Первый администратор создаётся без письма и кода подтверждения. После создания войдите с новым паролем. SMTP можно настроить в админке; он понадобится для восстановления пароля.') . '</p>';
        if ($message !== '') echo '<p role="status" class="notice ' . ($error ? 'error' : 'success') . '">' . \h($message) . '</p>';
        echo '<form method="post">' . \csrf_field() . '<input type="hidden" name="account_action" value="' . ($reset ? 'send' : 'create') . '">';
        \field('email', 'Email', '', $reset ? 'На этот адрес придёт код восстановления.' : 'Адрес для восстановления доступа после настройки почты.', 'email');
        \field('account_password', 'Новый пароль', '', 'Не менее 12 символов.', 'password');
        \field('account_password_confirm', 'Повторите пароль', '', '', 'password');
        echo '<button>' . ($reset ? 'Отправить код' : 'Создать администратора') . '</button></form>';
        if ($reset) {
            echo '<form method="post">' . \csrf_field()
                . '<input type="hidden" name="account_action" value="verify"><label for="account-code">Код из письма</label>'
                . '<input id="account-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>'
                . '<button>Подтвердить</button></form>';
        }
        echo '<p><a href="?">Вернуться ко входу</a></p></section>';
        \page_end();
        exit;
    }
}
