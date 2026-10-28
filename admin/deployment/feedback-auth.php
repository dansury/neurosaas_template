<?php
declare(strict_types=1);

final class FeedbackAuth
{
    private static function key(): string
    {
        $dir = PULL_ROOT . '/admin/account/data';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Не удалось подготовить защищённое хранилище.');
        $path = $dir . '/feedback.key';
        if (!is_file($path)) {
            $handle = @fopen($path, 'x');
            if ($handle) { @chmod($path, 0600); fwrite($handle, random_bytes(32)); fclose($handle); }
        }
        $key = (string)@file_get_contents($path);
        if (strlen($key) !== 32) throw new RuntimeException('Загрузите ключ расшифровки обращений ниже.');
        return $key;
    }

    public static function seal(string $token): string
    {
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('Для защищённого хранения обращений включите PHP OpenSSL.');
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($token, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'feedback-issue-v1');
        if ($encrypted === false) throw new RuntimeException('Не удалось зашифровать ключ GitHub.');
        return base64_encode($iv . $tag . $encrypted);
    }

    public static function unseal(string $value): string
    {
        if (!function_exists('openssl_decrypt') || !is_file(PULL_ROOT.'/admin/account/data/feedback.key')) return '';
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) return '';
        $token = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'feedback-issue-v1');
        return $token === false ? '' : $token;
    }

    public static function token(string $existing): string
    {
        $provided = (string)getenv('FEEDBACK_GITHUB_TOKEN');
        $path = PULL_ROOT . '/admin/account/data/feedback-credential.enc';
        if ($provided !== '') {
            $sealed = self::seal($provided);
            $tmp = $path . '.' . bin2hex(random_bytes(6));
            if (file_put_contents($tmp, $sealed, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить ключ обращений.');
            @chmod($tmp, 0600);
            if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Не удалось сохранить ключ обращений.'); }
            return $provided;
        }
        foreach ([$path, PULL_ROOT . '/admin/deployment/feedback-credential.enc'] as $candidate) {
            if (!is_file($candidate)) continue;
            $token = self::unseal(trim((string)file_get_contents($candidate)));
            if ($token !== '') return $token;
        }
        return $existing;
    }

    /** Authenticated owner imports only the 32-byte decryption key, never a browser-visible token. */
    public static function importKey(): void
    {
        try {
            $upload = $_FILES['feedback_key'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) !== 32) throw new RuntimeException('Выберите файл feedback.key, выданный отдельно от репозитория.');
            $key = (string)file_get_contents($upload['tmp_name']);
            $bundled = PULL_ROOT . '/admin/deployment/feedback-credential.enc';
            $raw = is_file($bundled) ? base64_decode(trim((string)file_get_contents($bundled)), true) : false;
            if ($raw === false || strlen($raw) < 29 || openssl_decrypt(substr($raw,28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16), 'feedback-issue-v1') === false) throw new RuntimeException('Этот файл не подходит к зашифрованному ключу обращений.');
            self::key();
            $path = PULL_ROOT . '/admin/account/data/feedback.key';
            $tmp = $path . '.' . bin2hex(random_bytes(6));
            if (file_put_contents($tmp, $key, LOCK_EX) !== 32) throw new RuntimeException('Не удалось сохранить ключ расшифровки.');
            @chmod($tmp, 0600);
            if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Не удалось установить ключ расшифровки.'); }
            render_feedback('Ключ обращений подключён. Теперь можно отправлять задачи из формы.');
        } catch (Throwable $ex) { render_feedback($ex->getMessage(), true); }
    }

    public static function controls(): void
    {
        global $config;
        $repo = (string)($config['repo'] ?? '');
        if ($repo === '') $repo = (string)getenv('FEEDBACK_GITHUB_REPO');
        $destination = preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~D', $repo) ? 'https://github.com/'.$repo.'/issues/new' : 'https://github.com/';
        $ready=false; try { $ready=self::token('')!==''; } catch (Throwable $ex) {}
        echo '<aside class="notice"><h3>Отправка задач в GitHub</h3><p>'.($ready?'Серверный ключ обращений подключён.':'Для отправки этой формой подключите файл расшифровки ниже.').'</p><p><a class="button" href="https://github.com/login?return_to='.rawurlencode($destination).'" target="_blank" rel="noopener">Войти в GitHub</a></p><p>После входа можно создать задачу на GitHub от своего имени. Кнопка отправки формы ниже использует серверный ключ обращений, включая отправку до входа.</p>';
        echo '<details><summary>Подключить ключ обращений</summary><p>Один раз загрузите отдельный файл feedback.key. В репозитории хранится только зашифрованный токен; файл расшифровки хранится в закрытых данных сервера и сохраняется при обновлениях.</p><form method="post" action="?feedback=1" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="action" value="feedback_key"><label>Файл расшифровки<input type="file" name="feedback_key" required></label><button>Подключить ключ</button></form></details></aside>';
    }
}


