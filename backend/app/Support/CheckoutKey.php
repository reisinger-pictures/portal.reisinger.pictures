<?php

namespace App\Support;

use App\Models\User;

/**
 * Centralized, non-reversible identifiers for checkout limiter/risk keys.
 *
 * Raw IP addresses are evidence data and must not be persisted in cache keys.
 * The application key provides domain separation for the digest; the local
 * fallback keeps tests and an unconfigured local install deterministic.
 */
final class CheckoutKey
{
    public static function user(User|int|string|null $user, string $namespace = 'default'): string
    {
        $identifier = $user instanceof User
            ? ActorIdentity::cacheIdentifier($user)
            : (string) ($user ?? 'unknown');

        return $namespace.':user:'.self::digest($identifier);
    }

    public static function ip(?string $ip, string $namespace = 'default'): string
    {
        $normalized = trim((string) ($ip ?? ''));
        if ($normalized === '') {
            $normalized = 'unknown';
        }

        return $namespace.':ip:'.self::digest(strtolower($normalized));
    }

    private static function digest(string $value): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            $key = 'checkout-key-local-fallback';
        }

        return hash_hmac('sha256', $value, $key);
    }
}
