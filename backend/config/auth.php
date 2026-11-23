<?php

use App\Models\User;

return [
    'defaults' => [
        // Standardmäßig nutzen wir sofort die API (JWT), nicht das Session-basierte 'web'
        'guard' => env('AUTH_GUARD', 'api'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT -> Sanctum migration driver (Portal #9, Phase 2)
    |--------------------------------------------------------------------------
    |
    | Dormant feature flag. The default and every existing environment stay on
    | 'legacy': the JWT path below is used unchanged, the `api` guard keeps its
    | `jwt` driver and no controller/middleware/route behaviour changes.
    | Only 'sanctum' makes the token infrastructure reachable: the `api` guard
    | driver and the controllers' credential issuance switch together. The guard NAME
    | `api` never changes (D-74).
    |
    */
    'migration' => [
        'driver' => env('AUTH_DRIVER', 'legacy'),
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
            // D-74: the guard NAME stays `api`; only its driver is swapped. The
            // default is unchanged JWT behaviour; the custom driver is registered
            // by AppServiceProvider and wraps SanctumTransientGuard.
            'driver' => env('AUTH_DRIVER', 'legacy') === 'sanctum' ? 'sanctum_transient' : 'jwt',
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
