#!/bin/sh

set -eu
umask 077

QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-60}"
QUEUE_WORKER_RESTART_DELAY="${QUEUE_WORKER_RESTART_DELAY:-5}"
DB_QUEUE_RETRY_AFTER="${DB_QUEUE_RETRY_AFTER:-90}"
QUEUE_SUPERVISOR_PID_FILE=/tmp/portal-queue-supervisor.pid
QUEUE_WORKER_PID_FILE=/tmp/portal-queue-worker.pid
SCHEDULER_PID_FILE=/tmp/portal-scheduler.pid

is_positive_integer() {
    case "$1" in
        ''|*[!0-9]*) return 1 ;;
        *) [ "$1" -gt 0 ] ;;
    esac
}

if ! is_positive_integer "$QUEUE_WORKER_TIMEOUT"; then
    echo 'FATAL: QUEUE_WORKER_TIMEOUT muss eine positive ganze Zahl sein.' >&2
    exit 1
fi

if ! is_positive_integer "$QUEUE_WORKER_RESTART_DELAY"; then
    echo 'FATAL: QUEUE_WORKER_RESTART_DELAY muss eine positive ganze Zahl sein.' >&2
    exit 1
fi

if [ "${QUEUE_CONNECTION:-}" != 'database' ]; then
    echo 'FATAL: QUEUE_CONNECTION muss im Produktions-Stack database sein.' >&2
    exit 1
fi

if [ -z "${DB_CONNECTION:-}" ] || [ "${DB_QUEUE_CONNECTION:-}" != "${DB_CONNECTION:-}" ]; then
    echo 'FATAL: DB_QUEUE_CONNECTION muss DB_CONNECTION entsprechen.' >&2
    exit 1
fi

if ! is_positive_integer "$DB_QUEUE_RETRY_AFTER"; then
    echo 'FATAL: DB_QUEUE_RETRY_AFTER muss eine positive ganze Zahl sein.' >&2
    exit 1
fi

if [ "$QUEUE_WORKER_TIMEOUT" -ge "$DB_QUEUE_RETRY_AFTER" ]; then
    echo 'FATAL: QUEUE_WORKER_TIMEOUT muss kleiner als DB_QUEUE_RETRY_AFTER sein.' >&2
    exit 1
fi

# A container restart must not make a stale PID from a previous worker look
# healthy while the new supervisor is still starting.
rm -f "$QUEUE_SUPERVISOR_PID_FILE" "$QUEUE_WORKER_PID_FILE" "$SCHEDULER_PID_FILE"

queue_supervisor_loop() {
    while :; do
        php artisan queue:work \
            --name=portal-production \
            --tries=3 \
            --timeout="$QUEUE_WORKER_TIMEOUT" &
        worker_pid=$!

        printf '%s\n' "$worker_pid" > "${QUEUE_WORKER_PID_FILE}.tmp"
        mv "${QUEUE_WORKER_PID_FILE}.tmp" "$QUEUE_WORKER_PID_FILE"

        worker_status=0
        wait "$worker_pid" || worker_status=$?
        rm -f "$QUEUE_WORKER_PID_FILE"

        if [ "$worker_status" -ne 0 ]; then
            echo "Queue worker exited with status ${worker_status}; restarting in ${QUEUE_WORKER_RESTART_DELAY}s." >&2
        fi

        sleep "$QUEUE_WORKER_RESTART_DELAY"
    done
}

scheduler_loop() {
    while :; do
        if ! php artisan schedule:run; then
            echo 'Scheduler run failed; the next run will be attempted in 60s.' >&2
        fi

        sleep 60
    done
}

queue_supervisor_loop &
queue_supervisor_pid=$!
printf '%s\n' "$queue_supervisor_pid" > "${QUEUE_SUPERVISOR_PID_FILE}.tmp"
mv "${QUEUE_SUPERVISOR_PID_FILE}.tmp" "$QUEUE_SUPERVISOR_PID_FILE"

scheduler_loop &
scheduler_pid=$!
printf '%s\n' "$scheduler_pid" > "${SCHEDULER_PID_FILE}.tmp"
mv "${SCHEDULER_PID_FILE}.tmp" "$SCHEDULER_PID_FILE"

echo 'Queue supervisor and scheduler started.'

exec php-fpm -F
