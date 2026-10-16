# mailer.php — SMTP mail

> Infrastructural module — no customer TZ behind it; no `[CODE §…]` citations (see `spec.md` §Provenance).
> Canonical source of this code: repository `site_yacloud_openrouter`.

`final class Mailer` — static. Pure-PHP SMTP client (no PHPMailer, no `mail()`),
MIME assembled by hand, UTF-8 throughout.

## 1. API

```php
Mailer::sendCustom(array $cfg, string $to, string $subject, string $html,
                   string $text, array $extraHeaders = []): void        // throws
Mailer::sendWithAttachment(array $cfg, string $to, string $subject, string $body_text,
                           string $original_name, string $stored_path): bool
Mailer::sendTest(array $cfg, string $to): void                          // throws
Mailer::sendErrorNotification(array $cfg, string $context, string $message,
                              array $extra = []): bool                  // never throws
Mailer::buildMimeMessage(string $from_addr, string $from_name, string $to,
                         string $subject, string $id_prefix, string $plain,
                         string $html_body, ?array $attachment = null): string
Mailer::mdToHtmlEmail(string $md): string
```

Error posture per entry point: `sendCustom` / `sendTest` **throw** (operator-visible);
`sendWithAttachment` returns `false` and logs; `sendErrorNotification` returns `false`
and logs — it must never break the caller's pipeline.

Config keys used: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`
(falls back to `SMTP_USER`), `SMTP_FROM_NAME`, `ADMIN_EMAIL` (default recipient for
attachments), `ERROR_EMAIL`, `LOG_DIR` (throttle state). Every sender first checks
host + user + password and refuses otherwise (`smtp_not_configured` / logged skip).

## 2. Message shapes

| Sender | Structure |
|---|---|
| `sendCustom` | `multipart/alternative` (plain, then HTML), boundary `fa_<hex>` |
| `buildMimeMessage` | same alternative part; wrapped in `multipart/mixed` (`rm_<hex>`) when a readable `['path','filename','mime']` attachment is given — returns the payload, does not send |
| `sendWithAttachment` | `multipart/mixed` (`rb_<hex>`): plain text + one base64 attachment |
| `sendTest`, `sendErrorNotification` | single `text/plain` part |

Common headers: `From: <encoded name> <from_addr>`, `To`, encoded `Subject`,
`MIME-Version: 1.0`, `Date: r`, `Message-ID: <prefix_<hex>@<from-domain>>`.
`sendCustom` adds `Reply-To: <from_addr>` unless the caller already supplied one in
`$extraHeaders` (case-insensitive check) — extra headers are for `List-Unsubscribe` /
`List-Unsubscribe-Post` one-click opt-out. `buildMimeMessage` always adds `Reply-To`
plus `List-Unsubscribe: <mailto:from?subject=unsubscribe>`.

Attachment MIME in `sendWithAttachment` is derived from the extension: `pdf` →
`application/pdf`, `docx` → the OOXML wordprocessing type, otherwise
`application/octet-stream`; body base64 via `chunk_split`.

Helpers: `encodeHeader()` — RFC 2047 `=?UTF-8?B?…?=` only when non-ASCII is present;
`sanitizeFilename()` — strips `CR`, `LF`, `"`; `messageIdHost()` — the From address's own
domain (validated `[A-Za-z0-9.-]+`, else `localhost`) so the Message-ID aligns with the
sending identity.

## 3. SMTP transport — `smtpSend()` *(private)*

Port 465 → implicit TLS (`ssl://`); any other port → `STARTTLS` when advertised in the
EHLO reply, mandatory on 587. Connect timeout 15 s, stream timeout 30 s.

Sequence: expect `220` → `EHLO <from-domain>` `250` → [`STARTTLS` `220`, enable crypto,
`EHLO` `250`] → `AUTH LOGIN` `334` → base64 user `334` → base64 pass `235` →
`MAIL FROM` `250` → `RCPT TO` `250` → `DATA` `354` → payload (leading dots doubled) →
`.` `250` → `QUIT`. Multi-line replies are consumed until the non-`-` line; any
unexpected code, read, write, or crypto failure → `RuntimeException`.

## 4. `sendErrorNotification` — recipient & body

Recipient `ERROR_EMAIL` (empty → `false`). Subject
`[site_yacloud_openrouter · ОШИБКА] <context>`. Body: `message`, UTC `time`, then each
scalar `$extra` entry as a padded `key : value` line (empty and `—` values dropped).

## 5. Throttling

Two layers:

- **Per-request memo** — static `$sent[md5(context|message|scope)]`, where `scope` is
  built from `extra['session_id']` and `extra['email']`; a repeat inside the same request
  returns `true` without sending.
- **Persistent gate** — `errorEmailAllowed()` over `LOG_DIR/error_email_throttle.json`
  under `flock(LOCK_EX)`: state `{last_sent_at, keys:{dedupe: ts}}`; a `dedupe` key
  suppresses duplicates for `24 h` (`key_ttl`), and a global `60 s` minimum interval
  (`min_interval`) rate-limits everything. Expired keys are pruned on each pass.
  **Fail-open** — missing `LOG_DIR`, unopenable file, unobtainable lock or any throw →
  the email is allowed. Suppressions are logged (`suppressed duplicate` /
  `suppressed by 1/min rate limit`).

## 6. `mdToHtmlEmail()`

Markdown subset → inline-styled HTML for email bodies: `#`/`##`/`###` headings,
`- `/`* ` unordered lists, blank-line-separated paragraphs; inline `**bold**`,
`*italic*`, `` `code` ``. Text is `htmlspecialchars`-escaped **before** inline markup is
applied, so user content cannot inject tags. All styling is inline (no `<style>` block),
since mail clients strip head styles.
