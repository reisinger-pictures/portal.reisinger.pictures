#!/bin/sh

# This preflight runs before any Laravel command. Laravel casts several queue
# values to integers and normalizes mail options; checking the raw environment
# first prevents a malformed value from being hidden by that normalization.

set -eu
umask 077

fail() {
    printf 'FATAL: %s\n' "$*" >&2
    exit 1
}

has_whitespace() {
    case "$1" in
        *[[:space:]]*) return 0 ;;
        *) return 1 ;;
    esac
}

is_positive_integer() {
    case "$1" in
        ''|*[!0-9]*) return 1 ;;
        *) [ "$1" -gt 0 ] 2>/dev/null ;;
    esac
}

is_true() {
    case "$1" in
        1|true|TRUE|on|ON|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

is_smtp_scheme() {
    case "$1" in
        smtp|smtps) return 0 ;;
        *) return 1 ;;
    esac
}

is_shared_cache_store() {
    case "$1" in
        database|redis|memcached|dynamodb) return 0 ;;
        *) return 1 ;;
    esac
}

app_env=${APP_ENV:-}
db_connection=${DB_CONNECTION:-}
db_queue_connection=${DB_QUEUE_CONNECTION:-}
queue_connection=${QUEUE_CONNECTION:-}
queue_failed_driver=${QUEUE_FAILED_DRIVER:-}
db_queue_retry_after=${DB_QUEUE_RETRY_AFTER:-}
queue_worker_timeout=${QUEUE_WORKER_TIMEOUT:-}
queue_worker_restart_delay=${QUEUE_WORKER_RESTART_DELAY:-}
cache_store=${CACHE_STORE:-}
db_cache_connection=${DB_CACHE_CONNECTION:-}
db_cache_lock_connection=${DB_CACHE_LOCK_CONNECTION:-}
mail_mailer=${MAIL_MAILER:-}
mail_scheme=${MAIL_SCHEME:-}
mail_require_tls=${MAIL_REQUIRE_TLS:-}
mail_host=${MAIL_HOST:-}
mail_port=${MAIL_PORT:-}
mail_username=${MAIL_USERNAME:-}
mail_password=${MAIL_PASSWORD:-}
mail_from_address=${MAIL_FROM_ADDRESS:-}
mail_from_name=${MAIL_FROM_NAME:-}

[ "$app_env" = 'production' ] || fail 'APP_ENV must be production.'
[ -n "$db_connection" ] || fail 'DB_CONNECTION must be non-empty.'
has_whitespace "$db_connection" && fail 'DB_CONNECTION must not contain whitespace.'
[ -n "$db_queue_connection" ] || fail 'DB_QUEUE_CONNECTION must be non-empty.'
has_whitespace "$db_queue_connection" && fail 'DB_QUEUE_CONNECTION must not contain whitespace.'
[ "$db_queue_connection" = "$db_connection" ] || fail 'DB_QUEUE_CONNECTION must equal DB_CONNECTION.'
[ "$queue_connection" = 'database' ] || fail 'QUEUE_CONNECTION must be database.'
has_whitespace "$queue_connection" && fail 'QUEUE_CONNECTION must not contain whitespace.'
[ "$queue_failed_driver" = 'database-uuids' ] || fail 'QUEUE_FAILED_DRIVER must be database-uuids.'
has_whitespace "$queue_failed_driver" && fail 'QUEUE_FAILED_DRIVER must not contain whitespace.'

is_positive_integer "$db_queue_retry_after" || fail 'DB_QUEUE_RETRY_AFTER must be a raw positive integer.'
is_positive_integer "$queue_worker_timeout" || fail 'QUEUE_WORKER_TIMEOUT must be a raw positive integer.'
is_positive_integer "$queue_worker_restart_delay" || fail 'QUEUE_WORKER_RESTART_DELAY must be a raw positive integer.'
[ "$queue_worker_timeout" -lt "$db_queue_retry_after" ] || fail 'QUEUE_WORKER_TIMEOUT must be less than DB_QUEUE_RETRY_AFTER.'

[ -n "$cache_store" ] || fail 'CACHE_STORE must be non-empty.'
has_whitespace "$cache_store" && fail 'CACHE_STORE must not contain whitespace.'
is_shared_cache_store "$cache_store" || fail 'CACHE_STORE must be a shared store for onOneServer.'
if [ "$cache_store" = 'database' ]; then
    [ "$db_cache_connection" = "$db_connection" ] || fail 'DB_CACHE_CONNECTION must equal DB_CONNECTION for the database cache.'
    [ "$db_cache_lock_connection" = "$db_cache_connection" ] || fail 'DB_CACHE_LOCK_CONNECTION must equal DB_CACHE_CONNECTION.'
fi

[ "$mail_mailer" = 'smtp' ] || fail 'MAIL_MAILER must be smtp.'
is_smtp_scheme "$mail_scheme" || fail 'MAIL_SCHEME must be smtp or smtps.'
[ -n "$mail_require_tls" ] || fail 'MAIL_REQUIRE_TLS must be non-empty.'
has_whitespace "$mail_require_tls" && fail 'MAIL_REQUIRE_TLS must not contain whitespace.'
is_true "$mail_require_tls" || fail 'MAIL_REQUIRE_TLS must be true.'
[ -n "$mail_host" ] || fail 'MAIL_HOST must be non-empty.'
has_whitespace "$mail_host" && fail 'MAIL_HOST must not contain whitespace.'
is_positive_integer "$mail_port" || fail 'MAIL_PORT must be a raw positive integer.'
[ "$mail_port" -le 65535 ] || fail 'MAIL_PORT must not exceed 65535.'
[ -n "$mail_username" ] || fail 'MAIL_USERNAME must be non-empty.'
[ -n "$mail_password" ] || fail 'MAIL_PASSWORD must be non-empty.'
[ -n "$mail_from_address" ] || fail 'MAIL_FROM_ADDRESS must be non-empty.'
has_whitespace "$mail_from_address" && fail 'MAIL_FROM_ADDRESS must not contain whitespace.'
[ -n "$mail_from_name" ] || fail 'MAIL_FROM_NAME must be non-empty.'

sender_lower=$(printf '%s' "$mail_from_address" | tr '[:upper:]' '[:lower:]')
[ "$sender_lower" != 'hello@example.com' ] || fail 'MAIL_FROM_ADDRESS must not use the placeholder sender address.'

[ -x /usr/local/bin/portal-backend-supervisor ] || fail 'Supervisor binary is missing; rebuild portal-base, push GHCR, and update the compose digest before starting.'

echo 'Production environment preflight passed.'
