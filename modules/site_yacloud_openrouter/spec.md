# site_yacloud_openrouter — spec.md (navigation index)

> **Usage:** file -> spec mapping only. For ANY detail (signatures, tables, algorithms,
> flows) open the relevant `/spec/<module>.md` — never all of them at once.

---

## Provenance

- **This repository is the canonical source of this code.** Consuming projects
  (bot, site, admin panels) take the LLM / parser / mailer / settings layer **from the
  `site_yacloud_openrouter` repository** — they do not fork or vendor a private copy.
  Fixes land here first, then propagate to the consumers.
- **Purely infrastructural code, written outside any customer TZ.** It realizes no
  customer requirement: there is no source technical assignment behind it, therefore
  spec sections here carry **no `[CODE §…]` TZ citations** (unlike the product repos,
  where every spec section cites its TZ item). Requirements for this layer come from
  the consuming projects' integration needs, not from a customer document.
- Historical origin: extracted from the `resume-index` PHP service
  (`careerhack.ru/resume-index`), with all report/product-specific logic removed.

## Rules

- **No secrets in code.** Every key/credential arrives from ENV or the `settings`
  table (`setup.php`). Defaults in `config.php` are non-secret placeholders. The
  single, deliberate exception is the sealed reporting credential in
  `selfheal/token.php` — see `/spec/selfheal.md` §4, including the scope it is
  restricted to and what sealing does and does not protect against.
- **The public API is frozen.** `Selfheal\Contract::PUBLIC_API` is what consuming
  projects may call; `API_VERSION` is semver over that surface only, and a major
  bump never reaches users through the auto-updater. Everything else in
  `selfheal/` is internal. See `/spec/selfheal.md` §2.
- **Reporting is opt-in, always.** Nothing leaves an install until the operator
  says yes; the assistant installing the module must ask (`AGENTS.md` §1), and
  every user-visible error says what happened to it. See `/spec/selfheal.md` §7.
- **Config resolution order (per key):** `settings` DB row > process ENV >
  `config.php` fallback. Only whitelisted keys (`cfg_settings_whitelist()`) may be
  overlaid from the DB.
- **No app coupling.** Nothing here may import or assume a consuming project's tables,
  routes, or domain model. The only optional hook is `LLM::init($cfg, $store)` with a
  duck-typed `logLLMCall(...)`.
- **Model catalogue is data, not code.** Chat/OCR models live in
  `config.php → AVAILABLE_MODELS`; `setup.php` builds its dropdown from that list.
  The list is also **pulled live from the providers** and cached in `settings`
  (`model_catalog.php`); the hardcoded rows are what the service runs on until the
  first refresh, and are never replaced by it.
- **Spec-driven changes.** For any new feature or non-trivial change: update the
  relevant `/spec/<module>.md` first, then implement to match it. Specs describe
  actual functionality only — never changelogs or version history.
- **Language.** All specs in English.

---

## Spec index (open one file at a time)

| File | Modules covered |
|---|---|
| `/spec/llm.md`      | `llm.php` — OpenRouter + Yandex providers, overrides, fallback chain, PDF OCR |
| `/spec/model_catalog.md` | `model_catalog.php` — live provider catalogue, cache in `settings`, model versions |
| `/spec/parser.md`   | `parser.php` — DOCX/PDF extraction, normalization, quality heuristic |
| `/spec/mailer.md`   | `mailer.php` — SMTP transport, MIME building, attachments, error notifications |
| `/spec/settings.md` | `config.php`, `settings_store.php`, `setup.php` — config resolution, `settings` table, admin page |
| `/spec/diag_log.md` | `diag_log.php` — diagnostic log, redaction, reset on redeploy |
| `/spec/auto_pull.md` | `auto_pull.php` — silent GitHub head check on every page, deploy through `pull.php`, redirect back |
| `/spec/selfheal.md` | `selfheal/*`, `selfheal_install.php`, `selfheal_seal.php`, `selfheal_admin.php`, `module.json` — self-maintaining layer: isolation, error reports to issues, consent, sealed credential, capability keys, verified update + rollback |

---

## File -> spec map

| File | Spec file |
|---|---|
| `llm.php`            | `/spec/llm.md` |
| `model_catalog.php`  | `/spec/model_catalog.md` |
| `parser.php`         | `/spec/parser.md` |
| `mailer.php`         | `/spec/mailer.md` |
| `config.php`         | `/spec/settings.md` |
| `settings_store.php` | `/spec/settings.md` |
| `diag_log.php`       | `/spec/diag_log.md` |
| `setup.php`          | `/spec/settings.md` |
| `auto_pull.php`      | `/spec/auto_pull.md` |
| `selfheal/*.php`     | `/spec/selfheal.md` |
| `module.json`        | `/spec/selfheal.md` §2 |
| `selfheal_install.php` | `/spec/selfheal.md` §12 |
| `selfheal_seal.php`  | `/spec/selfheal.md` §4.2 |
| `selfheal_admin.php` | `/spec/selfheal.md` §11.2 |
| `AGENTS.md`          | operator/assistant instructions (not a spec) |
| `example.php`        | `/spec/llm.md` §6 (CLI smoke modes) |
| `tests/llm_chain.php` | `/spec/llm.md` §4 (candidate chain, run: `php tests/llm_chain.php`) |
| `tests/llm_json.php` | `/spec/llm.md` §5.1–5.2 (Yandex address, answer parsing, run: `php tests/llm_json.php`) |
| `tests/model_catalog_yandex.php` | `/spec/model_catalog.md` §4 (Yandex Models API, run: `php tests/model_catalog_yandex.php`) |
| `tests/selfheal_smoke.php` | `/spec/selfheal.md` §13.1 (run: `php tests/selfheal_smoke.php`) |
| `tests/selfheal_update.php` | `/spec/selfheal.md` §13.2 (run: `php tests/selfheal_update.php`) |

---

## Runtime requirements

PHP 7.4+ (typed properties, arrow functions), no Composer. Extensions: `curl`,
`pdo_sqlite`, `zip` (DOCX), `dom` + `libxml` (DOCX XPath), `mbstring`,
`openssl` (SMTP TLS). Optional external binary: `pdftotext` (poppler-utils) —
absent → PDF goes straight to OCR.

## Storage

| Path | Content |
|---|---|
| `data/selfheal.db` | SQLite; the self-maintaining layer's OWN state — settings, report queue, fingerprint→issue map (`/spec/selfheal.md` §3). Deliberately not `DB_PATH` |
| `data/selfheal/` | update staging, downloads and backups (`/spec/selfheal.md` §10) |
| `DB_PATH` (default `data/app.db`) | SQLite; table `settings` (see `/spec/settings.md` §2) — also holds the cached model catalogue (`/spec/model_catalog.md` §2) and the diagnostic log (`/spec/diag_log.md` §1) |
| `LOG_DIR` (default `data/logs`)   | `error_email_throttle.json` (see `/spec/mailer.md` §5) |
| `dirname(DB_PATH)` | `auto-pull.json` — last deploy check (see `/spec/auto_pull.md` §4) |

Both are gitignored (`data/*.db`, `data/logs/`).
