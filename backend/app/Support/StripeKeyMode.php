<?php

namespace App\Support;

/**
 * Pure derivation of the Stripe key mode ("test" vs "live").
 *
 * A Stripe key is shaped `<prefix>_<mode>_<random>` where prefix is one of
 * `pk`/`sk`/`rk` (publishable/secret/restricted) and mode is `test` or `live`.
 * The mode is the token that *immediately* follows the prefix: a `_test_` or
 * `_live_` marker buried in the random portion must never flip the answer, and
 * a marker that sits behind garbage in the prefix-adjacent position must not be
 * honored at all. Anything unrecognizable (unknown prefix, empty value, null)
 * returns null, so a caller can fail closed instead of guessing.
 *
 * This class is deliberately free of `config()` and any side effect. The
 * environment-driven target mode is resolved by the caller (cache-safety: no
 * `env()` outside `config/`) and handed in through fromEnvironment().
 */
class StripeKeyMode
{
    public const TEST = 'test';

    public const LIVE = 'live';

    /**
     * Derive the test/live mode from a raw Stripe key (publishable, secret or
     * restricted). Returns null for an unknown prefix, an empty value or null.
     */
    public static function fromKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(pk|sk|rk)_(test|live)(?:_|$)/i', $value, $matches) === 1) {
            return strtolower($matches[2]);
        }

        return null;
    }

    /**
     * Map an APP_ENV value to the target mode. `production` is the only
     * environment that expects live keys; every other value (local, testing,
     * staging, …) expects test keys.
     */
    public static function fromEnvironment(?string $environment): string
    {
        return strtolower(trim((string) $environment)) === 'production' ? self::LIVE : self::TEST;
    }
}
