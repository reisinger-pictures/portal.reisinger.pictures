<?php

return [
    // Production must select SMTP explicitly. Local/test environments keep a
    // log fallback when no mailer is configured, so unit fixtures do not need a
    // live SMTP service.
    'default' => env('MAIL_MAILER', env('APP_ENV') === 'production' ? null : 'log'),

    'mailers' => [
        // ... Laravel's Standard Mailer ...
        'smtp' => [
            'transport' => 'smtp',
            // Laravel 13 passes these keys to Symfony's EsmtpTransportFactory.
            // `encryption` is not a Laravel 13/Symfony SMTP setting.
            'scheme' => env('MAIL_SCHEME'),
            'require_tls' => env('MAIL_REQUIRE_TLS', env('APP_ENV') === 'production'),
            'host' => env('MAIL_HOST'),
            'port' => env('MAIL_PORT'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        // UNSER NEUER CUSTOM MAILER
        'gmail_rest' => [
            'transport' => 'gmail_rest',
            'client_id' => env('OAUTH_CLIENT_ID'),
            'client_secret' => env('OAUTH_CLIENT_SECRET'),
            'refresh_token' => env('OAUTH_REFRESH_TOKEN'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
        'array' => [
            'transport' => 'array',
        ],
    ],

    'from' => [
        // No placeholder sender is safe for production. The production policy
        // command requires a real address before workers are started.
        'address' => env('MAIL_FROM_ADDRESS'),
        'name' => env(
            'MAIL_FROM_NAME',
            env('APP_ENV') === 'production' ? null : env('APP_NAME', 'Reisinger Foto Portal'),
        ),
    ],
];
