<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET'),
        'allowed_hostnames' => env('TURNSTILE_ALLOWED_HOSTNAMES', ''),
        // Only the CI fixture may opt into the exact official Cloudflare
        // dummy-key response. Production keeps this false.
        'allow_dummy_test_keys' => filter_var(env('TURNSTILE_ALLOW_DUMMY_TEST_KEYS', false), FILTER_VALIDATE_BOOL),
    ],

    'ai' => [
        'enabled' => env('AI_ENABLED', false),
        'type' => env('AI_TYPE', 'openai'),
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL', 'gpt-4o'),
        'session_header' => env('AI_SESSION_HEADER', 'x-opencode-session'),
        'session_prefix' => env('AI_SESSION_PREFIX', 'portal-'),
    ],

    /*
     * SFTPGo Admin-API (P1-M22). The base URL is the in-network address of the
     * service; the API port is deliberately not published (feature doc 7.13).
     * `SFTPGO_API_KEY` is preferred and comes from the Portainer stack env —
     * the versioned compose only carries the placeholder (feature doc 7.7).
     * Without an API key the client falls back to `GET /api/v2/token` with the
     * SFTPGo admin credentials, the same ones
     * `SFTPGO_DEFAULT_ADMIN_USERNAME`/`_PASSWORD` create.
     */
    'sftpgo' => [
        'base_url' => env('SFTPGO_BASE_URL', 'http://sftpgo:8080'),
        'api_key' => env('SFTPGO_API_KEY'),
        'admin_username' => env('SFTPGO_ADMIN_USERNAME'),
        'admin_password' => env('SFTPGO_ADMIN_PASSWORD'),
    ],

    'accounting_email' => env('ACCOUNTING_EMAIL'),

    'proxy_delivery_header' => env('PROXY_DELIVERY_HEADER'),
];
