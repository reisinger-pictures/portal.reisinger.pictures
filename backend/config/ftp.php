<?php

/**
 * FTP inbox import: limits, the background chain and the upload event (D-35/D-36/D-37).
 *
 * Values are passed through as the raw environment strings on purpose. Casting
 * them here with `(int)` turns an empty value into 0, and 0 is not a usable
 * limit: the old controller rescued it to 1, which silently shrank every batch
 * to a single file instead of restoring the documented default. Validation and
 * the documented defaults (20 files, 20 MiB per file, 100 MiB per run, 25 batches
 * and 3 failed attempts per chain, quarantine after 3 attempts) live in
 * `FtpInboxImporter`, the only reader of these keys — see AGENTS.md §14/D-35,
 * D-36 and D-37.
 */

/*
 * D-36: the internal endpoint SFTPGo posts its upload events to, and how it
 * authenticates against it.
 *
 * `url` defaults to the portal's own public URL plus the endpoint path, because
 * that is the only address SFTPGo can reach in every deployment (in-network
 * service names differ per compose revision).
 *
 * The default cannot be expressed with `env()`'s second argument. The compose
 * file always publishes `FTP_EVENT_URL` — as `${FTP_EVENT_URL:-}`, i.e. an
 * **empty string** when Portainer has no value — and Laravel's `env()` returns
 * that empty string rather than the default (`PhpOption\Some::getOrCall` hands
 * back `''`, and `PutenvAdapter` maps an empty putenv value to `Some('')`). So
 * "missing" and "present but empty" must be resolved to the same thing here, or
 * the documented promise ("leer darf bleiben", deployment/README.md §FTP-Upload-
 * Events, 19-ftp-upload-pipeline.md §7.18.1) would be false in the deployed
 * stack and `ftp:provision-events` would abort on every deploy. Whitespace
 * counts as empty too.
 *
 * The fallback is only usable when `APP_URL` itself is an absolute http(s) URL,
 * because that is exactly what SFTPGo accepts: `EventActionHTTPConfig.validate()`
 * (`internal/dataprovider/eventrule.go`, v2.7.6) runs
 * `util.IsStringPrefixInSlice($endpoint, ['http://', 'https://'])` — a
 * case-sensitive prefix check. A relative `APP_URL` therefore yields `null`
 * rather than a malformed endpoint, and `ftp:provision-events` names the missing
 * value instead of letting SFTPGo answer 400.
 *
 * `secret` has no default on purpose: an empty secret makes the endpoint reject
 * every event with 401 rather than accept unauthenticated ones. `secret_header`
 * is not an environment variable — it is the contract between the endpoint and
 * `ftp:provision-events`, and one constant beats two env values that have to be
 * kept in sync.
 */
$isAbsoluteHttpUrl = static function (mixed $url): bool {
    if (! is_string($url)) {
        return false;
    }

    return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
};

$configuredEventUrl = env('FTP_EVENT_URL');
$appUrl = env('APP_URL');

$eventUrl = is_string($configuredEventUrl) && trim($configuredEventUrl) !== ''
    ? trim($configuredEventUrl)
    : ($isAbsoluteHttpUrl($appUrl) ? rtrim(trim((string) $appUrl), '/').'/api/internal/ftp/upload-event' : null);

return [
    'import' => [
        'max_files' => env('FTP_IMPORT_MAX_FILES'),
        'max_file_bytes' => env('FTP_IMPORT_MAX_FILE_BYTES'),
        'max_batch_bytes' => env('FTP_IMPORT_MAX_BATCH_BYTES'),

        // D-36: the self-chaining background import. `chain_max_batches` is the
        // hard cap per trigger; `chain_max_failures` is how often a chain retries
        // after a thrown failure.
        'chain_max_batches' => env('FTP_IMPORT_MAX_CHAIN_BATCHES'),
        'chain_max_failures' => env('FTP_IMPORT_MAX_CHAIN_FAILURES'),

        // D-37: after this many failed attempts a file moves to the inbox's
        // `_quarantine` subfolder instead of blocking the inbox.
        'quarantine_after_attempts' => env('FTP_IMPORT_QUARANTINE_AFTER_ATTEMPTS'),
    ],

    'events' => [
        'url' => $eventUrl,
        'secret' => env('FTP_EVENT_SECRET'),
        'secret_header' => 'X-FTP-Event-Secret',
        'action_name' => 'portal-ftp-upload',
        'rule_name' => 'portal-ftp-upload-rule',
    ],
];
