<?php

use App\Models\User;

return [
    'defaults' => [
        // Standardmäßig nutzen wir sofort die API (Sanctum), nicht das Session-basierte 'web'
        'guard' => env('AUTH_GUARD', 'api'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT -> Sanctum migration driver (Portal #9, Phase 3)
    |--------------------------------------------------------------------------
    |
    | The `api` guard is now backed by Sanctum (D-73/D-74). The guard NAME
    | `api` never changes. 'legacy' remains as a fallback for the final
    | characterisation run while the JWT package is still installed; it is
    | removed together with that package in Phase 4 (D-77). The access and
    | refresh windows are unchanged by the swap (D-76).
    |
    */
    'migration' => [
        'driver' => env('AUTH_DRIVER', 'sanctum'),
        // D-76: the 240-minute access / 7-day refresh windows are unchanged by
        // the driver swap. JWT_TTL in the checked-in env files stays as-is for
        // legacy mode; these keys drive the Sanctum token lifetimes.
        'access_ttl' => (int) env('AUTH_ACCESS_TTL', 240),
        'refresh_ttl' => (int) env('AUTH_REFRESH_TTL', 10080),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            // D-74: the guard NAME stays `api`; only its driver is swapped.
            // The custom `sanctum_transient` driver is registered by
            // AppServiceProvider and wraps SanctumTransientGuard.
            'driver' => env('AUTH_DRIVER', 'sanctum') === 'legacy' ? 'jwt' : 'sanctum_transient',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'transient_eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
