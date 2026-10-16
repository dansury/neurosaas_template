<?php
/**
 * php tests/llm_chain.php — who the candidate chain asks, who it drops, and
 * what the person waiting on the answer is told. No network: candidateChain()
 * is pure over the config, and the short reason is derived from the trace.
 * The configuration is taken from a real log where one photo burned ten
 * requests to models the cloud folder does not serve.
 * spec: spec/llm.md §4.
 */

declare(strict_types=1);

require __DIR__ . '/../llm.php';

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

/** Candidate tags in walk order. */
function chain_tags(bool $vision): array {
    $out = [];
    foreach (LLM::candidateChain($vision)['candidates'] as $row) {
        $out[] = $row['provider'] . ':' . $row['full_id'];
    }
    return $out;
}

/** Catalogue as served: Yandex answered GET /models (rows marked live),
 *  OpenRouter's catalogue never arrived (403) — its rows are not judged. */
$models = [
    // hardcoded Yandex rows the live catalogue does not list
    ['id' => 'gemma-3-4b-it', 'provider' => 'yandex', 'full_id' => 'gemma-3-4b-it', 'vision' => true],
    ['id' => 'deepseek-vl2', 'provider' => 'yandex', 'full_id' => 'deepseek-vl2', 'vision' => true],
    ['id' => 'deepseek-vl2-tiny', 'provider' => 'yandex', 'full_id' => 'deepseek-vl2-tiny', 'vision' => true],
    ['id' => 'deepseek-r1', 'provider' => 'yandex', 'full_id' => 'deepseek-r1', 'vision' => false],
    // live Yandex rows
    ['id' => 'ya-qwen3-vl-30b', 'provider' => 'yandex', 'full_id' => 'qwen3-vl-30b-a3b-instruct', 'vision' => true, 'live' => true],
    ['id' => 'ya-yandexgpt-5-lite', 'provider' => 'yandex', 'full_id' => 'yandexgpt-5-lite', 'vision' => false, 'live' => true],
    ['id' => 'ya-gpt-oss-120b', 'provider' => 'yandex', 'full_id' => 'gpt-oss-120b', 'vision' => false, 'live' => true],
    ['id' => 'ya-ocr', 'provider' => 'yandex', 'full_id' => 'yandex-ocr-page', 'ocr_only' => true, 'live' => true],
    // OpenRouter — catalogue not received, hardcoded rows only
    ['id' => 'gpt-4o', 'provider' => 'openrouter', 'full_id' => 'openai/gpt-4o', 'vision' => true],
    ['id' => 'gemini-2.0-flash', 'provider' => 'openrouter', 'full_id' => 'google/gemini-2.0-flash-001', 'vision' => true],
];

$cfg = [
    'AVAILABLE_MODELS'      => $models,
    'LLM_PROVIDER'          => 'yandex',
    'LLM_PROVIDER_PRIORITY' => 'yandex,openrouter',
    'LLM_DEFAULT_MODEL'     => 'ya-gpt-oss-120b',
    'LLM_VISION_MODEL'      => 'yandex:gemma-3-4b-it',
    'LLM_FALLBACK_MODE'     => 'manual',
    'LLM_FALLBACK_MODELS'   => 'deepseek-vl2-tiny,deepseek-vl2',
    'LLM_FALLBACK_MODEL'    => 'openrouter/auto',
    'YANDEX_FALLBACK_MODEL' => 'yandexgpt-5-lite',
    'OPENROUTER_API_KEY'    => 'sk-test',
    'YANDEX_API_KEY'        => 'ya-test',
    'YANDEX_FOLDER_ID'      => 'b1test',
];
LLM::init($cfg);

// ── photo ────────────────────────────────────────────────────────────────
$vision = LLM::candidateChain(true);
check('the chosen vision model is asked first, catalogue or no catalogue',
    $vision['candidates'][0]['full_id'], 'gemma-3-4b-it');
check('dead hardcoded Yandex rows never enter the chain',
    chain_tags(true),
    ['yandex:gemma-3-4b-it', 'yandex:qwen3-vl-30b-a3b-instruct',
     'openrouter:openai/gpt-4o', 'openrouter:google/gemini-2.0-flash-001', 'openrouter:openrouter/auto']);

$skipped = [];
foreach ($vision['skipped'] as $s) $skipped[$s['candidate']] = $s['result'];
check('every skip names its reason',
    $skipped['yandex:deepseek-vl2-tiny'] ?? null, 'пропущен: провайдер не назвал эту модель в своём каталоге');
check('the text-only per-provider fallback is not asked on a photo',
    $skipped['yandex:yandexgpt-5-lite'] ?? null, 'пропущен: модель не принимает изображения');
check('an ocr_only row is not a chat model and stays out',
    in_array('yandex:yandex-ocr-page', chain_tags(true), true), false);

// ── text ─────────────────────────────────────────────────────────────────
check('text asks the default model and the live backups',
    chain_tags(false), ['yandex:gpt-oss-120b', 'yandex:yandexgpt-5-lite', 'openrouter:openrouter/auto']);

// ── a silent catalogue proves nothing ────────────────────────────────────
$blind = $cfg;
$blind['AVAILABLE_MODELS'] = array_map(static function (array $r): array {
    unset($r['live']);
    return $r;
}, $models);
LLM::init($blind);
check('no catalogue — everything configured is tried',
    chain_tags(true),
    ['yandex:gemma-3-4b-it', 'yandex:deepseek-vl2-tiny', 'yandex:deepseek-vl2',
     'yandex:qwen3-vl-30b-a3b-instruct', 'openrouter:openai/gpt-4o',
     'openrouter:google/gemini-2.0-flash-001', 'openrouter:openrouter/auto']);

// ── the provider refused as a whole, not one model ───────────────────────
$waf = new LLMHttpError('OPENROUTER HTTP 403 …', 'openrouter', 403,
    '{ "success": false, "error": "Access denied by security policy." }');
check('a 403 that is not the provider\'s own drops the whole provider leg',
    $waf->providerWide(), 'запрос к провайдеру заблокирован на подступах (хостинг или прокси)');
$forbidden = new LLMHttpError('YANDEX HTTP 403 …', 'yandex', 403,
    '{"error":{"message":"Forbidden","type":"forbidden"}}');
check('a 403 in the provider\'s envelope is about one model, the chain goes on',
    $forbidden->providerWide(), null);
$badKey = new LLMHttpError('OPENROUTER HTTP 401 …', 'openrouter', 401, '{"error":{"message":"No auth"}}');
check('401 is the key, not the model', $badKey->providerWide(), 'ключ провайдера отклонён');
$deadModel = new LLMHttpError('YANDEX HTTP 400 …', 'yandex', 400,
    '{"error":{"message":"Failed to get model","type":"server_error"}}');
check('400 about a model does not close the leg', $deadModel->providerWide(), null);

// ── what of this the person is shown ─────────────────────────────────────
$trace = [
    ['candidate' => 'yandex:deepseek-vl2', 'result' => 'пропущен: провайдер не назвал эту модель в своём каталоге'],
    ['candidate' => 'yandex:gemma-3-4b-it', 'result' => 'YANDEX HTTP 400 @ … {"error":{"message":"Failed to get model"}}'],
    ['candidate' => 'yandex:qwen3-vl-30b-a3b-instruct', 'result' => 'YANDEX HTTP 400 @ … {"error":{"message":"Failed to get model"}}'],
    ['candidate' => 'openrouter:openai/gpt-4o', 'result' => 'OPENROUTER HTTP 403 @ … { "success": false, "error": "Access denied by security policy." }'],
];
check('one cause per provider, not ten lines',
    LLM::failureReason($trace),
    'yandex: модель не включена в каталоге облака; openrouter: запрос блокирует хостинг');
check('a skip is not a reason the request failed',
    LLM::failureReason([$trace[0]]), '');
check('a successful call yields no reason',
    LLM::failureReason([['candidate' => 'yandex:gpt-oss-120b', 'result' => 'ok (412 симв.)']]), '');
check('a text model handed a photo is named in its own words',
    LLM::failureReason([['candidate' => 'yandex:yandexgpt-5-lite',
        'result' => 'YANDEX HTTP 400 @ … {"error":{"message":"empty message text"}}']]),
    'yandex: модель не приняла изображение');

echo $failures ? "\n{$failures} checks failed\n" : "\nall checks passed\n";
exit($failures ? 1 : 0);
