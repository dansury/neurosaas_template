<?php
/**
 * site_yacloud_openrouter — configuration (extracted from resume-index).
 * spec: spec/settings.md §1 — infrastructural code, no customer TZ behind it.
 * Resolution order per key (highest priority first):
 *   1. `settings` DB row (editable via setup.php — admin only)
 *   2. process-ENV variable
 *   3. hardcoded fallback below
 * No secrets in code — supply keys via ENV or setup.php.
 */

// Live provider catalogue (cached in `settings`) is merged into
// AVAILABLE_MODELS at the end of this file — parsing only, no network here.
require_once __DIR__ . '/model_catalog.php';

if (!function_exists('cfg_env')) {
    function cfg_env(string $key, ?string $default = null): ?string {
        $v = getenv($key);
        if ($v === false || $v === '') return $default;
        return $v;
    }
}

// Operator-editable keys: only these are overlaid from the `settings` table.
if (!function_exists('cfg_settings_whitelist')) {
    function cfg_settings_whitelist(): array {
        return [
            'LLM_PROVIDER', 'LLM_DEFAULT_MODEL', 'LLM_PROVIDER_PRIORITY',
            'OPENROUTER_API_KEY', 'LLM_VISION_MODEL', 'LLM_FALLBACK_MODEL',
            'LLM_FALLBACK_MODE', 'LLM_FALLBACK_MODELS',
            // Live model catalogue: cache, refresh stamp, last failure, TTL.
            'MODEL_CATALOG_MODELS', 'MODEL_CATALOG_SYNCED_AT', 'MODEL_CATALOG_ERROR',
            'MODEL_CATALOG_TTL_MIN',
            'LLM_OCR_MODELS', 'YANDEX_FALLBACK_MODEL',
            'YANDEX_API_KEY', 'YANDEX_FOLDER_ID', 'YANDEX_LLM_URL', 'YANDEX_LLM_URL_FM',
            'YANDEX_MODELS_URL', 'LLM_MAX_TOKENS',
            'YANDEX_OCR_URL', 'YANDEX_OCR_MODEL', 'YANDEX_OCR_ENABLED',
            'ADMIN_EMAIL', 'ERROR_EMAIL',
            'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_FROM_NAME',
            'ADMIN_PASSWORD',
            // Auto-pull: the deploy checkbox of active development (auto_pull.php).
            'AUTOPULL_ENABLED', 'AUTOPULL_INTERVAL', 'AUTOPULL_URL',
        ];
    }
}

$config = [
    /* ── LLM provider switch ── */
    'LLM_PROVIDER'          => cfg_env('LLM_PROVIDER', 'openrouter'),       // 'yandex' | 'openrouter'
    'LLM_DEFAULT_MODEL'     => cfg_env('LLM_DEFAULT_MODEL', 'gemini-2.0-flash'),
    // Comma-separated provider fallback order for LLM::dispatch(). Primary first.
    'LLM_PROVIDER_PRIORITY' => cfg_env('LLM_PROVIDER_PRIORITY', 'openrouter,yandex'),
    // What to try right after the chosen model:
    //   auto   — a NEWER VERSION of the same model from the catalogue
    //            (gpt-4.1 → gpt-5.1), then LLM_FALLBACK_MODELS;
    //   manual — LLM_FALLBACK_MODELS only.
    'LLM_FALLBACK_MODE'     => cfg_env('LLM_FALLBACK_MODE', 'auto'),   // 'auto' | 'manual'
    // Operator-picked backups (short ids from AVAILABLE_MODELS), tried on every
    // provider after the chosen model and its newer versions.
    'LLM_FALLBACK_MODELS'   => cfg_env('LLM_FALLBACK_MODELS', ''),

    /* ── OpenRouter ── (supply key via ENV or setup.php) */
    'OPENROUTER_API_KEY'    => cfg_env('OPENROUTER_API_KEY', ''),
    'OPENROUTER_URL'        => 'https://openrouter.ai/api/v1/chat/completions',
    'LLM_VISION_MODEL'      => cfg_env('LLM_VISION_MODEL', 'yandex:qwen3.6-35b-a3b'),
    'LLM_FALLBACK_MODEL'    => cfg_env('LLM_FALLBACK_MODEL', 'openrouter/auto'),
    'YANDEX_FALLBACK_MODEL' => cfg_env('YANDEX_FALLBACK_MODEL', 'yandexgpt-5-lite'),
    /* ── Live model catalogue ─────────────────────────────────────────────
       The model list is pulled from the providers (OpenRouter GET /models,
       Yandex GET /foundationModels/v1/models) and cached in `settings`. setup.php refreshes the
       cache on load once it is older than MODEL_CATALOG_TTL_MIN minutes; here
       the stored JSON is only parsed — no network (see ModelCatalog). */
    'MODEL_CATALOG_MODELS'    => '',   // JSON catalogue rows; filled from settings
    'MODEL_CATALOG_SYNCED_AT' => '',   // last successful refresh
    'MODEL_CATALOG_ERROR'     => '',   // last failure reason, rendered in setup.php
    'MODEL_CATALOG_TTL_MIN'   => cfg_env('MODEL_CATALOG_TTL_MIN', (string) ModelCatalog::TTL_MIN),
    // OpenRouter model ids tried in order during PDF OCR; first non-empty wins.
    'LLM_OCR_MODELS'        => array_values(array_filter(array_map('trim', explode(',', (string) cfg_env(
        'LLM_OCR_MODELS',
        'google/gemini-2.5-flash,google/gemini-2.0-flash-001'
    ))))),

    /* ── Yandex Cloud Foundation Models (OpenAI-compatible mode) ── */
    'YANDEX_API_KEY'        => cfg_env('YANDEX_API_KEY', ''),
    'YANDEX_FOLDER_ID'      => cfg_env('YANDEX_FOLDER_ID', ''),
    'YANDEX_LLM_URL'        => cfg_env('YANDEX_LLM_URL', 'https://llm.api.cloud.yandex.net/v1/chat/completions'),
    // The second Yandex address. Not every model of the folder is on both: the
    // YandexGPT family answers here, the open ones on the OpenAI-compatible URL
    // above. Which slug needs which is asked, not assumed (spec/llm.md §5.1).
    'YANDEX_LLM_URL_FM'     => cfg_env('YANDEX_LLM_URL_FM', 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion'),
    // Models API: the folder's real model list (GET, folderId in the query). The
    // OpenAI-compatible /v1/models is the fallback when this one is unavailable.
    'YANDEX_MODELS_URL'     => cfg_env('YANDEX_MODELS_URL', 'https://llm.api.cloud.yandex.net/foundationModels/v1/models'),
    // Yandex Vision OCR (https://yandex.cloud/docs/vision/concepts/ocr).
    'YANDEX_OCR_URL'        => cfg_env('YANDEX_OCR_URL', 'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText'),
    'YANDEX_OCR_MODEL'      => cfg_env('YANDEX_OCR_MODEL', 'page'),
    // '1' → Yandex Vision OCR participates in the PDF-OCR chain. Needs key + folder.
    'YANDEX_OCR_ENABLED'    => cfg_env('YANDEX_OCR_ENABLED', '1'),

    // Answer length ceiling. 0 — the provider's own limit, nothing is sent, so
    // nothing that fits today starts being cut off. Above zero it travels as
    // `max_tokens` / `completionOptions.maxTokens`.
    'LLM_MAX_TOKENS'        => (int) cfg_env('LLM_MAX_TOKENS', '0'),
    'LLM_TIMEOUT_SEC'       => (int) cfg_env('LLM_TIMEOUT_SEC', '120'),
    'LLM_MAX_RETRIES'       => (int) cfg_env('LLM_MAX_RETRIES', '2'),

    /* ── Admin / setup gate ── */
    'ADMIN_PASSWORD'        => cfg_env('ADMIN_PASSWORD', ''),

    /* ── Mail (defaults target Yandex SMTP) ── */
    'ADMIN_EMAIL'           => cfg_env('ADMIN_EMAIL', ''),
    // Error-notification mailbox (throttled; see Mailer::sendErrorNotification).
    'ERROR_EMAIL'           => cfg_env('ERROR_EMAIL', ''),
    'SMTP_HOST'             => cfg_env('SMTP_HOST', 'smtp.yandex.ru'),
    'SMTP_PORT'             => (int) cfg_env('SMTP_PORT', '465'),
    'SMTP_USER'             => cfg_env('SMTP_USER', ''),
    'SMTP_PASS'             => cfg_env('SMTP_PASS', ''),
    'SMTP_FROM'             => cfg_env('SMTP_FROM', cfg_env('SMTP_USER', '') ?: ''),
    'SMTP_FROM_NAME'        => cfg_env('SMTP_FROM_NAME', 'site_yacloud_openrouter'),

    /* ── Auto-pull: check GitHub for a newer commit on every page (auto_pull.php).
       Credentials are NOT here — repo, token and the pull.php password live in
       pull-config.php next to pull.php. ── */
    'AUTOPULL_ENABLED'      => cfg_env('AUTOPULL_ENABLED', '0'),
    'AUTOPULL_INTERVAL'     => (int) cfg_env('AUTOPULL_INTERVAL', '0'),  // 0 — every page view
    'AUTOPULL_URL'          => cfg_env('AUTOPULL_URL', ''),              // empty — derive from DOCUMENT_ROOT

    /* ── Storage ── */
    'DB_PATH'               => cfg_env('DB_PATH', __DIR__ . '/data/app.db'),
    'LOG_DIR'               => cfg_env('LOG_DIR', __DIR__ . '/data/logs'),
    'PROMPT_VERSION'        => 'v1.0',

    /* ── Available models (chat + OCR). price_in/price_out: RUB per 1k tokens (approx).
       Yandex `full_id` is the slug used in gpt://<folder>/<full_id>/latest.
       `vision` — the model accepts images (photos, labels, page scans). Absent
       on live catalogue rows the provider gave no modality for; LLM treats the
       absence as "maybe" and only ever SKIPS rows explicitly marked false. ── */
    'AVAILABLE_MODELS'      => [
        // `group` names the <optgroup> the row lands in (setup.php dropdown).
        // ── Yandex AI Studio — first-party (Yandex AI Studio model catalogue) ──
        // `context` — the model's context window in tokens, as the catalogue documents it.
        // `price_in`/`price_out` stay 0.0 where no RUB estimate is worth stating: the
        // dropdown then shows the model without an invented price.
        ['id' => 'aliceai-llm',       'label' => 'Alice AI LLM',       'provider' => 'yandex', 'full_id' => 'aliceai-llm',       'group' => 'Yandex AI Studio', 'price_in' => 0.0,  'price_out' => 0.0,  'context' => 131072,  'vision' => false],
        ['id' => 'aliceai-llm-flash', 'label' => 'Alice AI LLM Flash', 'provider' => 'yandex', 'full_id' => 'aliceai-llm-flash', 'group' => 'Yandex AI Studio', 'price_in' => 0.0,  'price_out' => 0.0,  'context' => 65536,   'vision' => false],
        ['id' => 'yandexgpt-5.1',     'label' => 'YandexGPT Pro 5.1',  'provider' => 'yandex', 'full_id' => 'yandexgpt-5.1',     'group' => 'Yandex AI Studio', 'price_in' => 1.20, 'price_out' => 1.20, 'context' => 32768,   'vision' => false],
        ['id' => 'yandexgpt-5-pro',   'label' => 'YandexGPT Pro 5',    'provider' => 'yandex', 'full_id' => 'yandexgpt-5-pro',   'group' => 'Yandex AI Studio', 'price_in' => 1.20, 'price_out' => 1.20, 'context' => 32768,   'vision' => false],
        ['id' => 'yandexgpt-5-lite',  'label' => 'YandexGPT Lite 5',   'provider' => 'yandex', 'full_id' => 'yandexgpt-5-lite',  'group' => 'Yandex AI Studio', 'price_in' => 0.20, 'price_out' => 0.20, 'context' => 32768,   'vision' => false],
        ['id' => 'deepseek-v4-flash', 'label' => 'DeepSeek V4 Flash',  'provider' => 'yandex', 'full_id' => 'deepseek-v4-flash', 'group' => 'Yandex AI Studio', 'price_in' => 0.0,  'price_out' => 0.0,  'context' => 1048576, 'vision' => false],
        ['id' => 'gpt-oss-120b',      'label' => 'gpt-oss-120b',       'provider' => 'yandex', 'full_id' => 'gpt-oss-120b',      'group' => 'Yandex AI Studio', 'price_in' => 0.0,  'price_out' => 0.0,  'context' => 131072,  'vision' => false],
        ['id' => 'gpt-oss-20b',       'label' => 'gpt-oss-20b',        'provider' => 'yandex', 'full_id' => 'gpt-oss-20b',       'group' => 'Yandex AI Studio', 'price_in' => 0.0,  'price_out' => 0.0,  'context' => 131072,  'vision' => false],
        ['id' => 'qwen3-235b-a22b-fp8', 'label' => 'Qwen3 235B',       'provider' => 'yandex', 'full_id' => 'qwen3-235b-a22b-fp8', 'group' => 'Yandex AI Studio', 'price_in' => 0.0, 'price_out' => 0.0, 'context' => 262144, 'vision' => false],
        // ── Yandex AI Studio — open catalogue (slug = gpt://folder/<full_id>/latest).
        //    Enabled per folder and region: what this folder really serves is what the
        //    Models API reports (ModelCatalog::fetchYandex), these rows are candidates. ──
        ['id' => 'llama-3.3-70b-instruct',  'label' => 'Llama 3.3 70B Instruct',  'provider' => 'yandex', 'full_id' => 'llama-3.3-70b-instruct',  'group' => 'Yandex AI Studio', 'price_in' => 0.50, 'price_out' => 0.50, 'vision' => false],
        ['id' => 'llama-3.1-70b-instruct',  'label' => 'Llama 3.1 70B Instruct',  'provider' => 'yandex', 'full_id' => 'llama-3.1-70b-instruct',  'group' => 'Yandex AI Studio', 'price_in' => 0.50, 'price_out' => 0.50, 'vision' => false],
        ['id' => 'qwen2.5-72b-instruct',    'label' => 'Qwen2.5 72B Instruct',    'provider' => 'yandex', 'full_id' => 'qwen2.5-72b-instruct',    'group' => 'Yandex AI Studio', 'price_in' => 0.50, 'price_out' => 0.50, 'vision' => false],
        ['id' => 'qwen2.5-32b-instruct',    'label' => 'Qwen2.5 32B Instruct',    'provider' => 'yandex', 'full_id' => 'qwen2.5-32b-instruct',    'group' => 'Yandex AI Studio', 'price_in' => 0.30, 'price_out' => 0.30, 'vision' => false],
        ['id' => 'qwen2.5-7b-instruct',     'label' => 'Qwen2.5 7B Instruct',     'provider' => 'yandex', 'full_id' => 'qwen2.5-7b-instruct',     'group' => 'Yandex AI Studio', 'price_in' => 0.15, 'price_out' => 0.15, 'vision' => false],
        ['id' => 'gemma-3-1b-it',           'label' => 'Gemma 3 1B IT',           'provider' => 'yandex', 'full_id' => 'gemma-3-1b-it',           'group' => 'Yandex AI Studio', 'price_in' => 0.10, 'price_out' => 0.10, 'vision' => false],
        // Multimodal Yandex rows — what a photo / label / scan can be sent to.
        ['id' => 'gemma-3-4b-it',   'label' => 'Gemma 3 4B IT (зрение)',   'provider' => 'yandex', 'full_id' => 'gemma-3-4b-it',   'group' => 'Yandex AI Studio · зрение', 'price_in' => 0.15, 'price_out' => 0.15, 'vision' => true],
        ['id' => 'gemma-3-12b-it',  'label' => 'Gemma 3 12B IT (зрение)',  'provider' => 'yandex', 'full_id' => 'gemma-3-12b-it',  'group' => 'Yandex AI Studio · зрение', 'price_in' => 0.30, 'price_out' => 0.30, 'vision' => true],
        ['id' => 'gemma-3-27b-it',  'label' => 'Gemma 3 27B IT (зрение)',  'provider' => 'yandex', 'full_id' => 'gemma-3-27b-it',  'group' => 'Yandex AI Studio · зрение', 'price_in' => 0.45, 'price_out' => 0.45, 'vision' => true],
        // Slug has no "-vl-" segment, so ModelCatalog::yandexSeesImages() (name-based
        // heuristic, see T072) can't auto-detect it — hardcoded here as the default vision model.
        ['id' => 'qwen3.6-35b-a3b', 'label' => 'Qwen3.6 35B A3B (зрение)', 'provider' => 'yandex', 'full_id' => 'qwen3.6-35b-a3b', 'group' => 'Yandex AI Studio · зрение', 'price_in' => 0.35, 'price_out' => 0.35, 'context' => 262144, 'vision' => true],
        // ── Yandex Vision OCR (PDF text recognition, not a chat model) ──
        ['id' => 'yandex-vision-ocr', 'label' => 'Yandex Vision OCR (PDF)', 'provider' => 'yandex', 'full_id' => 'yandex-ocr-page', 'group' => 'Yandex Vision', 'price_in' => 0.0, 'price_out' => 0.0, 'ocr_only' => true],
        // ── OpenRouter ──
        ['id' => 'openrouter-deepseek-r1', 'label' => 'DeepSeek R1 (OpenRouter)', 'provider' => 'openrouter', 'full_id' => 'deepseek/deepseek-r1', 'group' => 'OpenRouter', 'price_in' => 50.0, 'price_out' => 200.0, 'vision' => false],
        ['id' => 'gpt-4o',           'label' => 'GPT-4o (OpenRouter)',           'provider' => 'openrouter', 'full_id' => 'openai/gpt-4o',                'group' => 'OpenRouter', 'price_in' => 230.0, 'price_out' => 920.0, 'vision' => true],
        ['id' => 'gemini-2.0-flash', 'label' => 'Gemini 2.0 Flash (OpenRouter)', 'provider' => 'openrouter', 'full_id' => 'google/gemini-2.0-flash-001', 'group' => 'OpenRouter', 'price_in' => 9.0,   'price_out' => 36.0, 'vision' => true],
    ],
];

/**
 * Overlay operator-editable settings from the `settings` table so setup.php can
 * change API keys / provider / models / mail without code edits. Best-effort:
 * never fails config loading if the DB is missing or locked. Whitelist-gated.
 */
(static function (array &$config): void {
    $dbPath = $config['DB_PATH'];
    if (!is_string($dbPath) || !file_exists($dbPath)) return;
    $overlayKeys = cfg_settings_whitelist();
    $intKeys = ['SMTP_PORT', 'AUTOPULL_INTERVAL', 'LLM_MAX_TOKENS'];
    $csvKeys = ['LLM_OCR_MODELS']; // stored comma-separated, consumed as array
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $stmt = $pdo->query("SELECT key, value FROM settings");
        if ($stmt === false) return;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $k = (string) ($row['key'] ?? '');
            $v = (string) ($row['value'] ?? '');
            if ($v === '' || !in_array($k, $overlayKeys, true)) continue;
            if (in_array($k, $csvKeys, true)) {
                $config[$k] = array_values(array_filter(array_map('trim', explode(',', $v))));
            } elseif (in_array($k, $intKeys, true)) {
                $config[$k] = (int) $v;
            } else {
                $config[$k] = $v;
            }
        }
    } catch (Throwable $e) {
        // DB unavailable / table missing → keep env + hardcoded values.
    }
})($config);

/**
 * Merge the LIVE provider catalogue in. Rows were normalized on write, so no
 * network and no format guessing here: a hardcoded row with the same slug just
 * gets flagged as available, an unknown model is appended to the catalogue.
 */
(static function (array &$config): void {
    $live = ModelCatalog::decode((string) ($config['MODEL_CATALOG_MODELS'] ?? ''));
    if ($live) $config['AVAILABLE_MODELS'] = ModelCatalog::merge($config['AVAILABLE_MODELS'], $live);
})($config);

return $config;
