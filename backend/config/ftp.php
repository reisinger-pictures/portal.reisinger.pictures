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
 * D-36: the upload-event secret must stay inside the Docker network. The
 * backend is php-fpm-only and speaks FastCGI, so SFTPGo reaches its HTTP event
 * endpoint through the `ftp-events:8080` listener defined in
 * deployment/docker-compose.yml.
 *
 * Compose always publishes FTP_EVENT_URL as `${FTP_EVENT_URL:-}`. When unset,
 * Laravel's `env()` returns an empty string, so missing, empty and whitespace
 * values all resolve to the internal default below. A configured non-empty
 * value wins; the production operations policy requires it to match exactly.
 *
 * `secret` has no default on purpose: an empty secret makes the endpoint reject
 * every event with 401 rather than accept unauthenticated ones. `secret_header`
 * is not an environment variable — it is the contract between the endpoint and
 * `ftp:provision-events`, and one constant beats two env values that have to be
 * kept in sync.
 */
$configuredEventUrl = env('FTP_EVENT_URL');

$eventUrl = is_string($configuredEventUrl) && trim($configuredEventUrl) !== ''
    ? trim($configuredEventUrl)
    : 'http://ftp-events:8080/api/internal/ftp/upload-event';

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
