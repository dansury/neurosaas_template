<?php
/**
 * Keys — signed capability keys the module hands to its neighbours.
 * spec: spec/selfheal.md §5
 *
 * Why this exists: a host project usually wraps the module in its own shell —
 * an admin panel, a queue worker, a second module. Those need to talk to this
 * one without importing its internals. Instead of exposing classes (which
 * would freeze our internals forever), the module issues an opaque key that
 * names what the bearer may do:
 *
 *   $k = SelfHeal::issueKey(['errors.report'], 3600);   // hand it anywhere
 *   ...
 *   if (SelfHeal::grants($k, 'errors.report')) { ... }  // verified here
 *
 * Properties:
 *   - signed with a per-install secret (generated once, stored in our own db),
 *     so a key from one install is worthless on another;
 *   - carries an optional expiry; no state per key, nothing to clean up;
 *   - unknown capabilities are rejected at issue time, so a typo fails loudly
 *     where it was written, not silently where it is checked;
 *   - the format is versioned ("sk1.") — future formats verify side by side.
 */

declare(strict_types=1);

namespace Selfheal;

use Throwable;

final class Keys {
    private const PREFIX = 'sk1.';
    private const SECRET_KEY = 'install_secret';

    private State $state;
    private ?string $secret = null;

    public function __construct(State $state) { $this->state = $state; }

    /** Stable per-install signing secret; created on first use. */
    public function secret(): string {
        if ($this->secret !== null) return $this->secret;
        $s = (string) $this->state->get(self::SECRET_KEY, '');
        if (strlen($s) < 32) {
            try { $s = bin2hex(random_bytes(32)); } catch (Throwable $e) { $s = hash('sha256', (string) mt_rand() . microtime()); }
            $this->state->set(self::SECRET_KEY, $s);
        }
        $this->secret = $s;
        return $s;
    }

    /**
     * Mint a key. $caps must be names from Contract::CAPABILITIES; $ttl 0 means
     * "no expiry". Returns '' when a capability is unknown — never a key that
     * silently grants less than asked.
     */
    public function issue(array $caps, int $ttl = 0): string {
        $clean = [];
        foreach ($caps as $c) {
            $c = (string) $c;
            if (!in_array($c, Contract::CAPABILITIES, true)) return '';
            $clean[$c] = true;
        }
        if (!$clean) return '';
        $body = [
            'c' => array_keys($clean),
            'i' => time(),
            // 0 = no expiry; any other value (including a negative one, which
            // must NOT quietly become "forever") is an absolute deadline.
            'e' => $ttl !== 0 ? time() + $ttl : 0,
            'm' => Contract::MODULE,
            'a' => Contract::API_VERSION,
        ];
        $payload = self::b64e((string) json_encode($body, JSON_UNESCAPED_SLASHES));
        return self::PREFIX . $payload . '.' . self::b64e(hash_hmac('sha256', $payload, $this->secret(), true));
    }

    /** True when $key is authentic, unexpired and carries $cap. */
    public function grants(string $key, string $cap): bool {
        $claims = $this->read($key);
        if ($claims === null) return false;
        return in_array($cap, (array) ($claims['c'] ?? []), true);
    }

    /** Verified claims, or null when the key is forged, malformed or expired. */
    public function read(string $key): ?array {
        $key = trim($key);
        if (strpos($key, self::PREFIX) !== 0) return null;
        $parts = explode('.', substr($key, strlen(self::PREFIX)));
        if (count($parts) !== 2) return null;
        [$payload, $sig] = $parts;
        $want = self::b64e(hash_hmac('sha256', $payload, $this->secret(), true));
        if (!hash_equals($want, $sig)) return null;
        $claims = json_decode(self::b64d($payload), true);
        if (!is_array($claims)) return null;
        $exp = (int) ($claims['e'] ?? 0);
        if ($exp > 0 && $exp < time()) return null;
        if ((string) ($claims['m'] ?? '') !== Contract::MODULE) return null;
        return $claims;
    }

    /** Short-lived nonce for our own admin forms (no session needed). */
    public function adminNonce(): string { return $this->issue(['admin'], 1800); }

    private static function b64e(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

    private static function b64d(string $txt): string {
        $pad = strlen($txt) % 4;
        if ($pad > 0) $txt .= str_repeat('=', 4 - $pad);
        $bin = base64_decode(strtr($txt, '-_', '+/'), true);
        return is_string($bin) ? $bin : '';
    }
}
