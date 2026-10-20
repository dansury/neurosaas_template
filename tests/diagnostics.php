<?php
declare(strict_types=1);
require dirname(__DIR__) . '/admin/llm_connector/diag_log.php';
require dirname(__DIR__) . '/admin/llm_connector/diagnostics.php';

$path = tempnam(sys_get_temp_dir(), 'service-diag-');
$previousCalls = 0;
set_error_handler(static function () use (&$previousCalls): bool { $previousCalls++; return true; });
try {
    DiagLog::init($path);
    NeuroSaaS\Diagnostics::install();
    NeuroSaaS\Diagnostics::install(); // repeated includes must not stack handlers
    @trigger_error('SUPPRESSED_CUSTOMER_PAYLOAD', E_USER_WARNING);
    trigger_error('PRIVATE_CUSTOMER_PAYLOAD api_key=unregistered', E_USER_WARNING);
    $rows = DiagLog::tail();
    if (count($rows) !== 1 || $rows[0]['level'] !== 'warn') throw new RuntimeException('Only unsuppressed warning must be captured once.');
    if ($previousCalls !== 2) throw new RuntimeException('Previous PHP handler must remain in control.');
    if (strpos(json_encode($rows), 'CUSTOMER_PAYLOAD') !== false || strpos(json_encode($rows), 'unregistered') !== false) {
        throw new RuntimeException('Automatic capture must exclude arbitrary PHP messages.');
    }
    $context = json_decode($rows[0]['context'], true);
    if ($context['file'] !== 'tests/diagnostics.php' || $context['errno'] !== E_USER_WARNING || $context['line'] < 1) {
        throw new RuntimeException('Technical file, line and error type must be retained.');
    }
    echo "4 diagnostic capture checks passed\n";
} finally {
    restore_error_handler();
    restore_error_handler();
    @unlink($path);
}
