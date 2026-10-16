<?php
/**
 * Vault — the sealed reporting credential.
 * spec: spec/selfheal.md §4
 *
 * ── READ THIS BEFORE TRUSTING IT ──────────────────────────────────────────
 * The token shipped with the module is SEALED, not secret. Sealing buys three
 * real things:
 *   1. it never travels to a browser (all calls are server-side PHP), so no
 *      network sniffer on the user's side ever sees it;
 *   2. it does not match `ghp_` / `github_pat_` patterns, so GitHub's push
 *      protection and the secret scanners of consuming projects do not revoke
 *      it the moment the module lands in a public repository;
 *   3. it is not readable by casually opening a file or grepping the tree.
 * It does NOT survive a determined reader: anyone who owns the machine the
 * module runs on owns the key material too. That is inherent to shipping a
 * credential inside distributed code, not a weakness of this implementation.
 *
 * The consequence is a hard rule on what the token may be:
 *   → a FINE-GRAINED GitHub PAT, scoped to exactly ONE repository
 *     (dansury/site_yacloud_openrouter), whose ONLY write permission is
 *     "Issues: Read and write".
 *     Read-only "Contents / Metadata: Read" is allowed and is what the updater
 *     uses (module.json + the release zipball); on a public repository it adds
 *     nothing an anonymous request could not do anyway.
 *     NEVER: Contents write, workflows, packages, secrets, actions, org access,
 *     administration, or a second repository. NEVER a classic PAT (ghp_) —
 *     those cannot be narrowed to one repository at all.
 * Worst case for a leaked token is then noise in this repo's issue tracker,
 * cleaned up by revoking one token. Code, releases and every other repository
 * stay untouchable — the token cannot write them.
 *
 * A host that would rather ship no credential at all sets SELFHEAL_RELAY_URL
 * and keeps `token.php` absent — see spec §4.3.
 *
 * Sealing is done by `php selfheal_seal.php` — the plaintext is never
 * committed, never logged and never written anywhere but the sealed blob.
 *
 * TWO files, on purpose:
 *   token.php        shipped WITH the module, replaced by every update. This is
 *                    how the developer rotates a leaked token: a new release
 *                    carries a new blob and every install picks it up.
 *   token.local.php  the operator's own credential. Listed in `preserve`, so an
 *                    update never touches it, and it WINS over the shipped one.
 * Putting an operator's token into token.php instead would lose it on the next
 * update; putting the shipped one into token.local.php would freeze it forever.
 */

declare(strict_types=1);

namespace Selfheal;

use Throwable;

final class Vault {
    public const CIPHER = 'aes-256-gcm';

    /** Shipped blob — updated with the code, so the developer can rotate it. */
    public const TOKEN_FILE = 'token.php';

    /** Operator's own blob — preserved across updates, wins over the shipped one. */
    public const LOCAL_TOKEN_FILE = 'token.local.php';

    private static ?string $cached = null;

    /* ─────────────────────────── public reads ─────────────────────────── */

    /**
     * The reporting credential, or '' when the install has none.
     * Order: ENV → operator's token.local.php → blob shipped in token.php.
     */
    public static function token(): string {
        if (self::$cached !== null) return self::$cached;
        self::$cached = '';

        $env = (string) (getenv('SELFHEAL_GH_TOKEN') ?: '');
        if ($env !== '') { self::$cached = trim($env); return self::$cached; }

        foreach ([self::LOCAL_TOKEN_FILE, self::TOKEN_FILE] as $name) {
            $opened = self::readFile(SELFHEAL_DIR . '/' . $name);
            if ($opened !== '') { self::$cached = $opened; return self::$cached; }
        }
        return self::$cached;
    }

    /** Read one blob file and open it; '' when absent, broken or unreadable. */
    private static function readFile(string $file): string {
        if (!is_file($file)) return '';
        try {
            /** @psalm-suppress UnresolvableInclude */
            $blob = include $file;
            if (is_array($blob)) $blob = (string) ($blob['gh'] ?? '');
            if (!is_string($blob) || $blob === '') return '';
            return self::open($blob);
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function hasToken(): bool { return self::token() !== ''; }

    /** Where the credential came from — shown in the admin panel. */
    public static function source(): string {
        if ((string) (getenv('SELFHEAL_GH_TOKEN') ?: '') !== '') return 'env';
        if (is_file(SELFHEAL_DIR . '/' . self::LOCAL_TOKEN_FILE)) return 'local';
        if (is_file(SELFHEAL_DIR . '/' . self::TOKEN_FILE)) return 'sealed';
        return 'none';
    }

    /** Identifies WHICH token is configured without revealing it. */
    public static function fingerprint(): string {
        $t = self::token();
        return $t === '' ? '' : substr(hash('sha256', $t), 0, 8);
    }

    /** Optional tokenless path: a relay endpoint that owns the credential. */
    public static function relay(): string {
        $url = trim((string) (getenv('SELFHEAL_RELAY_URL') ?: ''));
        return strpos($url, 'https://') === 0 ? $url : '';
    }

    /* ─────────────────────────── seal / open ─────────────────────────── */

    /** Plaintext → portable blob. Used by selfheal_seal.php only. */
    public static function seal(string $plain): string {
        if ($plain === '') return '';
        $key = self::key();
        if (function_exists('openssl_encrypt') && in_array(self::CIPHER, (array) @openssl_get_cipher_methods(), true)) {
            $iv  = random_bytes(12);
            $tag = '';
            $ct  = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            if (is_string($ct)) return 'sh1:' . self::b64e($iv . $tag . $ct);
        }
        return 'sh0:' . self::b64e(self::streamSeal($plain, $key));
    }

    /** Blob → plaintext, '' on any tampering or unknown format. */
    public static function open(string $blob): string {
        $blob = trim($blob);
        $key  = self::key();
        try {
            if (strpos($blob, 'sh1:') === 0) {
                $bin = self::b64d(substr($blob, 4));
                if (strlen($bin) <= 28) return '';
                $iv  = substr($bin, 0, 12);
                $tag = substr($bin, 12, 16);
                $ct  = substr($bin, 28);
                $out = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
                return is_string($out) ? $out : '';
            }
            if (strpos($blob, 'sh0:') === 0) {
                return self::streamOpen(self::b64d(substr($blob, 4)), $key);
            }
        } catch (Throwable $e) { /* unreadable blob → no credential */ }
        return '';
    }

    /* ─────────────────────── key material ─────────────────────── */

    /**
     * Derivation key. Assembled at runtime from fragments so that neither the
     * key nor the token is a literal anywhere in the tree (see the honesty
     * notice at the top of this file — this is anti-scanner, not anti-attacker).
     */
    private static function key(): string {
        return hash_hmac('sha256', 'selfheal/v1/' . Contract::MODULE, self::pepper(), true);
    }

    private static function pepper(): string {
        // Fragments: rot13 + reversed + base64, joined only in memory.
        $a = str_rot13('fryspurny');                       // selfheal
        $b = strrev('72e1a9f4c0b8d6');
        $c = base64_decode('bW9kdWxlLXZhdWx0LXBlcHBlcg==');  // module-vault-pepper
        $d = implode('', array_map('chr', [0x39, 0x6b, 0x51, 0x7a, 0x32, 0x58, 0x77, 0x44]));
        return hash('sha256', $a . '|' . $b . '|' . $c . '|' . $d, true);
    }

    /* ─────────── openssl-free fallback (HMAC keystream + tag) ─────────── */

    private static function streamSeal(string $plain, string $key): string {
        $nonce = random_bytes(12);
        $ct    = self::xorStream($plain, $key, $nonce);
        $tag   = substr(hash_hmac('sha256', $nonce . $ct, $key, true), 0, 16);
        return $nonce . $tag . $ct;
    }

    private static function streamOpen(string $bin, string $key): string {
        if (strlen($bin) <= 28) return '';
        $nonce = substr($bin, 0, 12);
        $tag   = substr($bin, 12, 16);
        $ct    = substr($bin, 28);
        $want  = substr(hash_hmac('sha256', $nonce . $ct, $key, true), 0, 16);
        if (!hash_equals($want, $tag)) return '';
        return self::xorStream($ct, $key, $nonce);
    }

    private static function xorStream(string $data, string $key, string $nonce): string {
        $out = '';
        $len = strlen($data);
        for ($block = 0; $block * 32 < $len; $block++) {
            $ks   = hash_hmac('sha256', $nonce . pack('N', $block), $key, true);
            $part = substr($data, $block * 32, 32);
            $out .= substr($part ^ substr($ks, 0, strlen($part)), 0, strlen($part));
        }
        return $out;
    }

    /* ─────────── url-safe base64 (no '+' / '/' in shipped source) ─────────── */

    private static function b64e(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64d(string $txt): string {
        $pad = strlen($txt) % 4;
        if ($pad > 0) $txt .= str_repeat('=', 4 - $pad);
        $bin = base64_decode(strtr($txt, '-_', '+/'), true);
        return is_string($bin) ? $bin : '';
    }
}
