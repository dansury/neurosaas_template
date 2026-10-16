# diag_log.php — operator-facing diagnostic log

> Infrastructural module — no customer TZ behind it; no `[CODE §…]` citations (see `spec.md` §Provenance).
> Canonical source of this code: repository `site_yacloud_openrouter`.

`final class DiagLog` (+ `final class DiagLogLLMStore`) — the last N events of the
running deployment in the app's own SQLite file, rendered as copyable plain text by the
admin page. Replaces `error_log()`, which an operator on shared hosting cannot read.

## 1. Storage

```
TABLE diag_log    id INTEGER PK AUTOINCREMENT, ts TEXT, level TEXT, channel TEXT,
                  message TEXT, context TEXT NULL          -- context = JSON object
INDEX diag_log_level (level, id)
TABLE diag_state  key TEXT PK, value TEXT                  -- code_stamp, deployed_at
```

Same database file as `settings` (`DB_PATH`). Rows above `MAX_ROWS` (default 2000) are
deleted on ~1 in 20 writes. `level ∈ {error, warn, info}`; `channel` is free-form
(`llm`, `deploy`, `admin`, `recognize`, `php`), ≤ 40 chars.

## 2. Lifecycle

```php
DiagLog::init(string $dbPath, string $codeStamp = '', int $maxRows = 2000): void
DiagLog::isReady(): bool
DiagLog::codeStamp(array $files): string        // md5-12 of size+mtime per file
DiagLog::state(string $k): ?string / setState(string $k, string $v): void
DiagLog::deployNote(): ?string
```

**Wiped on redeploy.** `init()` compares `$codeStamp` with `diag_state.code_stamp`: a
different value empties `diag_log`, stores the new stamp and `deployed_at`, and writes one
`deploy` entry saying so. What the page shows therefore always describes the code that is
running now. Settings are *not* touched — they live in `settings` and must survive.

Nothing here throws: a failing open leaves `isReady() === false` and every call is a no-op.

## 3. Redaction

```php
DiagLog::addSecret(?string $value): void        // ignored below 8 chars
DiagLog::maskKey(?string $value): string        // "AQVN…ab12", ≤10 chars → all bullets
DiagLog::redact(string $text): string
```

Registered secrets are replaced by their masked form in every message, in every context
value (recursively) and in the header of `asText()`. The host app registers its API keys
and passwords right after `init()`, so a copied log can be pasted into a chat as is.

## 4. Writing

```php
DiagLog::write(string $level, string $channel, string $message, array $context = []): void
DiagLog::error|warn|info(string $channel, string $message, array $context = []): void
```

Message capped at 4000 chars, context JSON at 8000, each context string at 2000.

## 5. Reading

```php
DiagLog::tail(int $limit = 300, bool $errorsOnly = false): array   // oldest-first
DiagLog::counts(): array                                          // [total, errors]
DiagLog::clear(): void
DiagLog::asText(array $rows, array $header = [], string $title = 'diagnostic log'): string
```

`errorsOnly` keeps `error` + `warn`. `asText()` renders

```
=== <title> ===
сформирован: <UTC>
<header label>: <value>          -- app, php, db path, code stamp, keys (masked), llm config
------------------------------------------------------------
<ts> <LEVEL> <channel> | <message>
    <context key path> = <value>
```

The header is what makes a pasted log self-contained: whoever reads it needs no access to
the admin page to know which code, which provider, which models and which keys were in
play.

## 6. LLM adapter

`DiagLog::store(): object` returns a `DiagLogLLMStore` singleton — the duck-typed
`logLLMCall(...)` that `LLM::init($cfg, $store)` expects (`/spec/llm.md` §1, §7). Every
call becomes one row: `level = info` on `ok`, `error` otherwise; context carries
`step, model, status, latency_ms, prompt, session?, error?`. A failing call also records
the first 1000 chars of the raw answer; a successful one records only its length — user
content stays out of the log.
