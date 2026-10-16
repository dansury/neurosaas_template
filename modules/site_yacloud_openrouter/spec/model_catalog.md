# model_catalog.php — live provider model catalogue

> Infrastructural module — no customer TZ behind it; no `[CODE §…]` citations (see `spec.md` §Provenance).
> Canonical source of this code: repository `site_yacloud_openrouter`.

## 1. Purpose

`config.php → AVAILABLE_MODELS` is a hardcoded list that ages with the release.
`ModelCatalog` pulls the model list from the providers themselves, normalizes it into
catalogue rows, caches the JSON in the `settings` table, and merges the cache back into
`AVAILABLE_MODELS` on every request without touching the network. It also parses model
versions out of slugs, which backs the default fallback "a newer version of the same
model" (`spec/llm.md` §4).

The hardcoded list is never replaced: it is what the service runs on before any refresh
happens, and it stays the source of each known model's `id`, `group` and RUB price.

PHP 7.4-compatible (no `str_contains` / `str_starts_with`).

## 2. Storage keys (`settings`, whitelisted in `config.php`)

| Key | Content |
|---|---|
| `MODEL_CATALOG_MODELS` | JSON array of normalized catalogue rows (the cache) |
| `MODEL_CATALOG_SYNCED_AT` | UTC `Y-m-d\TH:i:s\Z` of the last refresh attempt that finished |
| `MODEL_CATALOG_ERROR` | reason of the last failure (or of a partial one), rendered in `setup.php`; empty on full success |
| `MODEL_CATALOG_TTL_MIN` | minutes a cache counts as fresh (default `ModelCatalog::TTL_MIN` = 15) |

## 3. API

```php
ModelCatalog::maybeRefresh(array $cfg, SettingsStore $store, bool $force = false): ?array
ModelCatalog::refresh(array $cfg, SettingsStore $store): array   // throws
ModelCatalog::isStale(array $cfg): bool
ModelCatalog::ttlSec(array $cfg): int
ModelCatalog::decode(string $json): array
ModelCatalog::merge(array $builtin, array $live): array
ModelCatalog::forget(SettingsStore $store): void
ModelCatalog::lineage(string $slug): ?array          // ['key' => …, 'version' => int[]]
ModelCatalog::versionCmp(array $a, array $b): int
ModelCatalog::newerSiblings(array $row, array $models): array
```

- **`maybeRefresh`** — the entry point used by `setup.php` on page load. Returns `null`
  when the cache is fresh (`isStale() === false`) and `$force` is not set. Network
  errors are **never thrown**: the reason goes to `MODEL_CATALOG_ERROR`, the sync stamp
  is moved anyway (so a failing provider is not re-tried on every reload, only after the
  TTL), and the previous cache stays in place.
- **`refresh`** — fetches both providers, stores the rows, the stamp and the (possibly
  empty) error note. Returns `['rows' => int, 'openrouter' => int|string, 'yandex' => int|string]`
  where a string is that provider's failure reason. Throws `RuntimeException` only when
  **no** provider produced rows — then the previous cache is untouched.
- **`isStale`** — true when the cache is empty or `now - MODEL_CATALOG_SYNCED_AT >= ttlSec()`.
  `ttlSec()` = `MODEL_CATALOG_TTL_MIN` × 60, floored at 60 s.
- **`decode`** — parses the stored JSON; rows without `id`, `full_id` or `provider` are
  dropped. Used by `config.php` (no network there).
- **`merge`** — hardcoded rows first. A live row whose `provider|full_id` matches a
  hardcoded row only sets `live => true` on it (and fills in `vision` when the hardcoded row
  carries no such flag — a curated flag always wins); an unknown model is appended. A live row
  whose short `id` collides with a hardcoded one is skipped. Hardcoded `id` / `group` /
  price are never rewritten, so a saved `LLM_DEFAULT_MODEL` cannot break.
- **`forget`** — clears all three cache keys; the lists fall back to the hardcoded catalogue.

## 4. Fetching

| Provider | Request | Notes |
|---|---|---|
| OpenRouter | `GET` on `OPENROUTER_URL` with `/chat/completions` → `/models` | `Authorization: Bearer` added when `OPENROUTER_API_KEY` is set (the endpoint also answers without a key) |
| Yandex | `GET` on `YANDEX_MODELS_URL` (`…/foundationModels/v1/models`) with `?folderId=<folder>`, `Authorization: Api-Key` + `x-folder-id` | the Models API — the list of what **this folder** really serves. Requires `YANDEX_API_KEY` **and** `YANDEX_FOLDER_ID`; skipped with a reason otherwise |
| Yandex (fallback) | `GET` on `YANDEX_LLM_URL` with `/chat/completions` → `/models` | tried when the Models API is unavailable (older install, proxy, 404) or answers with no models; both failures are reported together |

Transport: cURL, `Accept: application/json`, follow ≤ 3 redirects, timeout
`clamp(LLM_TIMEOUT_SEC, 10, 30)` s, connect timeout 10 s. Non-JSON body, HTTP ≥ 400 or a
transport error → `RuntimeException` for that provider only. The stored list is capped at
`MAX_MODELS` (500).

### Row normalization

OpenRouter `data[]` entries need an `id` containing `/`; everything else is optional:

| Row key | Value |
|---|---|
| `id` | `or-` + slug lowercased with non-alphanumerics collapsed to `-` (Yandex: `ya-`) |
| `label` | `name` from the response (≤ 70 chars), else the slug |
| `provider` | `openrouter` / `yandex` |
| `full_id` | the provider-native slug (Yandex: `gpt://<folder>/<slug>/latest` reduced to `<slug>`; another folder → row skipped) |
| `group` | `OpenRouter · <Vendor> (каталог)` / `Yandex AI Studio (каталог)` — the `<optgroup>` heading |
| `price_in` / `price_out` | `0.0` — the hardcoded rows' RUB estimates are **not** invented for live rows |
| `price_usd_in` / `price_usd_out` | OpenRouter `pricing.prompt` / `pricing.completion` × 1e6 (USD per 1M tokens), `0.0` when absent |
| `context` | OpenRouter `context_length`; Yandex `contextLength` / `context_length` / `maxTokens` / `maxInputTextTokens` when the answer states one, omitted otherwise |
| `free` | `true` when both prices are 0 |
| `vision` | the model accepts images — OpenRouter: `architecture.input_modalities` contains `image` (older payloads: the input half of `architecture.modality`); Yandex: a stated modality (`modalities` / `inputModalities` / `input_modalities`) when the answer carries one, otherwise a name heuristic, since the list usually answers with slugs only — `*-vl-*`, `*vl2*`, `*vision*`, `llava`, `pixtral`, `gemma-3-{4b,12b,27b}*`, `qwen*-vl*`. A Yandex vision row is also labelled and grouped `· зрение` |
| `live` | `true` |

Yandex accepts both the OpenAI shape (`{data:[{id}]}`) and `{models:[{modelUri|uri|id|name}]}`
(`items` is accepted as a wrapper too), and an entry may be a bare slug string. The exact
payload of the Models API differs between versions, which is why every field but the model
uri is read optionally — a shape that is not recognized costs the extra fields, not the row.
Entries of another folder, and uris that are not `gpt://` / `emb://` (`art://…`), are skipped.

Covered by `tests/model_catalog_yandex.php`.

## 5. Model versions — `lineage()` / `newerSiblings()`

`lineage()` splits the slug's last path segment (a `:free`-style suffix removed) on `-`/`_`
and picks the version token: the first dotted token (`4.1`, `2.5`), else the first `v<N>`,
else the last bare number (dates such as `2411` included). That token becomes `*` in the
lineage key and its digits become the version array. The vendor prefix is left out of the
key, so the same model matches across providers.

```
openai/gpt-4.1-mini          → gpt-*-mini          [4,1]
anthropic/claude-sonnet-4    → claude-sonnet-*     [4]
mistralai/mistral-large-2411 → mistral-large-*     [2411]
openai/gpt-4o, yandexgpt     → null (no readable version)
```

`versionCmp` compares component-wise, a missing component counting as `-1`, so `5.1 > 5 > 4.1`.
`newerSiblings($row, $models)` returns the catalogue rows with the **same lineage key** and a
strictly greater version, newest first; `ocr_only` rows and the row itself are excluded. No
readable version, or nothing newer, → empty array, and the caller just carries on with its
configured fallbacks. The match is deliberately conservative: every token but the version
must be identical, so an irregular rename (`gemini-2.0-flash-001` vs `gemini-2.5-flash`)
yields no match rather than a wrong one.

## 6. Consumers

- `config.php` — `decode()` + `merge()` at the end of the file, every request, no network.
- `setup.php` — `maybeRefresh()` on load, forced `refresh()` / `forget()` from the two
  buttons, and the cache status line (count, stamp, last error).
- `llm.php` — `newerSiblings()` behind `LLM::autoFallbackRows()` (`spec/llm.md` §4).
