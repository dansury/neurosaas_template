# config.php · settings_store.php · setup.php — configuration & operator settings

## Presentation

The settings page and diagnostic textareas use a readable white/light palette and explicit light color scheme even with a dark system preference. Settings and diagnostic-action rows wrap within a mobile viewport without horizontal page scrolling. Diagnostic contents, copying, privacy and authentication behavior are unchanged.

## 1. `config.php` — resolution

`require __DIR__ . '/config.php'` returns the config array. Per key, highest priority
first:

1. `settings` DB row (whitelisted keys only, written by `setup.php`)
2. process ENV (`cfg_env()` — empty string counts as absent)
3. hardcoded fallback in `config.php`

`cfg_env(string $key, ?string $default = null): ?string` and
`cfg_settings_whitelist(): array` are declared `function_exists`-guarded, so the file is
safe to `require` more than once per request (`setup.php` re-reads it after saving).

**Overlay** (closure at the end of the file): opens `DB_PATH` read-only-ish via PDO,
`SELECT key, value FROM settings`, and applies rows whose key is whitelisted and whose
value is non-empty. Type handling: `SMTP_PORT` → int; `LLM_OCR_MODELS` → comma-split
into a trimmed array; everything else → string. **Best-effort** — missing DB file,
missing table, locked DB, or any `Throwable` leaves the ENV/hardcoded values in place
and never breaks config loading.

### Whitelist (operator-editable)

`LLM_PROVIDER`, `LLM_DEFAULT_MODEL`, `LLM_PROVIDER_PRIORITY`, `OPENROUTER_API_KEY`,
`LLM_VISION_MODEL`, `LLM_FALLBACK_MODEL`, `LLM_FALLBACK_MODE`, `LLM_FALLBACK_MODELS`,
`MODEL_CATALOG_MODELS`, `MODEL_CATALOG_SYNCED_AT`, `MODEL_CATALOG_SUCCESS_AT`, `MODEL_CATALOG_ERROR`,
`MODEL_CATALOG_TTL_MIN`, `LLM_OCR_MODELS`, `YANDEX_FALLBACK_MODEL`,
`YANDEX_API_KEY`, `YANDEX_FOLDER_ID`, `YANDEX_LLM_URL`, `YANDEX_LLM_URL_FM`,
`YANDEX_MODELS_URL`, `LLM_MAX_TOKENS`, `YANDEX_OCR_URL`,
`YANDEX_OCR_MODEL`, `YANDEX_OCR_ENABLED`, `ADMIN_EMAIL`, `ERROR_EMAIL`, `SMTP_HOST`,
`SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_FROM_NAME`, `ADMIN_PASSWORD`.

Keys outside the whitelist are ENV/code-only (e.g. `OPENROUTER_URL`, `LLM_TIMEOUT_SEC`,
`LLM_MAX_RETRIES`, `DB_PATH`, `LOG_DIR`, `PROMPT_VERSION`, `AVAILABLE_MODELS`).

### Key groups & defaults

| Group | Keys (default) |
|---|---|
| Provider switch | `LLM_PROVIDER` (`genrouter`), `LLM_DEFAULT_MODEL` (first confirmed GenRouter chat model), `LLM_PROVIDER_PRIORITY` (`genrouter,openrouter,yandex`) |
| Fallback | `LLM_FALLBACK_MODE` (`auto` — a newer version of the same model first; `manual` — list only), `LLM_FALLBACK_MODELS` (`` — comma-separated short ids, written by three dropdowns in `setup.php`) |
| Model catalogue | `MODEL_CATALOG_MODELS` (``), `MODEL_CATALOG_SYNCED_AT` (``), `MODEL_CATALOG_SUCCESS_AT` (``), `MODEL_CATALOG_ERROR` (``), `MODEL_CATALOG_TTL_MIN` (15) — see `/specs/llm_connector/model_catalog.md` |
| OpenRouter | `OPENROUTER_API_KEY` (``), `OPENROUTER_URL` (chat/completions), `LLM_FALLBACK_MODEL` (`openrouter/auto`), `LLM_OCR_MODELS` (array, comma-split from ENV) |
| Vision | `LLM_VISION_MODEL` (first confirmed GenRouter vision model when unset) — the model for photos, labels and PDF pages. Accepts `"<provider>:<slug>"`, a short id, or a bare slug (bare = OpenRouter), so a Yandex multimodal model can be chosen here too (`/specs/llm_connector/llm.md` §2) |
| Yandex Cloud | `YANDEX_API_KEY`, `YANDEX_FOLDER_ID`, `YANDEX_LLM_URL` (OpenAI-compatible), `YANDEX_LLM_URL_FM` (`…/foundationModels/v1/completion` — the second address, used for a model the first one does not serve, `/specs/llm_connector/llm.md` §5.1), `YANDEX_MODELS_URL` (`…/foundationModels/v1/models` — the Models API the catalogue refresh asks first), `YANDEX_OCR_URL` (`…/ocr/v1/recognizeText`), `YANDEX_OCR_MODEL` (`page`), `YANDEX_OCR_ENABLED` (`1`), `YANDEX_FALLBACK_MODEL` (`yandexgpt-5-lite`) |
| Answer length | `LLM_MAX_TOKENS` (`0` — the provider's own limit; above zero it is sent as `max_tokens` / `completionOptions.maxTokens`, raised when the journal reports an answer cut off mid-value) |
| Timeouts | `LLM_TIMEOUT_SEC` (120), `LLM_MAX_RETRIES` (2) |
| Admin gate | `ADMIN_PASSWORD` (``) |
| Mail | `ADMIN_EMAIL`, `ERROR_EMAIL`, `SMTP_HOST` (empty), `SMTP_PORT` (465), `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM` (← `SMTP_USER`), `SMTP_FROM_NAME` |
| Storage | `DB_PATH` (`data/app.db`), `LOG_DIR` (`data/logs`), `PROMPT_VERSION` |

No secret ever has a non-empty default in code.

### `AVAILABLE_MODELS`

Row: `['id','label','provider','full_id','group','price_in','price_out','vision']`
(+ `'ocr_only' => true` for OCR-only entries). `vision` — `true` the model accepts images,
`false` it does not; on a live row it is absent when the provider reported no modality,
and `LLM` treats the absence as «maybe» (`/specs/llm_connector/llm.md` §2). `price_in`/`price_out` are approximate RUB per 1k tokens, shown
for operator orientation only — nothing in the code charges by them. `provider` is
`yandex` or `openrouter`; `full_id` is the provider-native id (Yandex: the slug used in
`gpt://<folder>/<full_id>/latest`); `group` names the `<optgroup>` the row lands in. The
model dropdowns in `setup.php` are built from this list with `ocr_only` rows filtered out.
Adding a model = adding a row.

The hardcoded Yandex rows are the slugs the Yandex AI Studio model catalogue documents:
first-party `aliceai-llm{,-flash}`, `yandexgpt-5.1`, `yandexgpt-5-{pro,lite}`,
`deepseek-v4-flash`, `gpt-oss-{120b,20b}`, `qwen3-235b-a22b-fp8`; the open models
`llama-3.{3,1}-70b-instruct`, `qwen2.5-{7b,32b,72b}-instruct`, `gemma-3-1b-it`; and the
multimodal `gemma-3-{4b,12b,27b}-it`, `qwen3.6-35b-a3b`. An invented slug answers
`Failed to get model` at call time, so rows are added only for models the catalogue
lists — and even a documented one is a CANDIDATE, not a fact: the open models are enabled
per folder and region, and what this folder really serves is what the Models API reports
(`/specs/llm_connector/model_catalog.md` §4). Rows withdrawn from the catalogue (`deepseek-r1`,
`deepseek-v3`, `phi-4`, `deepseek-vl2{,-tiny}`, `qwen2.5-vl-72b-instruct`) are gone from
the list; a `settings` value still naming one keeps its own option in the dropdown, so a
saved choice is never silently swapped.

Rows also carry `context` — the model's context window in tokens — where the catalogue
documents one; `price_in`/`price_out` stay `0.0` where no RUB estimate is worth stating,
and the dropdown then shows the model without a price rather than an invented one.

`qwen3.6-35b-a3b` — an optional Yandex vision model — is hardcoded with `vision => true`
for a second reason: its slug carries no `-vl-` segment, so the name heuristic that flags
Yandex live rows as multimodal (`/specs/llm_connector/model_catalog.md` §4) cannot name it, and without
the hardcoded row a photo step would drop it as image-blind.

**Live rows.** At the end of `config.php` the cached provider catalogue is merged in
(`ModelCatalog::decode()` + `merge()`, no network — see `/specs/llm_connector/model_catalog.md`). A live
row for a known slug only flags the hardcoded row `live => true`; an unknown model is
appended with an `or-` / `ya-` short id, a `… (каталог)` group and USD prices in
`price_usd_in` / `price_usd_out` (`price_in`/`price_out` stay 0 — no exchange rate is
invented). Nothing about the hardcoded rows is rewritten, so saved settings keep working.

## 2. `settings_store.php` — `SettingsStore`

```php
new SettingsStore(string $dbPath)          // mkdir -p dirname, PDO sqlite, ERRMODE_EXCEPTION
$store->pdo(): PDO
$store->getSetting(string $key, ?string $default = null): ?string
$store->setSetting(string $key, string $value): void   // UPSERT, updated_at = UTC ISO-8601
$store->allSettings(): array                            // [key => value]
```

Schema (created on construction):

```
TABLE settings
  key         TEXT PRIMARY KEY
  value       TEXT NOT NULL
  updated_at  TEXT NOT NULL      -- gmdate('Y-m-d\TH:i:s\Z')
```

No other table is owned by this repo.

## 3. `setup.php` — operator page

Standalone PHP page (`declare(strict_types=1)`), Russian UI, `display_errors` off +
`log_errors` on, `X-Robots-Tag: noindex, nofollow` + a `robots` meta, `Cache-Control: no-store`.

**Auth.** PHP session; `POST action=login` compares the password with `ADMIN_PASSWORD`
via `hash_equals` and only when that value is non-empty (empty admin password → login
impossible, never open). `?logout=1` clears the session. Unauthenticated requests get
the login form and `exit`.

**Authenticated embedding.** A host may define `SITE_TOOLKIT_HOST_AUTHENTICATED` as the boolean `true` after performing its own authentication. This request-local constant bypasses only the standalone login gate, never writes `admin_authed` into the shared session, and cannot be set by a query or form parameter. The host must enforce CSRF on embedded POST requests and preserve the module's own capability nonce for self-maintenance actions.

**Save** (`POST action=save`, default action): each whitelisted string field is trimmed
and written through `setSetting` **only when non-empty** — blanks never overwrite an
existing value (that is what lets the masked key/password placeholders work).
`YANDEX_OCR_ENABLED` is a checkbox and is therefore always written (`'1'` / `'0'`).
The model dropdowns are written whenever submitted, empty pick included, since
«— не задана —» has to be able to clear a value: `LLM_VISION_MODEL`, `LLM_FALLBACK_MODEL`,
`YANDEX_FALLBACK_MODEL`, and `LLM_FALLBACK_MODELS` joined from the three ordered
`LLM_FALLBACK_MODEL_{1,2,3}` selects (deduplicated, blanks dropped).
The confirmation message lists the keys that were edited.

**Model catalogue** (`POST model_catalog=refresh|forget`): API-key fields typed into the
form are saved first (so the fetch uses the new credentials), then `config.php` is
re-read and `ModelCatalog::refresh()` / `forget()` runs, reporting per-provider counts or
failure reasons. Independently of the buttons, **every page load** calls
`ModelCatalog::maybeRefresh()` before rendering: a cache older than `MODEL_CATALOG_TTL_MIN`
minutes is refetched, a fresh one is left alone, and a network failure only shows up as a
status line — the page and the previous list are unaffected (`/specs/llm_connector/model_catalog.md` §3).

**Provider self-test** (`POST llm_probe`): `LLM::probe()` (`/specs/llm_connector/llm.md` §3) — one ✅/⛔
line per leg (default model, vision model, each per-provider fallback) with the provider's
own answer, so a wrong key, a foreign folder or a model missing from the cloud catalogue
is named on the spot. Every leg also lands in the log.

**Log** (`/specs/llm_connector/diag_log.md`): `DiagLog::init()` runs next to the `SettingsStore`, with a
code stamp over the module files — a redeploy therefore starts the log empty while the
`settings` rows survive. `LLM::init($cfg, DiagLog::store())` routes every model call into
it. The page renders two read-only textareas built by `DiagLog::asText()` — «только
ошибки» and «полный лог», both prefixed with the environment header (PHP, DB path, code
stamp, masked keys, `LLM::configSummary()`) — with a copy-to-clipboard button each and
`POST diag_clear` to empty it. API keys and passwords are registered via
`DiagLog::addSecret()` right after `init()`, so the copied text carries none.

**SMTP test** (`POST smtp_test`): re-reads `config.php` (so just-saved values apply),
picks `smtp_test_to` or `ADMIN_EMAIL`, calls `Mailer::sendTest` and renders ✅ with
host:port or ⚠️ with the exception message.

**Where a result shows.** Every message carries a spot: `top` (save, module buttons,
log clear) or the id of the section whose button produced it — `catalog`,
`autopull`, `probe`, `smtp`. `$render_msgs($at)` prints a spot's messages; the
section ones render right under their button, and those buttons post to
`setup.php#<spot>`, so the browser lands on the answer instead of the page top
(on a phone the top is several screens away and the click looks like nothing
happened).

**Rendering.** After handling POST the page re-loads `config.php` and `allSettings()`;
`$eff($key)` shows the effective value (saved overlay → config, arrays joined by `,`).
Secrets are never echoed back: `$mask()` renders bullets for passwords, `$mask_key()`
renders `abcd…wxyz` for API keys. All output goes through `htmlspecialchars`.

**Form sections:** Провайдер и модели (provider, priority, default model — grouped by
`group` with a price hint, live-catalogue status + refresh/forget buttons and its TTL,
fallback mode with the newer versions it currently resolves to, three backup-model
dropdowns, vision model (multimodal rows of both providers), per-provider fallback model
dropdowns, OpenRouter OCR chain, Yandex OCR model + enable checkbox) ·
API-ключи (OpenRouter key, Yandex key + folder) · Почта (host, port, user, password,
from, from name, `ADMIN_EMAIL`, `ERROR_EMAIL`) · Доступ (`ADMIN_PASSWORD`) ·
Проверка провайдеров · Лог · Тест SMTP.

No model field is free text: every one of them is a `<select>` over the catalogue
(`$model_select()`), and a saved value the catalogue no longer lists stays as its own
`… — нет в каталоге` option so the browser cannot silently swap the model on save.

Deployment note: the page must not be reachable without TLS, and `ADMIN_PASSWORD` must
be set before the file is exposed to the web.


## GenRouter and operator controls

GENROUTER_API_KEY, GENROUTER_BASE_URL, GENROUTER_MODEL and LLM_AUDIO_MODEL are
whitelisted settings. GenRouter is first in fresh provider choices, API-key fields
and model groups; existing saved values win. Empty default text/vision/audio
choices resolve only to capabilities reported by GenRouter. Secondary providers
are explicit disclosures; only OpenRouter has a proxy. The catalog reset is a
small cross beside refresh. SMTP has no provider placeholder and explains its
optional purpose and application-password links for Yandex, Mail.ru and Gmail.
The shared top-right feedback widget is available on the starter and toolkit.
