# AGENTS.md — Backend (Laravel PHP)

Module-scoped operating guidelines for the Laravel backend in `backend/`.

The repository-wide rules in the root [`AGENTS.md`](../AGENTS.md) apply here as well. This file only covers what is specific to the backend module.

## Commands

`php` on `PATH` MUST be **8.5 or newer** (`backend/composer.json` requires `"php": "^8.5"`). Check before the first command, not after a failure:

```bash
php -r 'echo PHP_VERSION, PHP_EOL;'
```

- In scripts use `scripts/check-php-version.sh`; it reads the requirement from `composer.json` and aborts with a clear message. `scripts/e2e-up.sh` calls it first.
- If `php artisan` aborts with `Composer detected issues in your platform`, check the PHP version before suspecting `vendor/`; `composer install` does not fix it.
- macOS (Homebrew): `php` is `/opt/homebrew/bin/php`; from `backend/`: `php artisan test`.
- Windows (Git Bash): `export PATH="/c/Users/flori/.config/herd/bin/php85:$PATH"`; otherwise call the binary directly.
- Backend tests: `php artisan test` with the existing suite green.
- Pint (`STRICT`) is the formatter **and** its own auto-fix: `cd backend && ./vendor/bin/pint` reformats, `./vendor/bin/pint --test` checks only (CI mode). Never fix style violations by hand.

## Database Setup Policy (STRICT)

- After EVERY migration you MUST seed: `php artisan db:seed` (or the `--seed` flag). `migrate`/`migrate:fresh` alone is not enough — without the seed there is no admin user and login/auth are dead.
- `DatabaseSeeder` is **authoritative** for the settings keys it declares: it `upsert`s its own value on `(key, brand)` and therefore **overwrites those keys in production**. Before `db:seed` in production — and before `php artisan app:seed-if-fresh`, which calls the same seeder — verify whether affected values differ. Keys outside that list are created only when missing. Fields and evidence: [settings key meaning](../features/infrastructure/28-settings-key-meaning.md).
- `ADMIN_EMAIL` and a non-empty `ADMIN_PASSWORD` MUST be set before `composer setup`; `composer setup` copies a missing `.env` unasked and ends with `migrate --force --seed`.
- Local dev DB: SQLite file `backend/database/database.sqlite` (gitignored); no DB container required.
- `app:seed-if-fresh` is the first-install automation and runs only when the `users` table is empty. Entrypoint order: `migrate --force && app:seed-if-fresh && admin:update`. A deliberate production seed is the manual `php artisan db:seed --force` only, after the verification above. Contract pinned in `backend/tests/Feature/SeedIfFreshCommandTest.php`.
- E2E locations: `DatabaseSeeder` stays network-free and does not load E2E locations. Fresh E2E databases use `E2ELocationSeeder` plus the Scout location-index commands from `scripts/e2e-up.sh` or `.github/workflows/ci.yml`. Never call `app:import-locations` from the standard seed or production startup.

## Migration Policy (STRICT)

- The current migration frontier is recorded ONLY in [`features/tech/07-architectural-decisions.md`](../features/tech/07-architectural-decisions.md) (AD-2) and is measured there against repository and production; do not keep a number here.
- A deployed migration is never modified.
- A new migration is allowed only when the requirement cannot be met safely with existing tables, indexes and job contracts; document the concrete schema/backfill/rollback decision first.
- `down()` methods are never executed and may be left empty.

## Backend Parallel Testing (PHP) — SQLite `:memory:`

- The canonical test DB is SQLite `:memory:` (from `phpunit.xml`); no DB container, no grants, no worker DBs.
- `php artisan test --parallel` works out of the box: every `paratest` worker starts its own isolated in-memory DB.
- `RefreshDatabase` migrates the in-memory DB fresh at each PHPUnit process start, so parallel runs are isolated by nature.
- The full suite runs in ONE subagent (once), for reproducibility. Scoped runs without worker-DB setup: `php artisan test --filter <TestClass>`.
- The four `sqlite` tuning keys in `config/database.php` (`busy_timeout`, `journal_mode`, `synchronous`, `transaction_mode`, each overridable via `DB_*`) affect file-backed DBs only; on `:memory:` they are no-ops. The actual fix against `database is locked` is `transaction_mode=IMMEDIATE`. Pinned by `tests/Unit/Support/SqliteConnectionTuningTest.php`.
