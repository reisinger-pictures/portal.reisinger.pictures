<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Seed the database, but only when it has never been seeded.
 *
 * Replaces the unconditional `php artisan db:seed --force` in
 * `deployment/docker-compose.yml` (owner decision 2026-09-28).
 *
 * WHY: `DatabaseSeeder::seedCatalogForBrand()` writes its 28 declared `settings`
 * rows with `upsert` on (key, brand), so the seeder is authoritative for
 * exactly those keys and a `db:seed` overwrites what an operator set through
 * the UI. `AGENTS.md` §13 makes `docker restart portal_backend` mandatory after
 * any sync with PHP changes, and the entrypoint runs on every start — so the
 * mandated restart silently rewrote 28 production keys, including the pricing
 * terms, while the "check for differing values before `db:seed` in production"
 * warning in `backend/AGENTS.md` only ever covered a *manual* seed. Discovered
 * during a real deploy on 2026-09-28.
 *
 * The seed stays: a first install has to be automated. Only the *when* changes —
 * a running production is no longer re-seeded by a restart.
 *
 * FRESHNESS SIGNAL: an empty `users` table.
 * - `backend/AGENTS.md` (Database Setup Policy) states the invariant the whole
 *   login flow rests on: without a seed there is no admin user, so login and
 *   auth are dead. An empty `users` table is exactly that condition.
 * - An empty `settings` table is NOT a usable signal: migrations create the
 *   schema and some of them insert rows into it (V004 writes `base_price`,
 *   `term_*`; V005 the three bank keys), so a migrated-but-unseeded database
 *   already has a populated `settings` table and would look "seeded".
 * - No migration inserts a user. V004 and V018 only `UPDATE` existing user
 *   rows, so `users` is empty on a freshly migrated database and stays empty
 *   until something seeds it.
 *
 * ORDERING: the entrypoint runs this before `admin:update`, so on a first
 * install the check still sees an empty `users` table. `admin:update` creates
 * the admin itself and would therefore look like a seed on a later start.
 *
 * KNOWN LIMIT: if a seed writes the admin user and *then* throws, this command
 * skips the database on the next start because `users` is no longer empty. The
 * remedy is the manual, deliberately-triggered `php artisan db:seed --force` —
 * the seeder is idempotent, so re-running it is safe. The alternative — treating
 * "users present" as "possibly half-seeded" — would re-seed production, which is
 * the failure this command exists to prevent.
 *
 * A shell condition was rejected for the same decision: the container has no
 * `mysql` client, and `php artisan tinker` fails there with a psysh
 * write-permission error, so the entrypoint has no way to ask the database a
 * question without an artisan command.
 */
class SeedIfFresh extends Command
{
    protected $signature = 'app:seed-if-fresh';

    protected $description = 'Seed the database, but only when it has never been seeded (fresh users table)';

    public function handle(): int
    {
        // Fail closed: without the table there is no freshness signal, and
        // "assume fresh" would seed a database whose schema is not there yet.
        if (! Schema::hasTable('users')) {
            $this->components->error('No `users` table — run `php artisan migrate --force` before seeding.');

            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->components->info('Database already seeded (users present) — skipping the seed.');

            return self::SUCCESS;
        }

        $this->components->info('Fresh database (no users) — seeding.');

        try {
            // The existing seeding path, unchanged: the command decides *when*,
            // never *what*. `$settingDefaults` stays in one place.
            $exitCode = $this->call('db:seed', [
                '--class' => DatabaseSeeder::class,
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            $this->components->error('Seeding failed: '.$exception->getMessage());
            Log::error('app:seed-if-fresh: seeding failed', ['exception' => $exception]);

            return self::FAILURE;
        }

        if ($exitCode !== self::SUCCESS) {
            $this->components->error("Seeding failed (db:seed exited with {$exitCode}).");

            return self::FAILURE;
        }

        $this->components->info('Fresh database seeded.');

        return self::SUCCESS;
    }
}
