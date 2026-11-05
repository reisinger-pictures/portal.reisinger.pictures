# PostgreSQL production cutover

## Target and topology

Production moves from MariaDB 11.4 to PostgreSQL 16. Compose keeps the two
engines side by side during the cutover:

| Engine | Compose service | Container | Volume | Role during cutover |
|---|---|---|---|---|
| MariaDB 11.4 | `db` | `portal_db` | `db_data` | Live source and rollback source |
| PostgreSQL 16 | `db_pg` | `portal_db_pg` | `db_pg_data` | New target, initialized by Laravel migrations |

The backend's host environment selects the active connection. The PostgreSQL
settings are `DB_CONNECTION=pgsql`, `DB_HOST=db_pg`, and `DB_PORT=5432`; the
MariaDB settings are `DB_CONNECTION=mariadb`, `DB_HOST=db`, and
`DB_PORT=3306`. The database name and credentials are shared by both engines.
Host `.env` files created before this cutover may not contain `DB_HOST` or
`DB_PORT`: Compose supplied the MariaDB coordinates as defaults. The cutover
script uses those historical defaults when the keys are absent, then adds
`DB_HOST` and `DB_PORT` during the switch. These are not new required secrets.
The host `.env` must not set a non-empty `DB_URL`: Laravel gives it precedence
over the individual `DB_*` coordinates, so the script refuses before backup if
it is present rather than claim a switch that the live backend did not make.
Remove it and provide `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` before
retrying; an absent or empty `DB_URL` is accepted.

## One-time cutover contract

The copy is a one-time, write-frozen operation, not live replication. It uses a
preparatory deployment followed by the cutover:

1. Deploy the PostgreSQL-capable code/image and dual-engine Compose while the
   host `.env` still selects MariaDB. The compatibility defaults keep a legacy
   host file without `DB_HOST`/`DB_PORT` on `db:3306`. Let the normal backend
   startup apply all pending migrations to MariaDB; confirm `migrate:status`
   has no `Pending` entries. The cutover script repeats this check before the
   backup and refuses to copy a source at an older schema frontier.
2. Stop application writes and workers. The host runbook script stops the
   backend service before taking the backup, keeping both engines unchanged
   until verification succeeds.
3. Create and validate a mode-0600, gzip-compressed MariaDB dump outside the
   web root, and record exact source row counts in a private manifest.
4. Start PostgreSQL and run the Laravel migrations to create its schema. Every
   one-off Artisan process receives `APP_CONFIG_CACHE` pointing at a per-run
   path that does not exist. The database-backed processes (the source
   `migrate:status` preflight, target `migrate`, copy, verify, `migrate:status`,
   and Tinker smoke queries) also receive explicit
   `DB_CONNECTION`/`DB_HOST`/`DB_PORT` and an empty `DB_URL=`. Laravel 13.30
   resolves `Application::getCachedConfigPath()` through `APP_CONFIG_CACHE`;
   the absent path makes the process load `config/` fresh, so a
   `bootstrap/cache/config.php` written before the switch cannot redirect it to
   MariaDB. The live backend's shared config cache is not deleted or modified.
5. Run `php artisan portal:db-copy`. It copies source base tables in stable
   primary-key batches and omits only Laravel's `migrations` bookkeeping table.
   Before copying, it clears the matching target tables so migration-provided
   defaults, partial prior copies, and data left by a rolled-back attempt cannot
   override the frozen MariaDB source. A retry rebuilds those target tables from
   the current source; MariaDB is never modified.
6. Run `php artisan portal:db-copy --verify-only` and the production smoke
   queries. The switch is permitted only after every check passes. The
   verifier compares every `settings` row exactly by `key`, `brand`, and
   `value` (including null values and null brands), not just row counts or
   sums.
7. Atomically edit the protected host `.env` and recreate the backend against
   PostgreSQL. MariaDB stays running and untouched.

Cutover and rollback take one exclusive host lock before any preflight check
(`flock` on `/var/lib/portal-postgres-cutover/cutover.lock`). A concurrent run
fails closed with a named error instead of interleaving a second backup, copy,
or environment flip. The lock stays held through an automatic rollback and is
released when the process exits.

Rollback restores the timestamped host `.env`, then explicitly selects
`DB_CONNECTION=mariadb`, `DB_HOST=db`, and `DB_PORT=3306`. It also sets the
queue/cache connection keys to `mariadb`, adding them if an older `.env`
omitted them, before recreating the backend. The host/port keys are added too
when an older `.env` never contained them, so Compose defaults cannot redirect
rollback traffic. The MariaDB data and volume remain the pre-cutover source.

A manual rollback after the switch abandons PostgreSQL-only writes from the
active database. The switch writes a root-only `switch-attempted` marker in
`/var/lib/portal-postgres-cutover` (state directory mode 0700, marker mode
0600) that records the MariaDB `.env` backup path. It survives a successful
cutover and is removed only after a successful manual or automatic rollback.
Whenever that marker exists, `--rollback` refuses to run unless the operator
passes `--accept-postgres-write-loss`, even if the host `.env` has since been
edited back to `DB_CONNECTION=mariadb`; the acknowledgement names that those
writes will not appear in the active MariaDB database. MariaDB is the frozen
pre-cutover snapshot and no replication copies PostgreSQL writes back into it.
The PostgreSQL volume remains available for manual reconciliation; reconcile it
before a later copy because the copier reconstructs the target from MariaDB. If
no switch was attempted (no marker and the selection is still MariaDB), a
normal `--rollback` needs no acknowledgement. An automatic rollback on a failed
switch remains automatic and logs the same boundary. The exact post-switch
host-side command is:

```bash
ssh root@reisinger.pictures 'bash -s -- --rollback --accept-postgres-write-loss' < deployment/migrate-to-postgres.sh
```

The command copies rows only. Laravel migrations own the PostgreSQL schema;
the copy command does not infer types, create tables, alter columns, or migrate
schema. Source and target columns must agree for copied source data.

## PostgreSQL backup and restore precondition

Compose configures neither WAL archiving nor WAL retention for `db_pg`; the
`db_pg_data` volume is the only PostgreSQL copy this repository describes. The
cutover script's gzip dump, checksum, and row manifest back up MariaDB only:
they are a one-time backup of the MariaDB source for this migration, do not
dump PostgreSQL, and are not an ongoing PostgreSQL backup. Before
the PostgreSQL database accepts production writes, an ongoing PostgreSQL backup
(base backup plus WAL archiving/PITR, or an equivalent tested dump schedule) and
a tested restore path are required preconditions. This branch does not provide,
configure, or prove either of them, and a completed cutover would not be
evidence that such a path exists.

## Verification invariants

The verifier returns success only when all of these checks pass:

- **Per-table exact row counts:** every source base table except `migrations`
  must exist on the target with the same count. This detects missing inserts,
  ignored conflicts, and incomplete copies. The copy starts each source table
  empty on PostgreSQL, so rows seeded by schema migrations (including roles and
  settings) are replaced by the source rows rather than retained on a unique-key
  conflict.
- **Exact settings identity and value:** every `settings` row is compared by
  `(key, brand, value)`, with null values and null brands preserved and a
  deterministic PHP-side byte ordering that does not depend on either engine's
  collation. A target row with the right count but the wrong value, or a
  missing/extra `(key, brand)` identity, fails `--verify-only` and blocks the
  switch.
- **Table-wide column parity:** every shared source table is streamed in
  primary-key batches and each source row is matched to the target row with the
  same primary key, including composite keys. Every shared column is compared
  after applying the copy path's `PostgresCopyValueNormalizer` transformation,
  with target values reduced to a canonical form (numeric scale, timestamp
  fractional zeros, exact arbitrary-precision JSON numbers and JSON object
  ordering, boolean and `bytea` representations).
  Nulls, zero dates and `tinyint(1)` booleans are therefore compared as the
  transformed target value. Key matching does not depend on the engines agreeing
  on string order or collation, and a same-count, foreign-key-valid row with a
  wrong non-settings field fails `--verify-only`. Mismatch output names the
  table, column and row key without printing the differing data value. The
  `--chunk` batch bounds memory and query size, so no production table is loaded
  whole on either side.
- **Money and amount sums:** sums are compared exactly for persisted money
  fields in orders, invoice snapshots, products/licensing, coupons, payout
  pools/statements, payout corrections, open items, and payout bookings. The
  values are stored as integer cents (or preserved numeric strings); a
  difference is data loss, not a rounding tolerance.
- **Sequence headroom:** each table with a serial `id` must have a next value
  strictly greater than `MAX(id)`. After a copy, the command resets each such
  sequence with `setval(pg_get_serial_sequence(...), COALESCE(MAX(id), 1),
  MAX(id) IS NOT NULL)`. Without this reset, a table can look correct until its
  first post-cutover insert collides with an existing id — the classic
  “hour one” failure.
- **Foreign-key sanity:** target-side `NOT EXISTS` checks cover all declared
  foreign keys and fail if a non-null child key has no parent. The copy
  temporarily sets `session_replication_role` to `replica` so table order does
  not constrain inserts, then restores its original session value even if a
  batch fails. The verifier independently checks the relationships because
  constraints skipped during import are not retroactively rechecked.
- **Target privilege:** `portal:db-copy` requires a PostgreSQL superuser
  connection because it sets `session_replication_role = replica` to make
  table order irrelevant. `SET CONSTRAINTS ALL DEFERRED` is not a substitute:
  Laravel does not create deferrable foreign-key constraints. The Compose
  `postgres:16` service creates its configured `POSTGRES_USER` as a superuser,
  so the default deployment works; demoting that role makes the copy fail.
- **Schema agreement:** every source table and source column must exist on the
  migrated target. A required target-only column must have a default or be
  generated/nullable. A missing target column is an error, never a silently
  dropped field.
- **Value representation:** `tinyint(1)` maps only `0`/`1` to booleans;
  MariaDB zero dates map to `NULL`; JSON and decimal strings are preserved;
  binary/blob bytes are encoded through PostgreSQL `bytea`'s hex input format
  without changing the payload. Existing UUID strings are copied without
  case-folding.

`--verify-only` performs no table or sequence writes. Session-local timezone
settings make timestamp reads deterministic but do not persist in either
database. The backend waits for a Laravel query on only the selected
`DB_CONNECTION`; an unavailable, unselected engine does not block MariaDB
startup before cutover or the MariaDB rollback path. The host cutover script's
backup, switch, lock contention, explicit config-cache override, refused and
acknowledged post-switch rollback, rollback after an operator edits the host
`.env` back to MariaDB, automatic rollback, switch process failure, and
pre-switch rollback lifecycle is exercised against a Docker CLI test double in
CI; the copy itself is tested against real MariaDB and PostgreSQL services.

## Time and collation hazards

MariaDB `TIMESTAMP` values are converted using the connection's session time
zone. A dump or row export made in a host-local zone can shift instants when
imported elsewhere. The backup pins UTC with `mariadb-dump --tz-utc`, and the
copy connection pins MariaDB to `+00:00` and PostgreSQL to `UTC` before reading
or writing timestamps.

MariaDB's production `utf8mb4_unicode_ci` collation is case- and
accent-insensitive and ignores trailing spaces; PostgreSQL text comparisons and
ordinary unique indexes are case-sensitive. The application preserves the
established email identity contract through normalization and a functional
unique index, and coupon codes through V055's tested ICU collation plus
`RTRIM()` unique index. Organization domain identity uses V056's `LOWER(domain)`
lookup and unique expression index, preserving case-insensitive matching. This
is only case-folding parity: it does not reproduce MariaDB's accent-insensitive
or trailing-space equivalence for domains. V055 documents that ICU's Unicode
collation version is an approximation, not byte-for-byte equivalent to
MariaDB's UCA version. The copy itself never case-folds data or merges
duplicate rows. Every other collation-dependent business key must have an
explicit compatibility contract; collisions that remain unhandled block the
copy on schema/value verification or the target unique index rather than
choosing a value silently.

## Explicitly not handled

- Concurrent writes, change-data capture, dual writes, or replay after the
  freeze. Any write that escapes the freeze invalidates the snapshot and
  requires a new backup/copy/verification cycle.
- Schema conversion or data-model decisions. Migrations define PostgreSQL
  types, and incompatible types or missing fields fail the copy.
- Automatic reconciliation of unique-key conflicts, case-only duplicates,
  malformed non-zero dates, or semantically different values. The import does
  not resolve business conflicts or normalize UUID casing.
- Non-database state such as uploaded files, Redis cache contents, search
  indexes, and third-party provider state. Those retain their existing
  lifecycle or must be rebuilt independently.
- Bidirectional rollback after PostgreSQL accepts production writes. The saved
  MariaDB snapshot is not updated from PostgreSQL; after the first post-switch
  write, reverting the environment leaves those new writes on the PostgreSQL
  volume and absent from the active MariaDB dataset. A manual `--rollback`
  therefore requires `--accept-postgres-write-loss` once the switch has been
  attempted (recorded by the `switch-attempted` marker), even if the host `.env`
  has since been edited back to MariaDB; the flag acknowledges that divergence,
  it does not copy data back.
