<?php
/**
 * php tests/llm_json.php — what the model's answer is put through, and where a
 * Yandex model is asked. No network: parseJson() is pure, the request builder
 * is read directly, and the cross-address decision is a function of one error.
 * spec: spec/llm.md §5.1, §5.2 and the response helpers of §5.
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

/** Private member of LLM, for the pieces that have no reason to be public. */
function llm_call(string $method, array $args) {
    $m = new ReflectionMethod('LLM', $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
}
function llm_reset(string $prop, $value): void {
    $p = new ReflectionProperty('LLM', $prop);
    $p->setAccessible(true);
    $p->setValue(null, $value);
}

$cfg = [
    'AVAILABLE_MODELS'      => [['id' => 'gemma', 'provider' => 'yandex', 'full_id' => 'gemma-3-27b-it']],
    'LLM_PROVIDER'          => 'yandex',
    'LLM_PROVIDER_PRIORITY' => 'yandex,openrouter',
    'LLM_DEFAULT_MODEL'     => 'gemma',
    'OPENROUTER_URL'        => 'https://openrouter.ai/api/v1/chat/completions',
    'OPENROUTER_API_KEY'    => 'sk-test',
    'YANDEX_API_KEY'        => 'ya-test',
    'YANDEX_FOLDER_ID'      => 'b1test',
    'YANDEX_LLM_URL'        => 'https://llm.api.cloud.yandex.net/v1/chat/completions',
    'YANDEX_LLM_URL_FM'     => 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion',
    'LLM_MAX_TOKENS'        => 0,
    'LLM_TIMEOUT_SEC'       => 120,
];
LLM::init($cfg);

$row = ['id' => 'gemma', 'provider' => 'yandex', 'full_id' => 'gemma-3-27b-it'];
$text = [['role' => 'system', 'content' => 'сис'], ['role' => 'user', 'content' => 'польз']];
$vision = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'что это'],
                                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,xx']]]]];

echo "\n1. Ответ модели разбирается, даже если модель сломала свой JSON\n";

check('ограда ```json снимается', LLM::parseJson('```json{"a":1}```'), ['a' => 1]);
check('фраза вокруг объекта не мешает', LLM::parseJson('вот результат: {"inn":"7701234567"} — всё'), ['inn' => '7701234567']);
check('рассуждение <think> выбрасывается', LLM::parseJson('<think>ну</think>{"x":[1,2]}'), ['x' => [1, 2]]);
check('хвостовая запятая прощается', LLM::parseJson('{"a":1,}'), ['a' => 1]);
check('живой перевод строки внутри строки экранируется',
      LLM::parseJson("{\"comment\":\"первая\nвторая\"}"), ['comment' => "первая\nвторая"]);
check('табуляция внутри строки не мешает', LLM::parseJson("{\"a\":\"один\tдва\"}"), ['a' => "один\tдва"]);
check('битый байт не превращает ответ в «не JSON»', LLM::parseJson("{\"a\":\"\xC3(\"}"), ['a' => '(']);
check('оборванный на полуслове ответ чинится',
      LLM::parseJson('{"items":[{"name":"шлем","qty":2},{"name":"броне'),
      ['items' => [['name' => 'шлем', 'qty' => 2]]]);
check('незакрытая скобка дописывается', LLM::parseJson('{"a":1'), ['a' => 1]);
check('не-JSON так и остаётся не-JSON', LLM::parseJson('совсем не json'), null);

echo "\n2. Повторный вопрос знает, чем кончился предыдущий ответ\n";

check('обрыва по длине по умолчанию нет', LLM::lastTruncated(), false);
llm_reset('lastFinish', 'length');
check('finish_reason: length — это обрыв', LLM::lastTruncated(), true);
$turn = llm_call('jsonRetryTurn', ['Syntax error']);
check('в повторный вопрос уходит причина', strpos($turn, 'Syntax error') !== false, true);
check('и требование писать короче, раз ответ обрубило',
      strpos($turn, 'уместиться целиком') !== false, true);
llm_reset('lastFinish', 'stop');
$turn2 = llm_call('jsonRetryTurn', ['это не объект JSON']);
check('без обрыва про длину не говорим', strpos($turn2, 'уместиться целиком') !== false, false);

echo "\n3. Yandex: у модели спрашивают, по какому адресу она отвечает\n";

check('непроверенный слаг идёт по настроенному адресу', LLM::yandexRoute('gemma-3-27b-it'), 'openai');
check('провайдер сам называет адрес',
      LLM::yandexRouteHint('YANDEX HTTP 400 @ … : {"message":"Model is not available via gRPC API. Please use HTTP OpenAI API instead."}'),
      'openai');
check('обычный отказ адреса не называет', LLM::yandexRouteHint('YANDEX HTTP 400: Failed to get model'), null);
// В тексте ошибки всегда есть адрес, по которому шёл запрос: подсказкой он не считается
check('адрес в тексте ошибки подсказкой не считается',
      LLM::yandexRouteHint('YANDEX HTTP 400 @ https://llm.api.cloud.yandex.net/foundationModels/v1/completion '
                         . 'model=gpt://b1test/gemma-3-27b-it/latest: Failed to get model'), null);

$req = llm_call('requestFor', [$row, $text, 0.3, true, [], 'openai']);
check('адрес OpenAI-совместимый', $req['url'], $cfg['YANDEX_LLM_URL']);
check('модель уходит как gpt:// URI', $req['body']['model'], 'gpt://b1test/gemma-3-27b-it/latest');
check('json-режим просит объект', $req['body']['response_format'], ['type' => 'json_object']);
check('предел длины не задан — max_tokens не отправляется', isset($req['body']['max_tokens']), false);

$fm = llm_call('requestFor', [$row, $text, 0.3, true, [], 'fm']);
check('второй адрес — Foundation Models', $fm['url'], $cfg['YANDEX_LLM_URL_FM']);
check('там модель живёт в modelUri', $fm['body']['modelUri'], 'gpt://b1test/gemma-3-27b-it/latest');
check('и реплика несёт text, а не content', $fm['body']['messages'][1], ['role' => 'user', 'text' => 'польз']);
check('json-режим — в completionOptions',
      $fm['body']['completionOptions']['responseFormat'], ['type' => 'json_object']);

$cfgMax = $cfg; $cfgMax['LLM_MAX_TOKENS'] = 8000;
LLM::init($cfgMax);
$req2 = llm_call('requestFor', [$row, $text, 0.3, false, [], 'openai']);
$fm2 = llm_call('requestFor', [$row, $text, 0.3, false, [], 'fm']);
check('заданный предел уходит в max_tokens', $req2['body']['max_tokens'], 8000);
check('и в maxTokens на втором адресе', $fm2['body']['completionOptions']['maxTokens'], 8000);
LLM::init($cfg);

echo "\n4. Второй адрес спрашивают только тогда, когда это может помочь\n";

$grpc = new LLMHttpError('YANDEX HTTP 400 @ url model=gpt://b1test/gemma-3-27b-it/latest: '
    . '{"error":{"httpCode":400,"message":"Model is not available via gRPC API. Please use HTTP OpenAI API instead."}}',
    'yandex', 400, '');
$dead = new LLMHttpError('YANDEX HTTP 400 @ url model=gpt://b1test/gemma-3-27b-it/latest: Failed to get model',
    'yandex', 400, '');
$limit = new LLMHttpError('YANDEX HTTP 429 @ url: too many requests', 'yandex', 429, '');

llm_reset('yandexCrossTried', false);
llm_reset('yandexRouteWorked', []);
check('провайдер назвал адрес — переспрашиваем',
      llm_call('mayCrossTry', [$grpc, $text, [], 'fm', 'openai']), true);
check('первый непонятный отказ — одна попытка вслепую',
      llm_call('mayCrossTry', [$dead, $text, [], 'openai', 'fm']), true);
check('фото по второму адресу не отправляем',
      llm_call('mayCrossTry', [$dead, $vision, [], 'openai', 'fm']), false);
check('лимит запросов адреса не касается',
      llm_call('mayCrossTry', [$limit, $text, [], 'openai', 'fm']), false);

llm_reset('yandexRouteWorked', ['openai' => true, 'fm' => true]);
check('адрес уже отвечал — значит, дело в модели, а не в адресе',
      llm_call('mayCrossTry', [$dead, $text, [], 'openai', 'fm']), false);
// «Use HTTP OpenAI API instead» приходит со второго адреса и называет первый:
// это сильнее правила «адрес уже отвечал» — про ЭТУ модель сказано прямо
check('но названный провайдером адрес всё равно пробуем',
      llm_call('mayCrossTry', [$grpc, $text, [], 'fm', 'openai']), true);

llm_reset('yandexRouteWorked', []);
llm_reset('yandexCrossTried', true);
check('вслепую — не больше одного раза за запрос',
      llm_call('mayCrossTry', [$dead, $text, [], 'openai', 'fm']), false);

echo "\n5. Выясненный адрес виден в трассировке\n";

llm_reset('yandexRoutes', []);
check('обычная модель подписана как раньше', llm_call('tagFor', [$row]), 'yandex:gemma-3-27b-it');
llm_call('noteYandexRoute', ['gemma-3-27b-it', 'fm']);
check('модель, ответившая по второму адресу, подписана им',
      llm_call('tagFor', [$row]), 'yandex:gemma-3-27b-it@fm');
check('и запрос к ней теперь сразу идёт туда', LLM::yandexRoute('gemma-3-27b-it'), 'fm');
check('в причине отказа адрес назван словами',
      LLM::failureReason([['candidate' => 'yandex:gemma-3-27b-it',
                           'result' => 'YANDEX HTTP 400: Model is not available via gRPC API. Please use HTTP OpenAI API instead.']]),
      'yandex: модель отвечает только по OpenAI-совместимому адресу');

echo "\n" . ($failures ? "ПРОВАЛЕНО: $failures\n" : "Всё сошлось\n");
exit($failures ? 1 : 0);
