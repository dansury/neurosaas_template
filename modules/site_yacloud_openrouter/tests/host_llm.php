<?php
declare(strict_types=1);
// A host's legacy instance API must survive loading the canonical module.
final class LLM { public function hostMarker(): string { return 'host'; } }
require_once dirname(__DIR__) . '/llm.php';
require_once dirname(__DIR__) . '/parser.php';
$host = new LLM();
if ($host->hostMarker() !== 'host' || !method_exists(ToolkitLLM::class, 'ocrPdf')) {
    throw new RuntimeException('Host LLM collision');
}
$cfg = require dirname(__DIR__) . '/config.php';
ToolkitLLM::init($cfg);
if (ToolkitLLM::cfg()['DB_PATH'] !== $cfg['DB_PATH']) {
    throw new RuntimeException('Canonical configuration was not initialized');
}
echo "Host coexistence passed\n";
