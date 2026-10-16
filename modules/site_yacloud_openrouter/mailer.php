<?php
/**
 * Mailer — minimal pure-PHP SMTP client (no Composer).
 * spec: spec/mailer.md — infrastructural code, no customer TZ behind it.
 * (Extracted from resume-index/mailer.php; report-specific senders removed.)
 *
 * Handles AUTH LOGIN over implicit-TLS (port 465) or STARTTLS (587/25/2525).
 * Covers Yandex SMTP (465/587) and most shared hosts. All senders read the
 * $cfg array from config.php (SMTP_HOST/PORT/USER/PASS/FROM/FROM_NAME …).
 *
 * Public senders:
 *   sendCustom($cfg, $to, $subject, $html, $text, $extraHeaders=[])   — HTML+plain
 *   sendWithAttachment($cfg, $to, $subject, $bodyText, $name, $path)  — file attach
 *   sendTest($cfg, $to)                                               — SMTP probe (throws)
 *   sendErrorNotification($cfg, $context, $message, $extra=[])        — throttled alert
 *
 * Utilities: mdToHtmlEmail($markdown), buildMimeMessage(...).
 */

final class Mailer {
    /**
     * Error-notification email → $cfg['ERROR_EMAIL']. Best-effort: never throws,
     * returns false on any problem so callers can fire-and-forget. Throttled
     * persistently via $cfg['LOG_DIR']: one email per unique error per scope
     * (24 h) and globally ≤1 per minute.
     */
    public static function sendErrorNotification(array $cfg, string $context, string $message, array $extra = []): bool {
        static $sent = [];
        $to = trim((string) ($cfg['ERROR_EMAIL'] ?? ''));
        if ($to === '') return false;
        if (empty($cfg['SMTP_HOST']) || empty($cfg['SMTP_USER']) || empty($cfg['SMTP_PASS'])) {
            error_log('[mailer error-notify] SMTP not configured — error email skipped (' . $context . ')');
            return false;
        }
        $scope = '';
        foreach (['session_id', 'email'] as $k) {
            if (isset($extra[$k]) && (is_scalar($extra[$k]) || $extra[$k] === null)) $scope .= '|' . $extra[$k];
        }
        $dedupe = md5($context . '|' . $message . $scope);
        if (isset($sent[$dedupe])) return true;
        $sent[$dedupe] = true;
        if (!self::errorEmailAllowed($cfg, $dedupe, $context)) return false;

        $from_addr = $cfg['SMTP_FROM'] ?: $cfg['SMTP_USER'];
        $from_name = $cfg['SMTP_FROM_NAME'] ?? 'site_yacloud_openrouter';
        $subject = '[site_yacloud_openrouter · ОШИБКА] ' . $context;

        $lines = [];
        $lines[] = 'message : ' . $message;
        $lines[] = 'time    : ' . gmdate('Y-m-d H:i:s\Z');
        foreach ($extra as $k => $v) {
            if (!is_scalar($v) && $v !== null) continue;
            $v = trim((string) $v);
            if ($v === '' || $v === '—') continue;
            $lines[] = str_pad((string) $k, 8) . ': ' . $v;
        }
        $body_text = implode("\r\n", $lines);

        $headers = [
            'From: ' . self::encodeHeader($from_name) . ' <' . $from_addr . '>',
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
            'Message-ID: <err_' . bin2hex(random_bytes(8)) . '@' . self::messageIdHost($from_addr) . '>',
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body_text . "\r\n";
        try {
            self::smtpSend($cfg, $from_addr, $to, $payload);
            return true;
        } catch (Throwable $e) {
            error_log('[mailer error-notify] send failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Persistent throttle gate for sendErrorNotification (LOG_DIR/error_email_throttle.json).
     *  Returns true when the email may be sent now. Fail-open on any I/O error. */
    private static function errorEmailAllowed(array $cfg, string $dedupe, string $context): bool {
        $dir = (string) ($cfg['LOG_DIR'] ?? '');
        if ($dir === '') return true;
        @mkdir($dir, 0775, true);
        $path = rtrim($dir, '/') . '/error_email_throttle.json';
        $now = time();
        $min_interval = 60;
        $key_ttl = 24 * 3600;
        $fp = @fopen($path, 'c+');
        if (!$fp) return true;
        try {
            if (!flock($fp, LOCK_EX)) return true;
            $raw = stream_get_contents($fp);
            $state = json_decode((string) $raw, true);
            if (!is_array($state)) $state = ['last_sent_at' => 0, 'keys' => []];
            $keys = is_array($state['keys'] ?? null) ? $state['keys'] : [];
            foreach ($keys as $k => $ts) {
                if (!is_int($ts) && !is_numeric($ts)) { unset($keys[$k]); continue; }
                if ($now - (int) $ts > $key_ttl) unset($keys[$k]);
            }
            if (isset($keys[$dedupe])) {
                error_log('[mailer error-notify] suppressed duplicate (' . $context . ')');
                return false;
            }
            if ($now - (int) ($state['last_sent_at'] ?? 0) < $min_interval) {
                error_log('[mailer error-notify] suppressed by 1/min rate limit (' . $context . ')');
                return false;
            }
            $keys[$dedupe] = $now;
            $state = ['last_sent_at' => $now, 'keys' => $keys];
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($state));
            return true;
        } catch (Throwable $e) {
            return true;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * Generic HTML+plaintext sender. Supports arbitrary extra headers — notably
     * List-Unsubscribe / List-Unsubscribe-Post for one-click opt-out.
     * Throws on SMTP failure.
     */
    public static function sendCustom(array $cfg, string $to, string $subject,
                                      string $html, string $text, array $extraHeaders = []): void {
        if (empty($cfg['SMTP_HOST']) || empty($cfg['SMTP_USER']) || empty($cfg['SMTP_PASS'])) {
            throw new RuntimeException('smtp_not_configured');
        }
        if ($to === '') throw new RuntimeException('email_empty');
        $from_addr = $cfg['SMTP_FROM'] ?: $cfg['SMTP_USER'];
        $from_name = $cfg['SMTP_FROM_NAME'] ?? 'site_yacloud_openrouter';
        $alt = 'fa_' . bin2hex(random_bytes(8));
        $base = [
            'From: ' . self::encodeHeader($from_name) . ' <' . $from_addr . '>',
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Message-ID: <msg_' . bin2hex(random_bytes(8)) . '@' . self::messageIdHost($from_addr) . '>',
        ];
        $hasReplyTo = false;
        foreach ($extraHeaders as $h) { if (stripos((string) $h, 'reply-to:') === 0) { $hasReplyTo = true; break; } }
        if (!$hasReplyTo) $base[] = 'Reply-To: ' . $from_addr;
        $headers = array_merge($base, $extraHeaders, [
            'Content-Type: multipart/alternative; boundary="' . $alt . '"',
        ]);
        $body = [];
        $body[] = '--' . $alt;
        $body[] = 'Content-Type: text/plain; charset=utf-8';
        $body[] = 'Content-Transfer-Encoding: 8bit';
        $body[] = '';
        $body[] = $text;
        $body[] = '';
        $body[] = '--' . $alt;
        $body[] = 'Content-Type: text/html; charset=utf-8';
        $body[] = 'Content-Transfer-Encoding: 8bit';
        $body[] = '';
        $body[] = $html;
        $body[] = '--' . $alt . '--';
        $payload = implode("\r\n", array_merge($headers, [''], $body)) . "\r\n";
        self::smtpSend($cfg, $from_addr, $to, $payload);
    }

    /**
     * multipart/alternative (plain+html), wrapped in multipart/mixed when a file
     * attachment ['path','filename','mime'] is supplied. Returns the raw payload.
     */
    public static function buildMimeMessage(
        string $from_addr, string $from_name, string $to, string $subject,
        string $id_prefix, string $plain, string $html_body, ?array $attachment = null
    ): string {
        $alt = 'ra_' . bin2hex(random_bytes(8));
        $alt_body = [];
        $alt_body[] = '--' . $alt;
        $alt_body[] = 'Content-Type: text/plain; charset=utf-8';
        $alt_body[] = 'Content-Transfer-Encoding: 8bit';
        $alt_body[] = '';
        $alt_body[] = $plain;
        $alt_body[] = '';
        $alt_body[] = '--' . $alt;
        $alt_body[] = 'Content-Type: text/html; charset=utf-8';
        $alt_body[] = 'Content-Transfer-Encoding: 8bit';
        $alt_body[] = '';
        $alt_body[] = $html_body;
        $alt_body[] = '--' . $alt . '--';

        $headers = [
            'From: ' . self::encodeHeader($from_name) . ' <' . $from_addr . '>',
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Message-ID: <' . $id_prefix . '_' . bin2hex(random_bytes(6)) . '@' . self::messageIdHost($from_addr) . '>',
            // Deliverability: monitored Reply-To + unsubscribe affordance.
            'Reply-To: ' . $from_addr,
            'List-Unsubscribe: <mailto:' . $from_addr . '?subject=unsubscribe>',
        ];

        $att_data = ($attachment && !empty($attachment['path']) && is_file($attachment['path'])) ? file_get_contents($attachment['path']) : false;
        if ($att_data === false) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
            return implode("\r\n", array_merge($headers, [''], $alt_body)) . "\r\n";
        }

        $mixed = 'rm_' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';
        $fname = (string) ($attachment['filename'] ?? 'attachment');
        $fname_hdr = '=?UTF-8?B?' . base64_encode($fname) . '?=';
        $att_mime = (string) ($attachment['mime'] ?? 'application/octet-stream');
        $body = [];
        $body[] = '--' . $mixed;
        $body[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
        $body[] = '';
        $body = array_merge($body, $alt_body);
        $body[] = '';
        $body[] = '--' . $mixed;
        $body[] = 'Content-Type: ' . $att_mime . '; name="' . $fname_hdr . '"';
        $body[] = 'Content-Transfer-Encoding: base64';
        $body[] = 'Content-Disposition: attachment; filename="' . $fname_hdr . '"';
        $body[] = '';
        $body[] = chunk_split(base64_encode($att_data), 76, "\r\n");
        $body[] = '--' . $mixed . '--';
        return implode("\r\n", array_merge($headers, [''], $body)) . "\r\n";
    }

    /** Send an email with a single file attachment + plain-text body. Returns
     *  false on read/send failure (logged), true on success. */
    public static function sendWithAttachment(
        array $cfg, string $to, string $subject, string $body_text,
        string $original_name, string $stored_path
    ): bool {
        if (empty($cfg['SMTP_HOST']) || empty($cfg['SMTP_USER']) || empty($cfg['SMTP_PASS'])) {
            error_log('[mailer] SMTP not configured — skipped');
            return false;
        }
        $to = $to ?: (string) ($cfg['ADMIN_EMAIL'] ?? '');
        if ($to === '') { error_log('[mailer] no recipient'); return false; }
        $attachment_data = @file_get_contents($stored_path);
        if ($attachment_data === false) {
            error_log('[mailer] cannot read stored file: ' . $stored_path);
            return false;
        }
        $from_addr = $cfg['SMTP_FROM'] ?: $cfg['SMTP_USER'];
        $from_name = $cfg['SMTP_FROM_NAME'] ?? 'site_yacloud_openrouter';
        $attachment_b64 = chunk_split(base64_encode($attachment_data));
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $mime = $ext === 'pdf'
            ? 'application/pdf'
            : ($ext === 'docx'
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                : 'application/octet-stream');

        $boundary = 'rb_' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . self::encodeHeader($from_name) . ' <' . $from_addr . '>',
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . self::messageIdHost($from_addr) . '>',
            'Reply-To: ' . $from_addr,
        ];
        $m = [];
        $m[] = '--' . $boundary;
        $m[] = 'Content-Type: text/plain; charset=utf-8';
        $m[] = 'Content-Transfer-Encoding: 8bit';
        $m[] = '';
        $m[] = $body_text;
        $m[] = '';
        $m[] = '--' . $boundary;
        $m[] = 'Content-Type: ' . $mime . '; name="' . self::sanitizeFilename($original_name) . '"';
        $m[] = 'Content-Transfer-Encoding: base64';
        $m[] = 'Content-Disposition: attachment; filename="' . self::sanitizeFilename($original_name) . '"';
        $m[] = '';
        $m[] = $attachment_b64;
        $m[] = '--' . $boundary . '--';

        $payload = implode("\r\n", array_merge($headers, [''], $m)) . "\r\n";
        try {
            self::smtpSend($cfg, $from_addr, $to, $payload);
            return true;
        } catch (Throwable $e) {
            error_log('[mailer] send failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Diagnostic test letter. THROWS on failure so the operator sees the error. */
    public static function sendTest(array $cfg, string $to): void {
        if (empty($cfg['SMTP_HOST']) || empty($cfg['SMTP_USER']) || empty($cfg['SMTP_PASS'])) {
            throw new RuntimeException('SMTP не настроен: заполните SMTP host / user / password');
        }
        $from_addr = $cfg['SMTP_FROM'] ?: $cfg['SMTP_USER'];
        $from_name = $cfg['SMTP_FROM_NAME'] ?? 'site_yacloud_openrouter';
        $body = "Тестовое письмо из site_yacloud_openrouter.\r\n"
              . 'host=' . $cfg['SMTP_HOST'] . ' port=' . $cfg['SMTP_PORT']
              . ' user=' . $cfg['SMTP_USER'] . ' from=' . $from_addr . "\r\n"
              . 'time=' . gmdate('Y-m-d H:i:s\Z') . "\r\n";
        $headers = [
            'From: ' . self::encodeHeader($from_name) . ' <' . $from_addr . '>',
            'To: ' . $to,
            'Subject: ' . self::encodeHeader('[site_yacloud_openrouter] Тест SMTP'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
            'Message-ID: <test_' . bin2hex(random_bytes(8)) . '@' . self::messageIdHost($from_addr) . '>',
        ];
        self::smtpSend($cfg, $from_addr, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n");
    }

    private static function encodeHeader(string $value): string {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private static function sanitizeFilename(string $name): string {
        $name = preg_replace('/[\r\n"]/', '', $name);
        return $name === null ? 'attachment' : $name;
    }

    /** Host part for the Message-ID — the From address's own domain, so the
     *  Message-ID domain ALIGNS with the sending identity (spam-filter signal). */
    private static function messageIdHost(string $from_addr): string {
        $at = strrpos($from_addr, '@');
        $host = $at !== false ? trim(substr($from_addr, $at + 1)) : '';
        return ($host !== '' && preg_match('/^[A-Za-z0-9.\-]+$/', $host)) ? $host : 'localhost';
    }

    /**
     * Bare-bones SMTP/AUTH LOGIN. Port 465 — implicit TLS; any other port
     * (587, 25, 2525 …) — STARTTLS when advertised (mandatory on 587).
     */
    private static function smtpSend(array $cfg, string $from_addr, string $to, string $payload): void {
        $host = $cfg['SMTP_HOST'];
        $port = (int) $cfg['SMTP_PORT'];
        $user = $cfg['SMTP_USER'];
        $pass = $cfg['SMTP_PASS'];
        $ehloHost = self::messageIdHost($from_addr);

        $scheme = $port === 465 ? 'ssl://' : '';
        $remote = $scheme . $host . ':' . $port;
        $err_code = 0; $err_str = '';
        $sock = @stream_socket_client($remote, $err_code, $err_str, 15, STREAM_CLIENT_CONNECT);
        if (!$sock) throw new RuntimeException("smtp connect failed: $err_str ($err_code)");
        stream_set_timeout($sock, 30);

        $expect = function (int $want) use ($sock) {
            $line = '';
            do {
                $part = fgets($sock, 1024);
                if ($part === false) throw new RuntimeException('smtp read failed');
                $line .= $part;
                $code = (int) substr($part, 0, 3);
                $cont = isset($part[3]) && $part[3] === '-';
            } while ($cont);
            if ($code !== $want) throw new RuntimeException("smtp expected $want, got: " . trim($line));
            return $line;
        };
        $send = function (string $cmd) use ($sock) {
            if (fwrite($sock, $cmd . "\r\n") === false) throw new RuntimeException('smtp write failed');
        };

        $expect(220);
        $send('EHLO ' . $ehloHost);
        $ehlo = $expect(250);
        if ($port !== 465 && ($port === 587 || stripos($ehlo, 'STARTTLS') !== false)) {
            $send('STARTTLS'); $expect(220);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('starttls failed');
            }
            $send('EHLO ' . $ehloHost); $expect(250);
        }
        $send('AUTH LOGIN'); $expect(334);
        $send(base64_encode($user)); $expect(334);
        $send(base64_encode($pass)); $expect(235);
        $send('MAIL FROM:<' . $from_addr . '>'); $expect(250);
        $send('RCPT TO:<' . $to . '>'); $expect(250);
        $send('DATA'); $expect(354);
        $payload = preg_replace('/^\./m', '..', $payload); // dot-stuff
        if (fwrite($sock, $payload) === false) throw new RuntimeException('smtp write payload failed');
        $send('.'); $expect(250);
        $send('QUIT');
        @fclose($sock);
    }

    /* ──────────── Markdown → HTML helper (optional) ──────────── */

    /** Tiny Markdown subset → HTML for email bodies: # / ## / ### headings,
     *  **bold**, *italic*, `code`, - lists, blank-line paragraphs. */
    public static function mdToHtmlEmail(string $md): string {
        $md = str_replace("\r\n", "\n", $md);
        $lines = explode("\n", $md);
        $out = [];
        $in_ul = false;
        $para = [];
        $flush_para = function () use (&$para, &$out) {
            if (!$para) return;
            $out[] = '<p style="margin:0 0 12px;">' . self::mdInlineEmail(implode(' ', $para)) . '</p>';
            $para = [];
        };
        foreach ($lines as $raw) {
            $l = rtrim($raw);
            if ($l === '') {
                if ($in_ul) { $out[] = '</ul>'; $in_ul = false; }
                $flush_para();
                continue;
            }
            if (preg_match('/^###\s+(.*)$/u', $l, $m)) {
                if ($in_ul) { $out[] = '</ul>'; $in_ul = false; }
                $flush_para();
                $out[] = '<h3 style="font-size:16px;margin:18px 0 8px;">' . self::mdInlineEmail($m[1]) . '</h3>';
                continue;
            }
            if (preg_match('/^##\s+(.*)$/u', $l, $m)) {
                if ($in_ul) { $out[] = '</ul>'; $in_ul = false; }
                $flush_para();
                $out[] = '<h2 style="font-size:18px;margin:22px 0 10px;">' . self::mdInlineEmail($m[1]) . '</h2>';
                continue;
            }
            if (preg_match('/^#\s+(.*)$/u', $l, $m)) {
                if ($in_ul) { $out[] = '</ul>'; $in_ul = false; }
                $flush_para();
                $out[] = '<h1 style="font-size:22px;margin:0 0 14px;">' . self::mdInlineEmail($m[1]) . '</h1>';
                continue;
            }
            if (preg_match('/^[-*]\s+(.*)$/u', $l, $m)) {
                $flush_para();
                if (!$in_ul) { $out[] = '<ul style="margin:0 0 12px;padding-left:22px;">'; $in_ul = true; }
                $out[] = '<li style="margin:0 0 6px;">' . self::mdInlineEmail($m[1]) . '</li>';
                continue;
            }
            if ($in_ul) { $out[] = '</ul>'; $in_ul = false; }
            $para[] = $l;
        }
        if ($in_ul) { $out[] = '</ul>'; }
        $flush_para();
        return implode("\n", $out);
    }

    private static function mdInlineEmail(string $s): string {
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/u', '<em>$1</em>', $s);
        $s = preg_replace('/`([^`]+)`/u', '<code style="background:#f4f6fb;padding:1px 5px;border:1px solid #e2e7f0;">$1</code>', $s);
        return $s;
    }
}
