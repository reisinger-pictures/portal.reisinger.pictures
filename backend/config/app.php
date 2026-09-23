<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Reisinger Foto Portal'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'https://portal.test'),

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    | Used for generating absolute links to the decoupled frontend SPA.
    */
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:4321')),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    'stripe' => [
        'checkout_enabled' => filter_var(env('STRIPE_CHECKOUT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'customers_enabled' => filter_var(env('STRIPE_CUSTOMERS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'stale_payment_intent_hours' => max(1, (int) env('STRIPE_STALE_PAYMENT_INTENT_HOURS', 2)),
        'checkout_new_account_hours' => max(0, (int) env('STRIPE_CHECKOUT_NEW_ACCOUNT_HOURS', 24)),
    ],

    'throttle_auth' => env('AUTH_THROTTLE_LIMIT', 5),
    'throttle_api' => (int) env('API_THROTTLE_LIMIT', 120),
    'throttle_model_registration' => (int) env('MODEL_REGISTRATION_THROTTLE_LIMIT', 10),
    'throttle_download' => env('DOWNLOAD_THROTTLE', 60),
    'throttle_zip_download' => env('ZIP_DOWNLOAD_THROTTLE', 3),
    'checkout_throttle_user_per_hour' => (int) env('CHECKOUT_THROTTLE_USER_PER_HOUR', 5),
    'checkout_throttle_ip_per_hour' => (int) env('CHECKOUT_THROTTLE_IP_PER_HOUR', 10),
    'checkout_throttle_ip_per_day' => (int) env('CHECKOUT_THROTTLE_IP_PER_DAY', 30),
    'checkout_idempotency_ttl_minutes' => max(5, (int) env('CHECKOUT_IDEMPOTENCY_TTL_MINUTES', 30)),
    'turnstile_user_threshold_per_hour' => (int) env('TURNSTILE_USER_THRESHOLD_PER_HOUR', 3),
    'turnstile_ip_threshold_per_hour' => (int) env('TURNSTILE_IP_THRESHOLD_PER_HOUR', 5),
    'turnstile_failure_user_threshold_per_hour' => max(1, (int) env('TURNSTILE_FAILURE_USER_THRESHOLD_PER_HOUR', 3)),
    'turnstile_failure_ip_threshold_per_hour' => max(1, (int) env('TURNSTILE_FAILURE_IP_THRESHOLD_PER_HOUR', 5)),
    'turnstile_failure_window_seconds' => max(60, (int) env('TURNSTILE_FAILURE_WINDOW_SECONDS', 3600)),

];
