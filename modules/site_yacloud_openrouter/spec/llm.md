# llm.php — LLM provider layer (OpenRouter + Yandex Cloud)

> Infrastructural module — no customer TZ behind it; no `[CODE §…]` citations (see `spec.md` §Provenance).
> Canonical source of this code: repository `site_yacloud_openrouter`.

`final class ToolkitLLM` — static, one instance per request. The legacy global `LLM` name is a conditional alias, created only when the host has not already declared that class. Internal module calls use `ToolkitLLM`, so a host's instance-based `LLM` can coexist without collisions. Two providers behind one
interface, per-request model/provider override, config-driven fallback (newer version of
the same model, then the operator's list, then per-provider fallbacks), vision
(images) and PDF OCR, provider self-test, optional call logging.

## 1. Lifecycle

```php
LLM::init(array $cfg, $store = null): void   // $cfg = require config.php
LLM::cfg(): array                            // throws if init() was not called
```

`$store` — optional duck-typed logger; used only if it exposes
`logLLMCall($sessionId, $step, $model, $promptVersion, $latencyMs, $status, $error, $raw)`.
Absent/failing logger never breaks a call (§7).

## 2. Model & provider resolution

| Function | Contract |
|---|---|
| `setModelOverride(?string $shortId)`   | per-request model; short id from `AVAILABLE_MODELS`; `null` clears |
| `setProviderOverride(?string $p)`      | `'openrouter'` \| `'yandex'`; `null` → `cfg.LLM_PROVIDER` |
| `effectiveProvider(): string`          | override ?: `cfg.LLM_PROVIDER` |
| `findModel(string $shortId): ?array`   | row from `AVAILABLE_MODELS` by `id`, else `null` |
| `resolveModelSpec(string $spec): ?array` | `"yandex:<slug>"` \| short `id` \| bare `full_id` → row. A provider-qualified slug absent from the catalogue still resolves, keeping its stated provider |
| `rowIsVision(array $row): bool`        | row accepts images; missing `vision` key = «maybe» (never skipped) |
| `catalogueRow(string $p, string $slug): ?array` *(private)* | the catalogue row for provider+slug (live and hardcoded share the slug after `ModelCatalog::merge()`), carrying its `live` / `vision` flags |
| `rowIsDead(array $row): bool` *(private)* | the provider answered its catalogue and did not list this row (§4.1) |
| `hasLiveRows(string $p): bool` *(private)* | the catalogue holds at least one provider-confirmed row for `$p` |
| `visionModelRow(): ?array`             | `LLM_VISION_MODEL` through `resolveModelSpec`, `ocr_only` rows rejected |
| `visionModelRows(): array`             | every row with `vision === true` — the vision fallback pool and the admin dropdown |
| `configSummary(): array`               | non-secret snapshot of provider/model/key state, attached to every failure |
| `activeModel(): array` *(private)*     | override model → else `LLM_DEFAULT_MODEL` row → honours provider override |
| `providerPriority(): array` *(private)* | `LLM_PROVIDER_PRIORITY` split/validated against the two known providers |

Model row shape: `['id','label','provider','full_id','price_in','price_out','vision'?,'ocr_only'?,'live'?]`.
`full_id` semantics: OpenRouter — the model slug sent as-is; Yandex — the slug inside
`gpt://<YANDEX_FOLDER_ID>/<full_id>/latest` (`LLM::yandexModelUri()`). The version
segment belongs to the address, not to `full_id`: a slug that already carries one
(`yandexgpt/rc`, `yandexgpt/deprecated`) keeps it, anything else gets `/latest`.
`vision` — `true` the model takes images, `false` it does not, absent = unknown
(treated as «maybe»).

## 3. Entry points

```php
LLM::chatText(string $system, string $user, ?int $sessionId = null, float $temp = 0.7): string
LLM::chatJson(string $system, string $user, ?int $sessionId = null, float $temp = 0.1): array
LLM::visionJson(string $system, string $userText, array $imageDataUrls, ?int $sessionId = null, float $temp = 0.1): array
LLM::probe(): array                                 // provider self-test, never throws
LLM::lastTrace(): array                             // per-candidate outcome of the last dispatch
LLM::traceText(): string
LLM::failureReason(?array $trace = null): string    // one sentence per provider, for the end user (§4.3)
LLM::candidateChain(bool $vision): array            // who would be asked, without asking (§4.1)
LLM::dispatchPair(array $specA, array $specB, ?int $sessionId): array   // [parsedA, parsedB]
LLM::ocrPdf(string $pdfPath): ?string
LLM::render(string $tpl, array $vars): string       // {{token}} substitution
LLM::jsonCompact($v): string                        // JSON_UNESCAPED_UNICODE|SLASHES
```

- `chatText` → `callText` → `dispatch(json:false)`, result trimmed.
- `visionJson` → `callVisionJson` → `dispatch(json:true, vision:true)`. The user turn is
  `[{type:text},{type:image_url,image_url:{url}},…]`; images are `data:` URIs. Same JSON
  re-ask as `chatJson`.
- `probe()` — one minimal completion per configured leg (default model, vision model,
  each per-provider fallback). Returns `[{leg,model,ok,text}]`, never throws, writes every
  leg to the diagnostic log (`/spec/diag_log.md`).
- `chatJson` → `callJson` → `dispatch(json:true)` + `parseJson`. On unparseable JSON:
  one stricter re-ask (`step` suffixed `_retry`, `temp=0.0`, extra user turn demanding a
  single JSON object, no markdown). The re-ask **names what went wrong with the previous
  answer** (`json_last_error_msg()`, and «ответ оборвался по пределу длины» when
  `lastTruncated()` says the provider stopped at the token limit) and, when truncated,
  demands a shorter answer — asking again in the same words gets the same stump back.
  Still unparseable → `RuntimeException` naming the reason, and `LLM_MAX_TOKENS` when
  the answer was cut off.
- `visionJson` → `callVisionJson` — same shape as `callJson` (dispatch + parseJson +
  one stricter re-ask), but the user turn is multimodal:
  `visionContent()` builds `[{type:text,...}?, {type:image_url,image_url:{url}}, ...]`
  from `$userText` + `$imageDataUrls` (each a `data:image/...;base64,...` URI, or a
  plain https URL). No image bytes are read from disk here — the caller (e.g. a food-photo
  endpoint) hands over already-encoded `data:` URIs. Goes through the same `dispatch()`
  fallback chain as `chatJson`: pick a vision-capable model (`setModelOverride` or a
  vision-capable `LLM_DEFAULT_MODEL`, e.g. `gemini-2.0-flash`) — a candidate that can't
  read images simply fails its slot (`no_content`/`exception`) and the chain moves on,
  same as any other `dispatch()` failure (§4).
- `dispatchPair` — two calls in parallel via `curl_multi` on the **primary** model only
  (spec: `['step','system','user','temp','json']`). Provider not configured → both fall
  back to sequential `callJson`. Per-side transport failure / empty content / unparseable
  JSON → that side is retried through `callJson`, so the fallback chain and the JSON
  re-ask still apply. Statuses logged: `multi_fail`, `multi_no_content`, `ok`.

## 4. Fallback chain — `candidateChain()` + `dispatch()`

### 4.1 Who gets asked — `candidateChain(bool $vision): array`

`['primary' => row, 'candidates' => [row…], 'skipped' => [trace…]]`. Public and pure
over the config — no network — so an admin page or a test reads exactly the chain the
dispatch walks (`tests/llm_chain.php`).

Candidates, in order:

1. The primary model: `activeModel()`, or — when `$vision` — `visionModelRow()` if
   `LLM_VISION_MODEL` resolves, falling back to `activeModel()`.
2. **Newer versions of that same model**, newest first — only when
   `LLM_FALLBACK_MODE` is `auto` (the default). They come from
   `LLM::autoFallbackRows()` → `ModelCatalog::newerSiblings()`
   (`/spec/model_catalog.md` §5), so a refreshed live catalogue immediately supplies a
   fresher backup (`claude-sonnet-4.5` → `claude-sonnet-4.6`). A model whose slug carries
   no readable version (`yandexgpt`, `gpt-4o`) simply contributes nothing here.
3. The operator's `LLM_FALLBACK_MODELS` — comma-separated **short ids** resolved through
   `findModel()` (`LLM::configuredFallbackRows()`; unknown ids and `ocr_only` rows are
   dropped). With `LLM_FALLBACK_MODE=manual` this is the only configured backup.
4. When `$vision`: every `visionModelRows()` row (the whole known-multimodal pool).
5. For each provider in `providerPriority()`, its per-provider fallback model
   (`LLM_FALLBACK_MODEL` for openrouter, `YANDEX_FALLBACK_MODEL` for yandex), resolved
   through `catalogueRow()` so the row carries the catalogue's `live` / `vision` flags —
   no row there, the slug travels as the operator wrote it.

Dropped along the way, each with its reason in `skipped` (`['candidate' => …,
'result' => 'пропущен: …']`, merged into the trace by `dispatch()`):

| Filter | Dropped when | Reason recorded |
|---|---|---|
| `rowIsDead($row)` | the provider answered its catalogue (`hasLiveRows()`) and did not list this slug (`live` unset) | `провайдер не назвал эту модель в своём каталоге` |
| `rowIsVision($row)` | `$vision` and the row does not take images | `модель не принимает изображения` |
| credentials | openrouter without `OPENROUTER_API_KEY`; yandex without `YANDEX_API_KEY` **and** `YANDEX_FOLDER_ID` | *(silent — nothing was configured)* |

`rowIsDead()` is the verdict the admin page draws with ⛔: every such request comes back
`Failed to get model` and buries the real reason the chain got that far. The **hardcoded**
`AVAILABLE_MODELS` list does not vouch for a slug — it ages with the release, the live
answer does not; a provider whose catalogue never arrived proves nothing and its rows stay
usable. The **chosen** model is never dropped: it is an explicit decision.

Steps 2–5 keep each row's own provider — **a slug never travels to the other provider**.
A candidate identical to the primary `full_id` on the primary provider is skipped, and the
final list is deduplicated by `provider|full_id` so no pair is attempted twice.

### 4.2 Who actually answers — `dispatch()`

`dispatch($step, $messages, $sessionId, $temp, $json, $vision = false)` walks
`candidateChain($vision)['candidates']` in order; each attempt is logged with
`provider:full_id` as the model tag:

| Outcome | Status logged | Action |
|---|---|---|
| HTTP ok, content non-empty | `ok` | return content |
| response not an array | `empty` | next candidate |
| no `choices[0].message.content` | `no_content` | next candidate |
| transport/HTTP ≥ 400 / throw | `exception` | next candidate |

Every attempt lands in `self::$trace` (`lastTrace()` / `traceText()`):
`['candidate' => 'provider:slug', 'result' => …, 'latency_ms' => …]`, on top of the
`skipped` lines the chain already produced.

**A provider that refused for a reason that is not about the model is dropped for the rest
of the chain.** `LLMHttpError::providerWide()` decides (§5); non-`null` → every remaining
candidate of that provider is recorded as `пропущен: <reason>` without a request. Asking
them anyway spends a second each and buries the real cause.

All candidates exhausted → `RuntimeException` carrying the **whole chain**, not the last
error alone: `"LLM <step> failed (<n> попыток): [1] <provider:slug> → <error>; [2] …"`.
(With `openrouter → yandex`, the last line used to be all the operator saw, so a missing
OpenRouter key read as a Yandex problem.) The same failure is written to the diagnostic
log with `configSummary()` attached — `/spec/diag_log.md`.

### 4.3 What the person waiting is told — `failureReason(?array $trace = null): string`

The numbered chain belongs in the admin log, not on a phone screen. Attempts are grouped
by provider and reduced to a cause (`causeOf()`, private), so ten `Failed to get model`
read as one `yandex: модели не включены в каталоге облака`. Format:
`"<provider>: <cause>[, <cause>…][; <provider>: …]"`, empty string when the last dispatch
did not fail. `$trace` defaults to the last dispatch's.

| Provider answer contains | Cause |
|---|---|
| `пропущен:` (prefix) | *(none — a model we chose not to ask is not a failure)* |
| `Failed to get model` | `модель не включена в каталоге облака` |
| `not available via gRPC` / `use HTTP OpenAI API` | `модель отвечает только по OpenAI-совместимому адресу` |
| `Access denied by security policy` | `запрос блокирует хостинг` |
| `HTTP 401` / `HTTP 403` / `HTTP 429` | `ключ не принят` / `доступ к модели запрещён` / `превышен лимит запросов` |
| `text is empty`, `empty message text` | `модель не приняла изображение` |
| `not configured` / `timed out`, `timeout` / `HTTP 5xx` | `провайдер не настроен` / `провайдер не ответил вовремя` / `провайдер отвечает ошибкой` |
| anything else | `провайдер отказал` |

**Cross-provider notification.** When the primary provider was `openrouter` and a
`yandex` candidate served the call, `notifyOpenRouterFallback()` fires
`Mailer::sendErrorNotification` once per request (static guard), only if the `Mailer`
class is loaded; any error inside is swallowed.

## 5. HTTP transport

`buildCurl()` builds the handle (shared by `http()` and `dispatchPair`);
`http()` = `buildCurl` + `curl_exec` + decode.

| Provider | URL | Headers | Model string |
|---|---|---|---|
| `openrouter` | `OPENROUTER_URL` | `Authorization: Bearer <OPENROUTER_API_KEY>`, `HTTP-Referer` (`OPENROUTER_REFERER`), `X-Title` (`OPENROUTER_TITLE`) | `full_id` |
| `yandex` | `YANDEX_LLM_URL` (OpenAI-compatible) or `YANDEX_LLM_URL_FM` (Foundation Models) — per model, see §5.1 | `Authorization: Api-Key <YANDEX_API_KEY>`, `x-folder-id` | `yandexModelUri()` → `gpt://<folder>/<full_id>/latest` |

Body: `{model, messages, temperature}` + `response_format={"type":"json_object"}` when
`$jsonMode` + `max_tokens` when `LLM_MAX_TOKENS > 0` (`0` = the provider's own default,
so nothing that fits today starts being cut off) + any `$extra` keys merged in (used by
OCR strategies). Timeouts:
`CURLOPT_TIMEOUT = LLM_TIMEOUT_SEC`, `CURLOPT_CONNECTTIMEOUT = 15`. Missing credentials →
throws before the request. HTTP ≥ 400 or transport error → `LLMHttpError` (extends `RuntimeException`)
`"<PROVIDER> HTTP <code> @ <url> model=<model string>[ json_object]: <curl error | first
400 chars of body>"` — the endpoint and the model string as the provider saw it are part
of the message, because `YANDEX HTTP 400: Failed to get model` alone names no model.

`final class LLMHttpError` carries `$provider`, `$status` and `$body` (first 2000 chars)
next to the message, so the chain can tell one dead model from a whole provider leg:

```php
LLMHttpError::providerWide(): ?string   // null = this is about the model asked for
```

`401`/`407` → `ключ провайдера отклонён`. `403` → `null` when the body is the provider's
own error envelope (`{"error":{…}}`), otherwise `запрос к провайдеру заблокирован на
подступах (хостинг или прокси)` — a bare string or an HTML page under 403 comes from
something standing in front of the provider (hosting WAF, proxy), not from it. Every other
status → `null`. Used by `dispatch()` (§4.2).

### 5.1 Yandex has two endpoints — the route is asked, not assumed

Yandex Cloud serves the same folder through two addresses, and not every model is on
both: the YandexGPT family answers on `foundationModels/v1/completion`, the open models
(Llama, Qwen, Gemma, DeepSeek, GPT-OSS) on the OpenAI-compatible `v1/chat/completions`.
A model asked at the wrong address answers HTTP 400 — *"Model is not available via gRPC
API. Please use HTTP OpenAI API instead"* — and the candidate is lost for a reason that
has nothing to do with the model.

| Function | Contract |
|---|---|
| `yandexRoute(string $slug): string` | `openai` \| `fm` — what this slug is known to answer on; `openai` (the configured `YANDEX_LLM_URL`) until something is learned |
| `yandexRouteHint(string $error): ?string` | the route the provider's own refusal names, `null` when it names none |
| `yandexFmBody(array $row, array $messages, float $temp, bool $json): array` | the Foundation Models request: `{modelUri, completionOptions:{stream,temperature,maxTokens?,responseFormat?}, messages:[{role,text}]}` |
| `noteYandexRoute(string $slug, string $route)` *(private)* | remembers the route for the rest of the request |

A Yandex candidate that fails with 400/404 is **re-asked once at the other address**, and
the address that answered is remembered for the rest of the request. The re-ask happens
when the provider named the other endpoint itself, or — at most once per request, and
never after some Yandex call already succeeded at the configured address — blind: one
extra request is cheaper than a silently lost candidate. Multimodal turns and `$extra`
keys (OCR plugins) are never re-asked on the Foundation Models address: that shape has no
images and no plugins there. The trace and the diagnostic log carry the address that
answered (`yandex:gemma-3-27b-it@fm`).

The Foundation Models answer (`result.alternatives[0]`) is normalized into the OpenAI
shape before anything reads it, so `extractContent()`, `parseJson()` and the chain stay
one code path.

### 5.2 A truncated answer is never valid JSON

`lastTruncated(): bool` — the provider says it stopped at the length limit:
`finish_reason: length` on the OpenAI shape, `…TRUNCATED_FINAL` on the Foundation Models
one. `callJson()` / `callVisionJson()` put that into the re-ask (§3) instead of repeating
the same request, and the final exception points at `LLM_MAX_TOKENS`.

Response helpers: `extractContent()` reads `choices[0].message.content`, joining
`[{text:…}]` parts with `\n`; `parseJson()` (public, pure — the test reads it without a
network) strips ```` ```json ```` fences and `<think>` reasoning, takes the widest
`{…}`/`[…]` slice, and repairs what a model commonly breaks: an answer cut off
mid-value (the brackets the model opened are closed, nothing is invented), a trailing
comma, «ёлочки» instead of straight quotes, raw line breaks inside a string literal, and
bytes that are not valid UTF-8 (they make every `/u` pattern bail out, which turned the
whole answer into «not JSON»).

## 6. PDF OCR — `ocrPdf()`

Input file → base64 `data:application/pdf;base64,…` in an OpenAI-style multimodal
message (`type:file`, `filename:document.pdf`) with a "return only raw text" system prompt.

**Yandex-first condition** (`$preferYandexOcr`): the override model row has
`ocr_only`, **or** `effectiveProvider() === 'yandex'`, **or** the first entry of
`LLM_PROVIDER_PRIORITY` is `yandex`.

Order:
1. `tryYandexVisionOcr()` — only when Yandex-first.
2. OpenRouter: for each model of `LLM_OCR_MODELS` (empty → `[LLM_VISION_MODEL]`), three
   strategies in order — file-parser plugin `pdf-text` (free, text PDFs) → file-parser
   plugin `mistral-ocr` (paid, scans) → `native` (no plugin). First non-empty content
   wins; success logged as `[ocrPdf] success via <model>/<strategy> (<chars> chars)`.
   Skipped entirely when `OPENROUTER_API_KEY` is empty.
3. `tryYandexVisionOcr()` — when not Yandex-first (final fallback).

Nothing produced text → `RuntimeException('PDF OCR failed: ' . <all reasons joined by " | ">)`.

`LLM_OCR_MODELS` entries take the same spellings as `LLM_VISION_MODEL` and are resolved
through `resolveModelSpec()`; a non-OpenRouter row is not sent a PDF (Yandex chat models
take images, not files) and is noted in the error list — PDF on Yandex goes through Yandex
Vision OCR.

`tryYandexVisionOcr(string $pdfBytes, array &$errors): ?string` *(private)* — POSTs
`{mimeType:'application/pdf', languageCodes:[ru,en], model:YANDEX_OCR_MODEL, content:<b64>}`
to `YANDEX_OCR_URL` with the Api-Key + folder headers; concatenates per-page
`result.textAnnotation.fullText` (NDJSON or single JSON). Gated by
`YANDEX_OCR_ENABLED === '1'` and non-empty key + folder. Never throws — returns `null`
and appends a reason (`yandex-ocr: disabled` / `creds empty` / HTTP or parse detail).

## 7. Call logging

`logCall()` *(private)* forwards to `$store->logLLMCall(...)` with
`PROMPT_VERSION` from config; no store, no `logLLMCall` method, or a throwing store → no-op.
Statuses used: `ok`, `empty`, `no_content`, `exception`, `multi_fail`, `multi_no_content`.

`diag()` *(private)* additionally writes to `DiagLog` **if that class is loaded**
(`/spec/diag_log.md`) — a consuming project that did not vendor it loses nothing.

## 8. CLI smoke modes (`example.php`)

```
php example.php chat  "<prompt>"    # LLM::chatText
php example.php ocr   <file.pdf>    # LLM::ocrPdf
php example.php parse <file.docx>   # Parser::extract + normalize
php example.php email <address>     # Mailer::sendCustom
```
