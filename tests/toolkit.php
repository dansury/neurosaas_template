<?php
declare(strict_types=1);
define('PULL_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/pull.php';
$checks = 0;
function toolkitExpect(bool $value, string $label): void {
    global $checks; $checks++; if (!$value) throw new RuntimeException($label);
}
$temp = sys_get_temp_dir() . '/neurosaas-toolkit-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
try {
    $copied = 0;
    copyTree(dirname(__DIR__) . '/admin/llm_connector', $temp . '/admin/llm_connector', [], $copied);
    file_put_contents($temp . '/ai-config.php', "<?php return " . var_export([
        'order' => ['yandex', 'genrouter', 'openrouter'],
        'yandex' => ['enabled' => true, 'key' => 'FAKE_YANDEX_SECRET', 'folder' => 'FAKE_FOLDER', 'model' => 'yandexgpt/latest'],
        'openrouter' => ['enabled' => true, 'key' => 'FAKE_OPENROUTER_SECRET', 'model' => 'test/model'],
    ], true) . ';');
    unlink($temp . '/admin/llm_connector/.htaccess');
    $_SERVER['HTTP_HOST'] = 'localhost:1234';
    $_SERVER['DOCUMENT_ROOT'] = $temp;
    require $temp . '/admin/llm_connector/toolkit.php';
    $cfg = \NeuroSaaS\Toolkit::boot();
    toolkitExpect($cfg['YANDEX_API_KEY'] === 'FAKE_YANDEX_SECRET', 'legacy key migration');
    toolkitExpect($cfg['OPENROUTER_API_KEY'] === 'FAKE_OPENROUTER_SECRET', 'second provider migration');
    toolkitExpect(method_exists(LLM::class, 'defaults') && method_exists(ToolkitLLM::class, 'ocrPdf'), 'both LLM APIs coexist');
    toolkitExpect(is_file($temp . '/ai-config.php'), 'legacy config retained');
    toolkitExpect(strpos(file_get_contents(\NeuroSaaS\Toolkit::module() . '/.htaccess'), 'Require all denied') !== false, 'fresh install denies direct module HTTP access');
    toolkitExpect(!is_file(\NeuroSaaS\Toolkit::module() . '/selfheal/token.php'), 'no distributed reporting credential');
    toolkitExpect(Selfheal\SelfHeal::reporting() === 'ask', 'reporting stays undecided/local');
    toolkitExpect($cfg['AUTOPULL_URL'] === 'http://localhost:1234/pull.php?check=1', 'page hook uses explicit deployment endpoint');
    $store = new SettingsStore($cfg['DB_PATH']);
    $store->setSetting('MODEL_CATALOG_MODELS', json_encode([[
        'id' => 'catalog-test', 'provider' => 'openrouter', 'full_id' => 'vendor/catalog-test',
        'name' => 'Fixture', 'group' => 'Fixture', 'vision' => true, 'live' => true
    ]]));
    $cached = require \NeuroSaaS\Toolkit::module() . '/config.php';
    toolkitExpect(in_array('catalog-test', array_column($cached['AVAILABLE_MODELS'], 'id'), true), 'cached models merge without network');
    DiagLog::info('fixture', 'Failure with FAKE_YANDEX_SECRET', ['key' => 'FAKE_OPENROUTER_SECRET']);
    $log = DiagLog::asText(DiagLog::tail());
    toolkitExpect(strpos($log, 'FAKE_YANDEX_SECRET') === false && strpos($log, 'FAKE_OPENROUTER_SECRET') === false, 'diagnostics mask migrated secrets');
    ToolkitLLM::setModelOverride('catalog-test');
    ToolkitLLM::setProviderOverride('openrouter');
    toolkitExpect(ToolkitLLM::effectiveProvider() === 'openrouter', 'per-request provider override');
    ToolkitLLM::setModelOverride(null); ToolkitLLM::setProviderOverride(null);
    foreach (['visionJson', 'ocrPdf', 'autoFallbackRows'] as $method) toolkitExpect(method_exists(ToolkitLLM::class, $method), 'canonical API ' . $method);
    $docx = $temp . '/sample.docx';
    $zip = new ZipArchive(); $zip->open($docx, ZipArchive::CREATE);
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Document fixture text</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $document = \NeuroSaaS\Toolkit::extract($docx, 'docx');
    toolkitExpect(strpos($document['raw'], 'Document fixture text') !== false && $document['source'] === 'docx', 'DOCX extraction');
    toolkitExpect(Parser::normalize("a\xC2\xA0b\xE2\x80\x8Bc") === 'a bc', 'document normalization');
    $mime = Mailer::buildMimeMessage('from@example.test', 'Fixture', 'to@example.test', 'Subject', 'test', 'Plain', '<p>HTML</p>', [
        'path' => $docx, 'filename' => 'fixture.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ]);
    toolkitExpect(strpos($mime, 'multipart/mixed') !== false && strpos($mime, 'text/plain') !== false && strpos($mime, 'text/html') !== false, 'SMTP MIME alternatives and attachment');
    toolkitExpect(strpos($mime, base64_encode(file_get_contents($docx))) !== false || strpos($mime, substr(base64_encode(file_get_contents($docx)), 0, 60)) !== false, 'attachment bytes encoded');
    $state = $temp . '/destination/admin/llm_connector/data';
    mkdir($state, 0700, true); file_put_contents($state . '/operator.db', 'KEEP');
    $source = $temp . '/incoming/admin/llm_connector/data';
    mkdir($source, 0700, true); file_put_contents($source . '/operator.db', 'OVERWRITE');
    mkdir($temp . '/incoming/admin/llm_connector/selfheal', 0700, true);
    mkdir($temp . '/destination/admin/llm_connector/selfheal', 0700, true);
    file_put_contents($temp . '/incoming/admin/llm_connector/llm.php', 'new code');
    file_put_contents($temp . '/destination/admin/llm_connector/selfheal/token.local.php', 'LOCAL');
    file_put_contents($temp . '/destination/obsolete.txt', 'old');
    $count = 0; copyTree($temp . '/incoming', $temp . '/destination', ALWAYS_KEEP, $count);
    $files = $dirs = 0; purgeExtra($temp . '/incoming', $temp . '/destination', ALWAYS_KEEP, $files, $dirs);
    toolkitExpect(file_get_contents($state . '/operator.db') === 'KEEP', 'nested DB survives overwrite and purge');
    toolkitExpect(file_get_contents($temp . '/destination/admin/llm_connector/selfheal/token.local.php') === 'LOCAL', 'local token survives purge');
    toolkitExpect(file_get_contents($temp . '/destination/admin/llm_connector/llm.php') === 'new code' && !file_exists($temp . '/destination/obsolete.txt'), 'code updates while obsolete ordinary files are removed');
    echo "$checks toolkit checks passed\n";
} finally { cleanup($temp); }

