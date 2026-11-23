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
    | The `api` guard is backed by Sanctum (D-73/D-74). The guard NAME `api`
    | never changes; the JWT package and its legacy driver are gone (D-77).
    | Credentials stay in the same two httpOnly cookies (D-75) with the same
    | 240-minute access / 7-day refresh windows (D-76).
    |
    */
    'migration' => [
        'driver' => env('AUTH_DRIVER', 'sanctum'),
        // D-75: cookie names stay `rp_jwt` / `rp_jwt_refresh` so the frontend
        // and already-issued credentials do not change.
        'cookie_access' => env('AUTH_COOKIE_ACCESS', 'rp_jwt'),
        'cookie_refresh' => env('AUTH_COOKIE_REFRESH', 'rp_jwt_refresh'),
        // D-76: the 240-minute access / 7-day refresh windows are unchanged.
        'access_ttl' => (int) env('AUTH_ACCESS_TTL', 240),
        'refresh_ttl' => (int) env('AUTH_REFRESH_TTL', 10080),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            // D-74: the guard NAME stays `api`; only its driver was swapped.
            // The custom `sanctum_transient` driver is registered by
            // AppServiceProvider and wraps SanctumTransientGuard.
            'driver' => 'sanctum_transient',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            // The legacy transient provider only existed to read JWT claims in
            // retrieveById(). Sanctum resolves guests in SanctumTransientGuard,
            // so the plain Eloquent provider also restores web-guard resolution
            // by id (D-77).
            'driver' => 'eloquent',
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
