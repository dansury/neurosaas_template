<?php
/**
 * Self-maintaining module — the ONLY file a host project includes.
 * spec: spec/selfheal.md
 *
 * Isolation contract (why this file looks paranoid):
 *   - everything lives in the `Selfheal\` namespace, so no class name of the
 *     host project can ever collide with ours;
 *   - the autoloader answers for the `Selfheal\` prefix only and returns
 *     silently for anything else — it cannot hijack the host's class loading;
 *   - no global variable, no global function, no ini_set, no error_reporting
 *     change, no session_start, no output, no exit;
 *   - double include is a no-op.
 *
 * Usage (one line in the host bootstrap):
 *   require_once __DIR__ . '/selfheal/bootstrap.php';
 *   Selfheal\SelfHeal::boot(['app' => 'my-project']);
 *
 * PHP 7.4+.
 */

if (defined('SELFHEAL_BOOTSTRAP')) return;
define('SELFHEAL_BOOTSTRAP', '1');

/** Directory of the self-healing layer. */
define('SELFHEAL_DIR', __DIR__);

/** Module root — the directory the updater replaces as a whole. */
define('SELFHEAL_MODULE_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'Selfheal\\') !== 0) return;          // not ours — stay out
    $rel  = str_replace('\\', '/', substr($class, 9));
    $file = SELFHEAL_DIR . '/' . $rel . '.php';
    // Keep the search inside our own directory: no traversal via odd class names.
    if (strpos($rel, '..') !== false || !is_file($file)) return;
    require_once $file;
}, true, false);                                              // appended, never prepended
