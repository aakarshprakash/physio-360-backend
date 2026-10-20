<?php

namespace App\Support;

use RuntimeException;

/**
 * Deterministic keyed hashes for exact-match search over encrypted columns.
 * The key is separate from APP_KEY so encryption keys can rotate independently.
 *
 * Phones index the last 8 digits so "+91 98765 43210", "09876543210" and
 * "98765 43210" all match; candidates are confirmed after decryption.
 */
final class BlindIndex
{
    public static function phone(?string $phone): ?string
    {
        $digits = self::digits($phone);

        return strlen($digits) >= 6 ? self::hash('phone', substr($digits, -8)) : null;
    }

    public static function email(?string $email): ?string
    {
        $normalized = self::normalizeEmail($email);

        return $normalized === '' ? null : self::hash('email', $normalized);
    }

    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public static function phonesMatch(?string $a, ?string $b): bool
    {
        $a = self::digits($a);
        $b = self::digits($b);

        if ($a === '' || $b === '') {
            return false;
        }

        $len = min(strlen($a), strlen($b), 10);

        return substr($a, -$len) === substr($b, -$len);
    }

    private static function hash(string $purpose, string $value): string
    {
        $key = config('app.blind_index_key');

        if (! $key) {
            throw new RuntimeException('BLIND_INDEX_KEY is not configured.');
        }

        return hash_hmac('sha256', $purpose.'|'.$value, $key);
    }
}
