<?php

use App\Support\MollieMethodList;

$sftpGoBaseUrl = env('SFTPGO_BASE_URL', 'http://sftpgo:8080');
$sftpGoInternalHost = is_string($sftpGoBaseUrl)
    ? parse_url($sftpGoBaseUrl, PHP_URL_HOST)
    : null;

/*
 * `MOLLIE_PAYMENT_METHODS` is the portal-side allowlist: its format is a
 * comma-separated list, parsed here so a cached config keeps the same shape.
 * Values are trimmed, lowercased and emptied entries dropped; order is the
 * customer-visible order and the first entry is the default. The deprecated
 * single `MOLLIE_PAYMENT_METHOD` is only a fallback for environments that
 * predate the list. Format and BNPL validation deliberately live in
 * `MolliePaymentService`, which fails closed for the whole list when one
 * entry is not allowed.
 */
$mollieMethods = MollieMethodList::resolve(
    env('MOLLIE_PAYMENT_METHODS'),
    env('MOLLIE_PAYMENT_METHOD'),
);

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

    /*
     * Mollie is the online-payment provider for new orders (issue #53). The
     * key is server-only; test/live mode comes from its `test_`/`live_`
     * prefix. `methods` is the explicit portal-side allowlist; an empty,
     * malformed or BNPL-containing list disables payment creation (no method
     * is guessed). `webhook_url` is mandatory in every environment and must be
     * an HTTPS endpoint Mollie can reach; there is no fallback, and a missing
     * or plain-HTTP value disables payment creation.
     */
    'mollie' => [
        'key' => env('MOLLIE_KEY'),
        'methods' => $mollieMethods,
        'webhook_url' => env('MOLLIE_WEBHOOK_URL'),
        'idempotency_window_minutes' => max(5, (int) env('MOLLIE_IDEMPOTENCY_WINDOW_MINUTES', 60)),
        'max_attempts' => max(1, (int) env('MOLLIE_MAX_ATTEMPTS', 5)),
        // Issue #3: re-read paid attempts for late refunds/chargebacks that
        // arrived without a webhook. Bounded to a 90-day window and throttled
        // to once per interval so the five-minute run does not hammer Mollie.
        'refund_reconcile_window_days' => max(1, (int) env('MOLLIE_REFUND_RECONCILE_WINDOW_DAYS', 90)),
        'refund_reconcile_interval_days' => max(1, (int) env('MOLLIE_REFUND_RECONCILE_INTERVAL_DAYS', 3)),
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
     * `SFTPGO_API_KEY` is preferred and comes from the host production .env —
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

    /*
     * Signing key for detached signed links (offer tokens, shared contact-sheet
     * links, ...). D-77 moved these to native HMAC while preserving the exact
     * wire format, so the existing production JWT_SECRET keeps every
     * already-issued link valid without any deployment change. SIGNED_LINK_SECRET
     * is the dedicated replacement; APP_KEY is the last-resort fallback.
     */
    'signed_links' => [
        'secret' => env('SIGNED_LINK_SECRET') ?? env('JWT_SECRET') ?? env('APP_KEY'),
    ],

    /*
     * What a camera needs to be configured with, as shown in the management UI
     * (feature doc 7.13). Deliberately NO defaults: these are the host-facing
     * values, and a default would be a guess about this deployment's network
     * that nobody can see. The ports have to match what the compose publishes,
     * so both read the same variables — `${SFTPGO_SFTP_PORT:-2222}` in the
     * compose and `SFTPGO_SFTP_PORT` here. That is why `.env.production` sets
     * them explicitly instead of leaning on the compose default: one source,
     * and the UI can never show a port the container does not listen on.
     *
     * The passive range belongs here too, because it is the port range a
     * firewall has to open (feature doc 7.13) and a camera cannot be told
     * anything useful without it.
     */
    'ftp_transport' => [
        'internal_host' => env('SFTPGO_FTPS_INTERNAL_HOST', is_string($sftpGoInternalHost) ? $sftpGoInternalHost : 'sftpgo'),
        'internal_ftps_port' => env('SFTPGO_FTPS_INTERNAL_PORT', 989),
        'public_host' => env('FTP_PUBLIC_HOST'),
        'sftp_port' => env('SFTPGO_SFTP_PORT'),
        'ftps_port' => env('SFTPGO_FTPS_PORT'),
        'pasv_port_start' => env('SFTPGO_PASV_PORT_START'),
        'pasv_port_end' => env('SFTPGO_PASV_PORT_END'),
        // 1 = explicit (AUTH TLS), 2 = implicit. Read with no default so the UI
        // reports an unset mode instead of claiming one; the compose default of
        // 1 applies to the binding itself, and .env.production sets it.
        'ftps_tls_mode' => env('SFTPGO_FTPD_TLS_MODE'),
        // The subfolder the camera is told to upload into, relative to the
        // account root. '/' means the account root itself, which is correct as
        // long as one target gallery takes everything that arrives. A
        // subfolder is what makes Canon's 'Ordner wählen' usable, and it needs
        // no schema change: the path is deployment configuration, not a
        // property of the photographer.
        'upload_path' => env('FTP_UPLOAD_PATH'),
    ],
];
