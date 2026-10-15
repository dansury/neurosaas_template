<?php
declare(strict_types=1);
define('PULL_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/pull.php';
require dirname(__DIR__) . '/lib/llm.php';
$checks = 0;
function expect(bool $condition, string $message): void {
    global $checks; ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
// Each regional setting and an explicit override, without geolocation network calls.
foreach (['RU' => 'ru', 'BY' => 'ru', 'KZ' => 'ru', 'US' => 'en', '' => 'en'] as $country => $language) {
    $_SESSION = []; $_GET = []; $_SERVER['GEOIP_COUNTRY_CODE'] = $country;
    init_panel(); expect($GLOBALS['pull_language'] === $language, 'country ' . $country);
}
$_GET = ['lang' => 'en']; $_SERVER['GEOIP_COUNTRY_CODE'] = 'RU'; init_panel();
expect($GLOBALS['pull_language'] === 'en', 'manual override');
$_SESSION = []; $_GET = []; $_SERVER['GEOIP_COUNTRY_CODE'] = ''; $_SERVER['HTTP_CF_IPCOUNTRY'] = 'RU'; init_panel();
expect($GLOBALS['pull_language'] === 'en', 'untrusted country header ignored');
parse_str((string)parse_url(pat_url('owner/repo', true), PHP_URL_QUERY), $permissions);
expect($permissions['contents'] === 'read' && $permissions['issues'] === 'write' && $permissions['pull_requests'] === 'read', 'token permissions');
$hash = password_hash('example', PASSWORD_DEFAULT);
expect(auth_token_valid(auth_token($hash, time() + 60), $hash), 'valid signed auth');
expect(!auth_token_valid(auth_token($hash, time() - 60), $hash), 'expired auth');
expect(!auth_token_valid(auth_token($hash, time() + 60), password_hash('other', PASSWORD_DEFAULT)), 'different auth hash');

$cfg = LLM::defaults();
foreach (LLM::PROVIDERS as $provider) $cfg[$provider] = array_replace($cfg[$provider], ['enabled' => true, 'key' => 'FAKE_SECRET', 'model' => 'sample', 'folder' => 'FOLDER']);
$adapter = new LLM($cfg);
[$url, $headers, $body] = $adapter->request('yandex', [['role' => 'user', 'content' => 'hi']], true);
expect(strpos($url, '/foundationModels/v1/completion') !== false && $body['modelUri'] === 'gpt://FOLDER/sample/latest' && $body['jsonObject'] === true, 'native yandex payload');
expect($body['messages'][0]['text'] === 'hi', 'native messages');
$cfg['yandex']['route'] = 'openai'; $cfg['yandex']['model'] = 'gpt://FOLDER/qwen/latest';
$adapter = new LLM($cfg);
[$url, , $body] = $adapter->request('yandex', [], true);
expect(strpos($url, '/v1/chat/completions') !== false && $body['model'] === $cfg['yandex']['model'] && $body['response_format']['type'] === 'json_object', 'openai yandex route');
[$url] = $adapter->request('genrouter', [], false); expect($url === 'https://api.genrouter.ru/v1/chat/completions', 'GenRouter endpoint matches upstream');

$calls = [];
$cfg['yandex']['route'] = 'native';
$llm = new LLM($cfg, static function ($method, $url, $headers, $payload, $provider) use (&$calls) {
    $calls[] = $provider;
    if ($provider === 'yandex') return [503, 'secret server error'];
    return [200, json_encode(['choices' => [['message' => ['content' => '{"ok":true}']]]])];
});
expect($llm->chatJson('system', 'user') === ['ok' => true], 'JSON fallback result');
expect($calls === ['yandex', 'yandex', 'genrouter'], 'bounded retry then fallback');
$cfg['yandex']['enabled'] = false; $calls = [];
$llm = new LLM($cfg, static function ($method, $url, $headers, $payload, $provider) use (&$calls) {
    $calls[] = $provider;
    return [200, json_encode(['choices' => [['message' => ['content' => $provider === 'genrouter' ? '{"broken":' : '{"safe":true}']]]])];
});
expect($llm->chatJson('system', 'user') === ['safe' => true] && $calls === ['genrouter', 'openrouter'], 'invalid JSON falls back, disabled skipped');
$llm = new LLM($cfg, static fn() => [401, 'FAKE_SECRET']);
try { $llm->chatText('system', 'user'); throw new LogicException('Expected failure'); }
catch (RuntimeException $e) { expect(strpos($e->getMessage(), 'FAKE_SECRET') === false, 'errors hide credentials'); }
$llm = new LLM($cfg, static fn() => [200, '{"data":[{"id":"model-a"},{"id":"model-b"}]}']);
expect($llm->models('openrouter') === ['model-a', 'model-b'], 'live catalog');
try { $llm->test('yandex'); throw new LogicException('Disabled provider accepted'); }
catch (RuntimeException $e) { expect(true, 'disabled provider test blocked'); }

// Mock HTTPS stream only in this test process, exercise the actual feedback request.
class FakeGitHub {
    public $context;
    private int $offset = 0;
    public static string $payload = '';
    public static array $options = [];
    public static int $status = 201;
    public function stream_open($path, $mode, $options, &$opened): bool {
        self::$options = stream_context_get_options($this->context)['http']; self::$payload = self::$options['content'];
        $GLOBALS['http_response_header'] = ['HTTP/1.1 ' . self::$status]; return true;
    }
    public function stream_read($count): string { $body = '{"html_url":"https://github.com/owner/repo/issues/9"}'; $part = substr($body, $this->offset, $count); $this->offset += strlen($part); return $part; }
    public function stream_eof(): bool { return $this->offset >= strlen('{"html_url":"https://github.com/owner/repo/issues/9"}'); }
    public function stream_stat(): array { return []; }
}
if (!function_exists('curl_init')) {
    stream_wrapper_unregister('https'); stream_wrapper_register('https', FakeGitHub::class);
    [, $issue] = github_create_feedback('owner/repo', 'FAKE_GITHUB_KEY', 'Title', 'Detailed feedback');
    $payload = json_decode(FakeGitHub::$payload, true);
    expect(FakeGitHub::$options['method'] === 'POST' && $payload['title'] === 'Title', 'feedback POST payload');
    expect(strpos($payload['body'], 'FAKE_GITHUB_KEY') === false, 'no feedback credentials');
    expect(strpos(FakeGitHub::$options['header'], 'Authorization: Bearer FAKE_GITHUB_KEY') !== false, 'server-side feedback authorization');
    expect($issue['html_url'] === 'https://github.com/owner/repo/issues/9', 'feedback response');
    stream_wrapper_restore('https');
}
// Purge/copy must preserve local keys, state and deployment lock.
$dir = sys_get_temp_dir() . '/neurosaas-' . bin2hex(random_bytes(6));
mkdir($dir); mkdir($dir . '/src'); mkdir($dir . '/dst');
file_put_contents($dir . '/src/index.php', 'new');
foreach (ALWAYS_KEEP as $file) file_put_contents($dir . '/dst/' . $file, 'local');
file_put_contents($dir . '/dst/obsolete.txt', 'old');
$copied = $files = $dirs = 0;
copyTree($dir . '/src', $dir . '/dst', ALWAYS_KEEP, $copied);
purgeExtra($dir . '/src', $dir . '/dst', ALWAYS_KEEP, $files, $dirs);
expect(file_get_contents($dir . '/dst/index.php') === 'new' && !file_exists($dir . '/dst/obsolete.txt'), 'copy and purge');
foreach (ALWAYS_KEEP as $file) expect(file_get_contents($dir . '/dst/' . $file) === 'local', 'preserved ' . $file);
cleanup($dir);
echo "$checks checks passed\n";
