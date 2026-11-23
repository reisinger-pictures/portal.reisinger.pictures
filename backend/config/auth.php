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
    | Only 'sanctum' makes the Phase-2 token infrastructure reachable; the
    | actual guard flip and credential wiring are later phases. The guard NAME
    | `api` never changes (D-74).
    |
    */
    'migration' => [
        'driver' => env('AUTH_DRIVER', 'legacy'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'jwt',
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
