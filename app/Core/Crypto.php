<?php
namespace Omps\Core;

/**
 * Field encryption for sensitive values (ID numbers, family phone / address).
 * AES-256-GCM via OpenSSL (PHP 7.1+), plus an HMAC for exact lookup and duplicate checks.
 * The key is APP_KEY in .env ("base64:..." 32 bytes, created by `php omps key:generate`).
 * Losing APP_KEY means encrypted values cannot be read again: back up .env with the database.
 */
class Crypto
{
    const CIPHER = 'aes-256-gcm';

    private static function master()
    {
        $k = (string) env('APP_KEY', '');
        if (strncmp($k, 'base64:', 7) === 0) {
            $k = (string) base64_decode(substr($k, 7), true);
        }
        if (strlen($k) !== 32) {
            throw new \RuntimeException('APP_KEY is missing or invalid; run "php omps key:generate"');
        }
        return $k;
    }

    private static function subKey($purpose)
    {
        return hash_hmac('sha256', 'omps-' . $purpose, self::master(), true);
    }

    public static function hasKey()
    {
        try {
            self::master();
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public static function newKey()
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** @return string|null "v1:" + base64(iv | tag | ciphertext) */
    public static function encrypt($plain)
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt((string) $plain, self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt($stored)
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $stored = (string) $stored;
        if (strncmp($stored, 'v1:', 3) !== 0) {
            throw new \RuntimeException('Unknown cipher format');
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Corrupt cipher text');
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::subKey('enc'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed (wrong APP_KEY?)');
        }
        return $plain;
    }

    /** Keyed hash for exact matching (same input, same hash; not reversible). */
    public static function blindIndex($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return hash_hmac('sha256', mb_strtoupper(trim((string) $value)), self::subKey('index'));
    }

    /** 440102199001011234 -> 4401**********1234 ; short values keep only the last 2 characters. */
    public static function mask($value, $head = 4, $tail = 4)
    {
        $v = (string) $value;
        $len = mb_strlen($v);
        if ($len === 0) {
            return '';
        }
        if ($len <= $head + $tail) {
            return str_repeat('*', max(0, $len - 2)) . mb_substr($v, -2);
        }
        return mb_substr($v, 0, $head) . str_repeat('*', $len - $head - $tail) . mb_substr($v, -$tail);
    }
}
