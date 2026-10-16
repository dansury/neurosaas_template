<?php
/**
 * php tests/model_catalog_yandex.php — the Yandex half of the live catalogue:
 * which endpoint is asked (Models API first, OpenAI-compatible after it), and
 * what one answer turns into. No network: the private helpers are called over
 * a decoded answer.
 * spec: spec/model_catalog.md §4.
 */

declare(strict_types=1);

require __DIR__ . '/../model_catalog.php';

$failures = 0;
function check(string $what, $got, $want): void {
    global $failures;
    $ok = $got === $want;
    if (!$ok) $failures++;
    echo ($ok ? "ok   " : "FAIL ") . $what;
    if (!$ok) echo "\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
        . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE);
    echo "\n";
}

/** Private statics of ModelCatalog, called by name. */
function call_mc(string $method, array $args) {
    $m = new ReflectionMethod('ModelCatalog', $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
}

$cfg = [
    'YANDEX_MODELS_URL' => 'https://llm.api.cloud.yandex.net/foundationModels/v1/models',
    'YANDEX_LLM_URL'    => 'https://llm.api.cloud.yandex.net/v1/chat/completions',
];

// ── which endpoints are tried, and in what order ──────────────────────────
$urls = call_mc('yandexModelUrls', [$cfg, 'b1folder']);
check('the Models API is asked first, with the folder in the query',
    array_slice(array_values($urls), 0, 1),
    ['https://llm.api.cloud.yandex.net/foundationModels/v1/models?folderId=b1folder']);
check('the OpenAI-compatible list stays as the second try',
    array_values($urls)[1] ?? null,
    'https://llm.api.cloud.yandex.net/v1/models');
check('an existing query string keeps its separator',
    array_values(call_mc('yandexModelUrls', [['YANDEX_MODELS_URL' => 'https://x.test/models?v=1'], 'b1f']))[0],
    'https://x.test/models?v=1&folderId=b1f');
check('no Models API configured — only the compatible endpoint is left',
    array_values(call_mc('yandexModelUrls', [['YANDEX_LLM_URL' => $cfg['YANDEX_LLM_URL']], 'b1f'])),
    ['https://llm.api.cloud.yandex.net/v1/models']);

// ── one answer → catalogue rows ───────────────────────────────────────────
$answer = ['models' => [
    ['modelUri' => 'gpt://b1folder/yandexgpt-5-lite/latest', 'contextLength' => 32768],
    ['modelUri' => 'gpt://b1folder/gemma-3-27b-it/latest'],
    ['modelUri' => 'gpt://b1folder/deepseek-v4-flash/latest', 'maxTokens' => 1048576],
    ['modelUri' => 'gpt://b1other/yandexgpt-5-pro/latest'],          // another folder
    ['modelUri' => 'art://b1folder/yandex-art-2.0/latest'],          // not a text model
    ['id' => 'gpt-oss-120b', 'modalities' => ['text']],
    'qwen3.6-35b-a3b',
]];
$rows = call_mc('yandexRows', [$answer, 'b1folder']);

check('a foreign folder and a non-gpt:// uri are dropped',
    array_column($rows, 'full_id'),
    ['yandexgpt-5-lite', 'gemma-3-27b-it', 'deepseek-v4-flash', 'gpt-oss-120b', 'qwen3.6-35b-a3b']);
check('the context window is carried over when the answer states one',
    array_column($rows, 'context', 'full_id'),
    ['yandexgpt-5-lite' => 32768, 'deepseek-v4-flash' => 1048576]);
check('the name heuristic still marks the multimodal Gemma',
    array_column($rows, 'vision', 'full_id')['gemma-3-27b-it'], true);
check('a text-only model is not made to see',
    array_column($rows, 'vision', 'full_id')['gpt-oss-120b'], false);
check('a live row carries its provider, short id and live flag',
    array_intersect_key($rows[0], array_flip(['id', 'provider', 'live', 'group'])),
    ['id' => 'ya-yandexgpt-5-lite', 'provider' => 'yandex', 'group' => 'Yandex AI Studio (каталог)', 'live' => true]);

// A modality the answer states outweighs the name heuristic.
$stated = call_mc('yandexRows', [['models' => [
    ['modelUri' => 'gpt://b1f/qwen3.6-35b-a3b/latest', 'inputModalities' => ['text', 'image']],
]], 'b1f']);
check('a stated image modality is honoured', $stated[0]['vision'], true);

// ── the hardcoded rows are the ones AI Studio documents ───────────────────
$cfgAll = require __DIR__ . '/../config.php';
$yandex = array_values(array_filter((array) $cfgAll['AVAILABLE_MODELS'],
    static function (array $m): bool { return ($m['provider'] ?? '') === 'yandex' && empty($m['ocr_only']); }));
$slugs = array_column($yandex, 'full_id');
foreach (['yandexgpt-5.1', 'yandexgpt-5-pro', 'yandexgpt-5-lite', 'aliceai-llm', 'deepseek-v4-flash',
          'qwen3-235b-a22b-fp8', 'qwen3.6-35b-a3b', 'gpt-oss-120b', 'gpt-oss-20b'] as $slug) {
    check("the catalogue offers {$slug}", in_array($slug, $slugs, true), true);
}
foreach (['deepseek-r1', 'deepseek-v3', 'phi-4', 'deepseek-vl2', 'deepseek-vl2-tiny'] as $gone) {
    check("the withdrawn {$gone} is not offered any more", in_array($gone, $slugs, true), false);
}
check('the Yandex fallback is a model the catalogue still lists',
    in_array((string) $cfgAll['YANDEX_FALLBACK_MODEL'], $slugs, true), true);

echo "\n" . ($failures ? "{$failures} check(s) failed\n" : "all checks passed\n");
exit($failures ? 1 : 0);
