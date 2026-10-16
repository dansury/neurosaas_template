<?php
/**
 * Contract — the frozen promise this module makes to whatever embeds it.
 * spec: spec/selfheal.md §2
 *
 * Everything a host project is allowed to depend on is named here. Anything
 * NOT named here is internal and may change in any release. That is the whole
 * mechanism by which work inside this repository cannot break someone's code:
 *
 *   API_VERSION is semver over the PUBLIC surface only:
 *     patch — internals, fixes, new files             → host untouched
 *     minor — new public methods / features / keys     → host untouched
 *     major — a public method changes or disappears    → never shipped through
 *             the auto-updater (Updater refuses it, the admin is asked instead)
 *
 * A host pins what it needs with Contract::require('1.x') and reads
 * Contract::supports('feature') before using anything optional.
 */

declare(strict_types=1);

namespace Selfheal;

final class Contract {
    /** Machine name of the module (labels, user agent, issue titles). */
    public const MODULE = 'site_yacloud_openrouter';

    /** Public API version. Bump minor for additions, major NEVER auto-ships. */
    public const API_VERSION = '1.0.0';

    /** Canonical home of the code — where reports go and updates come from. */
    public const REPO = 'dansury/site_yacloud_openrouter';

    /** Public surface, frozen. Host code may call these and nothing else. */
    public const PUBLIC_API = [
        'SelfHeal::boot', 'SelfHeal::booted', 'SelfHeal::version', 'SelfHeal::apiVersion',
        'SelfHeal::supports', 'SelfHeal::capture', 'SelfHeal::note', 'SelfHeal::signError',
        'SelfHeal::userMessage', 'SelfHeal::reporting', 'SelfHeal::setReporting',
        'SelfHeal::issueKey', 'SelfHeal::grants', 'SelfHeal::updateStatus',
        'SelfHeal::applyUpdate', 'SelfHeal::rollback', 'SelfHeal::adminNotice',
        'SelfHeal::handleAdminPost', 'SelfHeal::diagnostics', 'SelfHeal::flush',
    ];

    /** Optional features a host may probe for before using them. */
    public const FEATURES = [
        'reporting',   // errors → GitHub Issues of the canonical repo
        'consent',     // operator can switch reporting off at any time
        'updates',     // version check + staged update
        'rollback',    // restore the previous version
        'keys',        // signed capability keys for neighbouring modules
        'admin',       // renderable admin notice
        'isolation',   // chained handlers, own database, own namespace
    ];

    /** Capability names an issued key may carry. */
    public const CAPABILITIES = [
        'errors.report',   // may hand errors to the reporter
        'errors.read',     // may read the local report queue
        'updates.read',    // may read update status
        'updates.apply',   // may apply an update / roll back
        'admin',           // internal: admin form nonce
    ];

    private static ?array $manifest = null;

    /** module.json — the deployed version, requirements and preserved paths. */
    public static function manifest(): array {
        if (self::$manifest !== null) return self::$manifest;
        $defaults = [
            'module'      => self::MODULE,
            'version'     => '0.0.0',
            'api_version' => self::API_VERSION,
            'repo'        => self::REPO,
            'channel'     => 'main',
            'requires'    => ['php' => '7.4.0', 'ext' => ['curl', 'pdo_sqlite']],
            'preserve'    => ['data', 'selfheal/token.php', 'pull-config.php', '.env'],
        ];
        $file = SELFHEAL_MODULE_ROOT . '/module.json';
        if (is_file($file)) {
            $raw = json_decode((string) @file_get_contents($file), true);
            if (is_array($raw)) $defaults = array_merge($defaults, $raw);
        }
        self::$manifest = $defaults;
        return self::$manifest;
    }

    public static function version(): string {
        return (string) (self::manifest()['version'] ?? '0.0.0');
    }

    public static function repo(): string {
        $r = (string) (self::manifest()['repo'] ?? self::REPO);
        return preg_match('~^[\w.-]+/[\w.-]+$~', $r) === 1 ? $r : self::REPO;
    }

    public static function channel(): string {
        $c = (string) (self::manifest()['channel'] ?? 'main');
        return preg_match('~^[\w./-]{1,64}$~', $c) === 1 ? $c : 'main';
    }

    public static function supports(string $feature): bool {
        return in_array($feature, self::FEATURES, true);
    }

    /**
     * Host-side guard: `Contract::require('1.x')` or `Contract::require('>=1.2')`.
     * Returns true when the running API satisfies the constraint — a host that
     * checks this can never be surprised by an incompatible update.
     */
    public static function fits(string $constraint): bool {
        $constraint = trim($constraint);
        $have = self::API_VERSION;
        if ($constraint === '' || $constraint === '*') return true;
        if (substr($constraint, -2) === '.x') {                  // "1.x" / "1.2.x"
            $prefix = substr($constraint, 0, -1);                // "1." / "1.2."
            return strpos($have, $prefix) === 0;
        }
        if (strpos($constraint, '>=') === 0) return version_compare($have, trim(substr($constraint, 2)), '>=');
        if (strpos($constraint, '^') === 0) {                    // caret — same major
            $want = trim(substr($constraint, 1));
            return self::major($have) === self::major($want) && version_compare($have, $want, '>=');
        }
        return version_compare($have, $constraint, '==');
    }

    public static function major(string $v): string {
        $parts = explode('.', $v);
        return (string) (int) ($parts[0] ?? '0');
    }

    /** True when a remote release may be installed without human review. */
    public static function autoUpdatable(string $remoteApiVersion): bool {
        if ($remoteApiVersion === '') return false;
        return self::major($remoteApiVersion) === self::major(self::API_VERSION);
    }
}
