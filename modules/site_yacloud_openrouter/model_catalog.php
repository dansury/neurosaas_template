<?php
/**
 * ModelCatalog — LIVE model catalogue: the model list is pulled from the
 * providers themselves and cached in the `settings` table.
 * spec: spec/model_catalog.md — infrastructural code, no customer TZ behind it.
 *
 * Why: `config.php → AVAILABLE_MODELS` is a hardcoded list that ages with the
 * release. Providers publish their own catalogue over HTTP: OpenRouter via
 * GET /api/v1/models, Yandex via the Models API GET /foundationModels/v1/models
 * (falling back to the OpenAI-compatible GET /v1/models). This
 * class fetches them, normalizes the answer into CATALOGUE ROWS (same keys as
 * AVAILABLE_MODELS) and stores the JSON in `settings`.
 *
 * Wiring:
 *   1. `setup.php` calls maybeRefresh() on load — a cache older than
 *      MODEL_CATALOG_TTL_MIN minutes is refetched, otherwise nothing happens.
 *      Network failure never breaks the page: the previous cache stays and the
 *      reason is shown to the operator. The refresh button forces a fetch.
 *   2. `config.php` merges the cache into AVAILABLE_MODELS on every request —
 *      no network there: normalization already happened on write.
 *   3. A hardcoded row stays the source of its id, group and RUB price; a live
 *      row either flags it as really available (`live`) or is appended as a new
 *      model. That way a saved LLM_DEFAULT_MODEL never breaks.
 *
 * Model VERSION parsing lives here too (lineage/newerSiblings): it backs the
 * default fallback "a newer version of the same model" (LLM_FALLBACK_MODE=auto,
 * see LLM::dispatch()).
 *
 * PHP 7.4-compatible (no str_contains / str_starts_with).
 */

final class ModelCatalog {
    /** `settings` keys: normalized cache, refresh stamp, last failure reason. */
    const KEY_MODELS = 'MODEL_CATALOG_MODELS';
    const KEY_SYNCED = 'MODEL_CATALOG_SYNCED_AT';
    const KEY_ERROR  = 'MODEL_CATALOG_ERROR';

    /** Minutes a cache counts as fresh (setting MODEL_CATALOG_TTL_MIN). */
    const TTL_MIN = 15;
    /** Cache size ceiling — the OpenRouter catalogue keeps growing. */
    const MAX_MODELS = 500;

    /* ─────────────────────────── reading the cache ─────────────────────────── */

    /** Stored JSON → catalogue rows. Used by config.php as well. */
    public static function decode(string $json): array {
        if (trim($json) === '') return [];
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && !empty($r['id']) && !empty($r['full_id']) && !empty($r['provider'])) $out[] = $r;
        }
        return $out;
    }

    /**
     * Hardcoded catalogue + live one. A live row with a known slug only flags
     * the hardcoded row as available (`live`); an unknown one is appended.
     * Hardcoded id / group / price are never rewritten — settings point at them.
     */
    public static function merge(array $builtin, array $live): array {
        $bySlug = [];
        foreach ($builtin as $i => $r) {
            $bySlug[self::slugKey((string) ($r['provider'] ?? ''), (string) ($r['full_id'] ?? ''))] = $i;
        }
        $ids = [];
        foreach ($builtin as $r) $ids[(string) ($r['id'] ?? '')] = true;
        foreach ($live as $r) {
            $key = self::slugKey((string) $r['provider'], (string) $r['full_id']);
            if (isset($bySlug[$key])) {
                $builtin[$bySlug[$key]]['live'] = true;
                // The curated row keeps its own vision flag; a row without one
                // inherits what the provider says.
                if (!isset($builtin[$bySlug[$key]]['vision']) && isset($r['vision'])) {
                    $builtin[$bySlug[$key]]['vision'] = (bool) $r['vision'];
                }
                continue;
            }
            if (isset($ids[(string) $r['id']])) continue;   // short-id collision
            $ids[(string) $r['id']] = true;
            $bySlug[$key] = count($builtin);
            $builtin[] = $r;
        }
        return $builtin;
    }

    /* ─────────────────────────── refreshing ─────────────────────────── */

    /**
     * Refresh when the cache is empty or stale. Returns the refresh() report,
     * or null when there was nothing to do. Network errors are NOT thrown: the
     * reason goes to `settings` (KEY_ERROR) and is rendered in setup.php.
     */
    public static function maybeRefresh(array $cfg, SettingsStore $store, bool $force = false): ?array {
        if (!$force && !self::isStale($cfg)) return null;
        try {
            return self::refresh($cfg, $store);
        } catch (Throwable $e) {
            $store->setSetting(self::KEY_ERROR, $e->getMessage());
            // Move the stamp anyway, so a failure does not fire a request on
            // every reload — the next attempt happens after the TTL.
            $store->setSetting(self::KEY_SYNCED, gmdate('Y-m-d\TH:i:s\Z'));
            return null;
        }
    }

    /** Cache empty or older than the TTL? */
    public static function isStale(array $cfg): bool {
        if (!self::decode((string) ($cfg[self::KEY_MODELS] ?? ''))) return true;
        $at = strtotime((string) ($cfg[self::KEY_SYNCED] ?? '')) ?: 0;
        return (time() - $at) >= self::ttlSec($cfg);
    }

    public static function ttlSec(array $cfg): int {
        $min = (int) ($cfg['MODEL_CATALOG_TTL_MIN'] ?? self::TTL_MIN);
        return max(60, $min * 60);
    }

    /**
     * Fetch from the providers and store the normalized catalogue. Returns
     * ['rows'=>N, 'openrouter'=>N|error text, 'yandex'=>…]. Throws only when NO
     * provider answered — the previous cache is then left untouched.
     */
    public static function refresh(array $cfg, SettingsStore $store): array {
        $rows = [];
        $report = ['openrouter' => null, 'yandex' => null];
        foreach (['openrouter' => 'fetchOpenRouter', 'yandex' => 'fetchYandex'] as $prov => $fn) {
            try {
                $got = self::$fn($cfg);
                $report[$prov] = count($got);
                $rows = array_merge($rows, $got);
            } catch (Throwable $e) {
                $report[$prov] = $e->getMessage();
            }
        }
        if (!$rows) {
            throw new RuntimeException(
                'OpenRouter: ' . self::asText($report['openrouter']) . '; Yandex: ' . self::asText($report['yandex'])
            );
        }
        if (count($rows) > self::MAX_MODELS) $rows = array_slice($rows, 0, self::MAX_MODELS);
        $store->setSetting(self::KEY_MODELS, (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
        $store->setSetting(self::KEY_SYNCED, gmdate('Y-m-d\TH:i:s\Z'));
        // A partial failure (one provider silent) is worth a note too.
        $partial = array_filter($report, static function ($v) { return is_string($v); });
        $store->setSetting(self::KEY_ERROR, $partial
            ? implode('; ', array_map(static function ($k, $v) { return $k . ': ' . $v; }, array_keys($partial), $partial))
            : '');
        $report['rows'] = count($rows);
        return $report;
    }

    /** Drop the live catalogue: only hardcoded models remain in the lists. */
    public static function forget(SettingsStore $store): void {
        $store->setSetting(self::KEY_MODELS, '');
        $store->setSetting(self::KEY_SYNCED, '');
        $store->setSetting(self::KEY_ERROR, '');
    }

    /* ───────────────────────── model versions ───────────────────────── */

    /**
     * Slug → lineage + version: "openai/gpt-4.1-mini" → ['gpt-*-mini', [4,1]].
     * The version is a token like 4.1 / v3 / 2411; the vendor prefix is left out
     * of the lineage so one model matches across providers. No version in the
     * slug → null.
     */
    public static function lineage(string $slug): ?array {
        $s = strtolower(trim($slug));
        $s = (string) preg_replace('~:.*$~', '', $s);          // ':free', ':nitro' are not versions
        $pos = strrpos($s, '/');
        $name = $pos === false ? $s : substr($s, $pos + 1);
        $tokens = preg_split('~[-_]~', $name) ?: [];
        $idx = null;
        foreach ($tokens as $i => $t) {                        // 1) first dotted token: 4.1, 2.5
            if (preg_match('~^v?\d+\.\d+(\.\d+)*$~', $t)) { $idx = $i; break; }
        }
        if ($idx === null) {
            foreach ($tokens as $i => $t) {                    // 2) first "v3"
                if (preg_match('~^v\d+$~', $t)) { $idx = $i; break; }
            }
        }
        if ($idx === null) {
            foreach ($tokens as $i => $t) {                    // 3) last bare number (dates like 2411 too)
                if (preg_match('~^\d+$~', $t)) $idx = $i;
            }
        }
        if ($idx === null) return null;
        $ver = array_map('intval', explode('.', ltrim($tokens[$idx], 'v')));
        $tokens[$idx] = '*';
        return ['key' => implode('-', $tokens), 'version' => $ver];
    }

    /** Version compare: 5.1 > 5 > 4.1. A missing component counts as -1. */
    public static function versionCmp(array $a, array $b): int {
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $x = $a[$i] ?? -1; $y = $b[$i] ?? -1;
            if ($x !== $y) return $x <=> $y;
        }
        return 0;
    }

    /**
     * Newer versions of the SAME model found in the catalogue, newest first —
     * the default fallback. Same lineage (gpt-*-mini), strictly greater version.
     * No match (irregular slug, nothing newer) → empty array and the chain just
     * carries on with the configured fallbacks.
     */
    public static function newerSiblings(array $row, array $models): array {
        $base = self::lineage((string) ($row['full_id'] ?? ''));
        if ($base === null) return [];
        $out = [];
        foreach ($models as $m) {
            if (!empty($m['ocr_only'])) continue;
            $slug = (string) ($m['full_id'] ?? '');
            if ($slug === (string) ($row['full_id'] ?? '')) continue;
            $l = self::lineage($slug);
            if ($l === null || $l['key'] !== $base['key']) continue;
            if (self::versionCmp($l['version'], $base['version']) <= 0) continue;
            $out[] = ['row' => $m, 'version' => $l['version']];
        }
        usort($out, static function ($a, $b) { return ModelCatalog::versionCmp($b['version'], $a['version']); });
        return array_map(static function ($e) { return $e['row']; }, $out);
    }

    /* ─────────────────────────── providers ─────────────────────────── */

    /** OpenRouter: GET /api/v1/models. The key is optional but sent when present. */
    private static function fetchOpenRouter(array $cfg): array {
        $url = (string) preg_replace('~/chat/completions$~', '/models', (string) ($cfg['OPENROUTER_URL'] ?? ''));
        if ($url === '') throw new RuntimeException('OPENROUTER_URL is empty');
        $headers = ['Accept: application/json'];
        if (!empty($cfg['OPENROUTER_API_KEY'])) $headers[] = 'Authorization: Bearer ' . $cfg['OPENROUTER_API_KEY'];
        $j = self::httpGetJson($url, $headers, $cfg);
        $rows = [];
        foreach ($j['data'] ?? [] as $m) {
            $slug = isset($m['id']) && is_string($m['id']) ? trim($m['id']) : '';
            if ($slug === '' || strpos($slug, '/') === false) continue;
            $pin  = self::usdPerMillion($m['pricing']['prompt'] ?? null);
            $pout = self::usdPerMillion($m['pricing']['completion'] ?? null);
            $row = [
                'id'            => 'or-' . self::shortId($slug),
                'label'         => self::niceLabel($m['name'] ?? '', $slug),
                'provider'      => 'openrouter',
                'full_id'       => $slug,
                'group'         => 'OpenRouter · ' . self::vendorTitle($slug) . ' (каталог)',
                // Pricing arrives in USD per token; the RUB estimates of the
                // hardcoded rows are not invented here.
                'price_in'      => 0.0,
                'price_out'     => 0.0,
                'price_usd_in'  => $pin,
                'price_usd_out' => $pout,
                'context'       => (int) ($m['context_length'] ?? 0),
                'vision'        => self::openRouterSeesImages($m),
                'live'          => true,
            ];
            if ($pin === 0.0 && $pout === 0.0) $row['free'] = true;
            $rows[] = $row;
        }
        if (!$rows) throw new RuntimeException('no models in the response');
        usort($rows, static function ($a, $b) {
            return [$a['group'], $a['full_id']] <=> [$b['group'], $b['full_id']];
        });
        return $rows;
    }

    /**
     * Yandex: the folder's own model list. Two sources, in this order:
     *
     *   1. Models API — `GET <YANDEX_MODELS_URL>?folderId=<folder>`. This is the
     *      list the docs point at ("точный список моделей, доступных именно в
     *      вашем каталоге"): open models are enabled per folder and region, so a
     *      slug from the published catalogue is a candidate, not a fact.
     *   2. The OpenAI-compatible `GET /v1/models` — used when the Models API is
     *      unavailable (older install, proxy, 404), so the refresh keeps working.
     *
     * Both answers are read by the same normalizer: `{models:[…]}`, `{data:[…]}`
     * and `{items:[…]}` wrappers, and entries that are either a bare slug string
     * or an object carrying `modelUri` / `uri` / `id` / `name`.
     */
    private static function fetchYandex(array $cfg): array
    {
        $key    = (string) ($cfg['YANDEX_API_KEY'] ?? '');
        $folder = (string) ($cfg['YANDEX_FOLDER_ID'] ?? '');
        if ($key === '' || $folder === '') throw new RuntimeException('no API key or folder id');
        $headers = ['Accept: application/json', 'Authorization: Api-Key ' . $key, 'x-folder-id: ' . $folder];

        $errors = [];
        foreach (self::yandexModelUrls($cfg, $folder) as $label => $url) {
            try {
                $rows = self::yandexRows(self::httpGetJson($url, $headers, $cfg), $folder);
                if ($rows) return $rows;
                $errors[] = $label . ': no models in the response';
            } catch (RuntimeException $e) {
                $errors[] = $label . ': ' . $e->getMessage();
            }
        }
        throw new RuntimeException(implode('; ', $errors ?: ['no Yandex model endpoint configured']));
    }

    /** The endpoints tried, in order: Models API first, OpenAI-compatible second. */
    private static function yandexModelUrls(array $cfg, string $folder): array
    {
        $urls = [];
        $models = trim((string) ($cfg['YANDEX_MODELS_URL'] ?? ''));
        if ($models !== '') {
            $urls['models API'] = $models . (strpos($models, '?') === false ? '?' : '&') . 'folderId=' . rawurlencode($folder);
        }
        $compat = (string) preg_replace('~/chat/completions$~', '/models', (string) ($cfg['YANDEX_LLM_URL'] ?? ''));
        if ($compat !== '' && $compat !== $models) $urls['/v1/models'] = $compat;
        return $urls;
    }

    /** One decoded answer → catalogue rows. Entries of another folder are skipped. */
    private static function yandexRows(array $j, string $folder): array
    {
        $list = [];
        foreach (['models', 'data', 'items'] as $k) {
            if (isset($j[$k]) && is_array($j[$k])) { $list = $j[$k]; break; }
        }
        $rows = [];
        foreach ($list as $m) {
            $raw = '';
            if (is_string($m)) {
                $raw = $m;
            } elseif (is_array($m)) {
                foreach (['modelUri', 'uri', 'id', 'name'] as $k) {
                    if (isset($m[$k]) && is_string($m[$k])) { $raw = $m[$k]; break; }
                }
            }
            $slug = self::yandexSlug($raw, $folder);
            if ($slug === null) continue;
            $vision = self::yandexSeesImages($slug) || (is_array($m) && self::yandexReportsImages($m));
            $row = [
                'id'       => 'ya-' . self::shortId($slug),
                'label'    => $slug . ($vision ? ' (зрение)' : ''),
                'provider' => 'yandex',
                'full_id'  => $slug,
                'group'    => 'Yandex AI Studio (каталог)' . ($vision ? ' · зрение' : ''),
                'price_in' => 0.0, 'price_out' => 0.0,
                'vision'   => $vision,
                'live'     => true,
            ];
            $context = is_array($m) ? self::yandexContext($m) : 0;
            if ($context > 0) $row['context'] = $context;
            $rows[] = $row;
        }
        return $rows;
    }

    /** Context window, when the Models API states one (field name varies by version). */
    private static function yandexContext(array $m): int
    {
        foreach (['contextLength', 'context_length', 'maxTokens', 'maxInputTextTokens', 'context'] as $k) {
            if (isset($m[$k]) && is_numeric($m[$k])) return (int) $m[$k];
        }
        return 0;
    }

    /** Does the model's own entry say it takes images? Only a stated modality counts. */
    private static function yandexReportsImages(array $m): bool
    {
        foreach (['modalities', 'inputModalities', 'input_modalities'] as $k) {
            if (!isset($m[$k])) continue;
            $val = $m[$k];
            if (is_array($val)) {
                foreach ($val as $mod) {
                    if (is_string($mod) && strpos(strtolower($mod), 'image') !== false) return true;
                }
            } elseif (is_string($val)) {
                $in = explode('->', strtolower($val))[0];
                if (strpos($in, 'image') !== false) return true;
            }
        }
        return false;
    }

    /**
     * Does an OpenRouter row accept images? The catalogue states it outright —
     * `architecture.input_modalities: ["text","image"]` on current payloads,
     * `architecture.modality: "text+image->text"` on older ones.
     */
    private static function openRouterSeesImages(array $m): bool {
        $arch = isset($m['architecture']) && is_array($m['architecture']) ? $m['architecture'] : [];
        $inputs = $arch['input_modalities'] ?? null;
        if (is_array($inputs)) {
            foreach ($inputs as $mod) {
                if (is_string($mod) && strtolower($mod) === 'image') return true;
            }
            return false;
        }
        $modality = isset($arch['modality']) && is_string($arch['modality']) ? strtolower($arch['modality']) : '';
        if ($modality !== '') {
            $in = explode('->', $modality)[0];
            return strpos($in, 'image') !== false;
        }
        return false;
    }

    /**
     * Yandex usually answers with slugs and no modality field, so the multimodal
     * families are recognized by name: the VL / vision lines and Gemma 3 (its
     * 4b/12b/27b instruct weights take images). A modality the answer DOES state
     * is honoured on top of this (yandexReportsImages()).
     */
    private static function yandexSeesImages(string $slug): bool {
        $s = strtolower($slug);
        $marks = ['-vl-', '-vl2', 'vl2-', '-vision', 'vision-', 'llava', 'pixtral'];
        foreach ($marks as $mark) {
            if (strpos($s, $mark) !== false) return true;
        }
        if (preg_match('~^gemma-3-(4b|12b|27b)~', $s)) return true;
        if (preg_match('~^qwen[0-9.]*-vl~', $s)) return true;
        return false;
    }

    /** "gpt://<folder>/yandexgpt/latest" → "yandexgpt". Another folder → not ours. */
    private static function yandexSlug(string $raw, string $folder): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (strpos($raw, 'gpt://') === 0 || strpos($raw, 'emb://') === 0) {
            $parts = explode('/', substr($raw, 6));
            if (count($parts) < 2) return null;
            if ($parts[0] !== $folder && $parts[0] !== '') return null;
            $slug = $parts[1];
        } else {
            $slug = $raw;
        }
        $slug = (string) preg_replace('~/(latest|rc|deprecated)$~', '', $slug);
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*$~', $slug) ? $slug : null;
    }

    /* ──────────────────────────── helpers ──────────────────────────── */

    private static function httpGetJson(string $url, array $headers, array $cfg): array {
        if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('bad url: ' . $url);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => max(10, min(30, (int) ($cfg['LLM_TIMEOUT_SEC'] ?? 30))),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'site_yacloud_openrouter (+model catalog)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('network: ' . ($err ?: 'request failed'));
        if ($code >= 400)    throw new RuntimeException('HTTP ' . $code . ': ' . mb_substr((string) $body, 0, 160));
        $j = json_decode((string) $body, true);
        if (!is_array($j))   throw new RuntimeException('response is not JSON');
        return $j;
    }

    /** OpenRouter prices arrive in USD per TOKEN; shown per 1M. */
    private static function usdPerMillion($raw): float {
        if (!is_string($raw) && !is_float($raw) && !is_int($raw)) return 0.0;
        $v = (float) $raw;
        return $v > 0 ? round($v * 1000000, 4) : 0.0;
    }

    private static function slugKey(string $provider, string $slug): string {
        return $provider . '|' . $slug;
    }

    /** Short id for the UI and settings: "openai-gpt-5-1". */
    private static function shortId(string $slug): string {
        $id = strtolower((string) preg_replace('~[^A-Za-z0-9]+~', '-', $slug));
        return trim($id, '-');
    }

    private static function niceLabel($name, string $slug): string {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') $name = $slug;
        return mb_substr((string) preg_replace('~\s+~u', ' ', $name), 0, 70);
    }

    /** "openai/gpt-5" → "OpenAI": the <optgroup> heading. */
    private static function vendorTitle(string $slug): string {
        $pos = strpos($slug, '/');
        $vendor = $pos === false ? '' : strtolower(substr($slug, 0, $pos));
        $known = [
            'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google',
            'meta-llama' => 'Meta', 'deepseek' => 'DeepSeek', 'qwen' => 'Qwen',
            'mistralai' => 'Mistral', 'x-ai' => 'xAI', 'z-ai' => 'Z.ai',
            'moonshotai' => 'Moonshot', 'nvidia' => 'NVIDIA', 'microsoft' => 'Microsoft',
            'cohere' => 'Cohere', 'amazon' => 'Amazon', 'perplexity' => 'Perplexity',
            'openrouter' => 'auto',
        ];
        return $known[$vendor] ?? ($vendor !== '' ? ucfirst($vendor) : 'other');
    }

    private static function asText($v): string {
        return is_string($v) ? $v : (is_int($v) ? $v . ' models' : 'no data');
    }
}
