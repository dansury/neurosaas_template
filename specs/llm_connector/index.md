# LLM connector — specification index

> **Usage:** file -> spec mapping only. For ANY detail (signatures, tables, algorithms,
> flows) open the relevant `/specs/llm_connector/<module>.md` — never all of them at once.

---

## Rules

- **No secrets in code.** Every key/credential arrives from ENV or the `settings`
  table (`setup.php`). Defaults in `config.php` are non-secret placeholders. The
  single, deliberate exception is the sealed reporting credential in
  `selfheal/token.php` — see `/specs/llm_connector/selfheal.md` §4, including the scope it is
  restricted to and what sealing does and does not protect against.
- **The public API is frozen.** `Selfheal\Contract::PUBLIC_API` is what consuming
  projects may call; `API_VERSION` is semver over that surface only, and a major
  bump never reaches users through the auto-updater. Everything else in
  `selfheal/` is internal. See `/specs/llm_connector/selfheal.md` §2.
- **Reporting is opt-in, always.** Nothing leaves an install until the operator
  says yes; the assistant installing the module must ask (`AGENTS.md` §1), and
  every user-visible error says what happened to it. See `/specs/llm_connector/selfheal.md` §7.
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
  relevant `/specs/llm_connector/<module>.md` first, then implement to match it. Specs describe
  actual functionality only — never changelogs or version history.
- **Language.** All specs in English.

---

## Spec index (open one file at a time)

| File | Modules covered |
|---|---|
| `/specs/llm_connector/llm.md`      | `llm.php` — OpenRouter + Yandex providers, overrides, fallback chain, PDF OCR |
| `/specs/llm_connector/model_catalog.md` | `model_catalog.php` — live provider catalogue, cache in `settings`, model versions |
| `/specs/llm_connector/parser.md`   | `parser.php` — DOCX/PDF extraction, normalization, quality heuristic |
| `/specs/llm_connector/mailer.md`   | `mailer.php` — SMTP transport, MIME building, attachments, error notifications |
| `/specs/llm_connector/settings.md` | `config.php`, `settings_store.php`, `setup.php` — config resolution, `settings` table, admin page |
| `/specs/llm_connector/diag_log.md` | `diag_log.php` — diagnostic log, redaction, reset on redeploy |
| `/specs/llm_connector/auto_pull.md` | `auto_pull.php` — silent GitHub head check on every page, deploy through `pull.php`, redirect back |
| `/specs/llm_connector/selfheal.md` | `selfheal/*`, `selfheal_install.php`, `selfheal_seal.php`, `selfheal_admin.php`, `module.json` — self-maintaining layer: isolation, error reports to issues, consent, sealed credential, capability keys, verified update + rollback |

---

## File -> spec map

| File | Spec file |
|---|---|
| `llm.php`            | `/specs/llm_connector/llm.md` |
| `model_catalog.php`  | `/specs/llm_connector/model_catalog.md` |
| `parser.php`         | `/specs/llm_connector/parser.md` |
| `mailer.php`         | `/specs/llm_connector/mailer.md` |
| `config.php`         | `/specs/llm_connector/settings.md` |
| `settings_store.php` | `/specs/llm_connector/settings.md` |
| `diag_log.php`       | `/specs/llm_connector/diag_log.md` |
| `setup.php`          | `/specs/llm_connector/settings.md` |
| `auto_pull.php`      | `/specs/llm_connector/auto_pull.md` |
| `selfheal/*.php`     | `/specs/llm_connector/selfheal.md` |
| `module.json`        | `/specs/llm_connector/selfheal.md` §2 |
| `selfheal_install.php` | `/specs/llm_connector/selfheal.md` §12 |
| `selfheal_seal.php`  | `/specs/llm_connector/selfheal.md` §4.2 |
| `selfheal_admin.php` | `/specs/llm_connector/selfheal.md` §11.2 |
| `AGENTS.md`          | operator/assistant instructions (not a spec) |
| `tests/llm_chain.php` | `/specs/llm_connector/llm.md` §4 (candidate chain, run: `php tests/llm_chain.php`) |
| `tests/llm_json.php` | `/specs/llm_connector/llm.md` §5.1–5.2 (Yandex address, answer parsing, run: `php tests/llm_json.php`) |
| `tests/model_catalog_yandex.php` | `/specs/llm_connector/model_catalog.md` §4 (Yandex Models API, run: `php tests/model_catalog_yandex.php`) |
| `tests/selfheal_smoke.php` | `/specs/llm_connector/selfheal.md` §13.1 (run: `php tests/selfheal_smoke.php`) |
| `tests/selfheal_update.php` | `/specs/llm_connector/selfheal.md` §13.2 (run: `php tests/selfheal_update.php`) |

---

## Runtime requirements

PHP 7.4+ (typed properties, arrow functions), no Composer. Extensions: `curl`,
`pdo_sqlite`, `zip` (DOCX), `dom` + `libxml` (DOCX XPath), `mbstring`,
`openssl` (SMTP TLS). Optional external binary: `pdftotext` (poppler-utils) —
absent → PDF goes straight to OCR.

## Storage

| Path | Content |
|---|---|
| `data/selfheal.db` | SQLite; the self-maintaining layer's OWN state — settings, report queue, fingerprint→issue map (`/specs/llm_connector/selfheal.md` §3). Deliberately not `DB_PATH` |
| `data/selfheal/` | update staging, downloads and backups (`/specs/llm_connector/selfheal.md` §10) |
| `DB_PATH` (default `data/app.db`) | SQLite; table `settings` (see `/specs/llm_connector/settings.md` §2) — also holds the cached model catalogue (`/specs/llm_connector/model_catalog.md` §2) and the diagnostic log (`/specs/llm_connector/diag_log.md` §1) |
| `LOG_DIR` (default `data/logs`)   | `error_email_throttle.json` (see `/specs/llm_connector/mailer.md` §5) |
| `dirname(DB_PATH)` | `auto-pull.json` — last deploy check (see `/specs/llm_connector/auto_pull.md` §4) |

Both are gitignored (`data/*.db`, `data/logs/`).
