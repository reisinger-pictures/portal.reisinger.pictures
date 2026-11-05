#!/bin/sh

set -eu

database="${DB_CONNECTION:-}"
case "$database" in
    mariadb|pgsql) ;;
    *)
        echo 'FATAL: DB_CONNECTION must select mariadb or pgsql before database readiness can be checked.' >&2
        exit 1
        ;;
esac

attempt=1
while [ "$attempt" -le 60 ]; do
    if php -r '
        $app = require "bootstrap/app.php";
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        \Illuminate\Support\Facades\DB::connection()->select("SELECT 1");
    ' >/dev/null 2>&1; then
        printf 'Selected database (%s) accepts Laravel queries.\n' "$database"
        exit 0
    fi

    sleep 2
    attempt=$((attempt + 1))
done

printf "FATAL: selected database '%s' did not accept a Laravel query within 120 seconds.\n" "$database" >&2
exit 1
