<?php

namespace App\Support;

/**
 * Parses the portal-side Mollie method allowlist from environment values.
 *
 * This class only normalises the transport shape (comma list, trim,
 * lowercase, drop empty entries, preserve order). Whether an id is valid or
 * allowed is decided in `MolliePaymentService`, where a bad list disables
 * payment creation instead of being silently reduced.
 */
final class MollieMethodList
{
    /**
     * @return array<int, string>
     */
    public static function parse(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        $methods = [];
        foreach (explode(',', $raw) as $method) {
            $method = strtolower(trim($method));
            if ($method !== '') {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * The list wins whenever it contains an entry; the deprecated single
     * variable is the fallback for environments that predate the list.
     *
     * @return array<int, string>
     */
    public static function resolve(mixed $list, mixed $legacy): array
    {
        $methods = self::parse($list);

        return $methods !== [] ? $methods : self::parse($legacy);
    }
}
