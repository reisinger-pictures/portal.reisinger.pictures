#!/bin/sh

set -eu

database="${DB_CONNECTION:-}"
case "$database" in
    pgsql) ;;
    *)
        echo 'FATAL: DB_CONNECTION must select pgsql before database readiness can be checked.' >&2
        exit 1
        ;;
esac

attempt=1
while [ "$attempt" -le 60 ]; do
    if php -r '
        # php -r does not load the Composer autoloader; without it the Laravel
        # Application class is missing and this readiness check can never pass.
        require "vendor/autoload.php";
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
