<?php

return [
    // Wir machen den neuen Gmail REST Transport zum Standard!
    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        // ... Laravel's Standard Mailer ...
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 1025),
            'encryption' => env('MAIL_ENCRYPTION', 'null'),
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
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Reisinger Foto Portal')),
    ],
];
