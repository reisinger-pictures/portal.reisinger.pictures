# PostgreSQL production cutover

## Target and topology

Production runs PostgreSQL 16 as its sole database. The repository Compose
file no longer contains a MariaDB service or the `db_data` volume; removing the
legacy `portal_db` container and its data on the host is a separate manual gate
(see the open-gate note below). The one-time forward copy script still reads a
MariaDB source for hosts where the switch has not yet been executed; that
source lives in the old host stack, not in this repository's topology.

| Engine | Compose service | Container | Volume | Role |
|---|---|---|---|---|
| PostgreSQL 16 | `db_pg` | `portal_db_pg` | `db_pg_data` | Active database |

The backend's host environment selects `DB_CONNECTION=pgsql`,
`DB_HOST=db_pg`, and `DB_PORT=5432`. Compose defaults `DB_HOST`/`DB_PORT` to
those PostgreSQL coordinates when the host `.env` omits them. The cutover
script still reads the old host `.env` and falls back to the historical MariaDB
defaults (`db`/`3306`) only when reading its source during the one-time copy;
that fallback is not an active connection target. These are not new required
secrets.
The host `.env` must not set a non-empty `DB_URL`: Laravel gives it precedence
over the individual `DB_*` coordinates, so the script refuses before backup if
it is present rather than claim a switch that the live backend did not make.
Remove it and provide `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` before
retrying; an absent or empty `DB_URL` is accepted.

## One-time cutover contract

The copy is a one-time, write-frozen operation, not live replication. It uses a
preparatory deployment followed by the cutover:

1. The repository stack deploys PostgreSQL only, with the backend selecting
   `pgsql` and the `db_pg` coordinates. Where the one-time copy has not yet
   been executed, it runs against the old host stack: the host `.env` there
   still selects MariaDB, and the script confirms `migrate:status` on that
   source has no `Pending` entries before the backup. It refuses to copy a
   source at an older schema frontier.
2. Before downtime, measure free space on the MariaDB data filesystem, the
   backup filesystem, and the PostgreSQL target's local-volume filesystem (or
   Docker's `volumes` filesystem if the target volume has not been created; if
   that directory is absent, use DockerRootDir's filesystem). Each must have at
   least three times the current MariaDB data-directory size free; the script
   refuses before stopping the backend if any measurement fails.
3. Stop application writes and workers. The host runbook script stops the
   backend service before taking the backup and does not modify either
   database until verification succeeds.
4. Create and validate a mode-0600, gzip-compressed MariaDB dump outside the
   web root, and record exact source row counts in a private manifest.
5. Start PostgreSQL and run the Laravel migrations to create its schema. Every
   one-off Artisan process receives `APP_CONFIG_CACHE` pointing at a per-run
   path that does not exist. The database-backed processes (the source
   `migrate:status` preflight, target `migrate`, copy, verify, `migrate:status`,
   and Tinker smoke queries) also receive explicit
   `DB_CONNECTION`/`DB_HOST`/`DB_PORT` and an empty `DB_URL=`. Laravel 13.30
   resolves `Application::getCachedConfigPath()` through `APP_CONFIG_CACHE`;
   the absent path makes the process load `config/` fresh, so a
   `bootstrap/cache/config.php` written before the switch cannot redirect it to
   MariaDB. The live backend's shared config cache is not deleted or modified.
6. Run `php artisan portal:db-copy`. It copies source base tables in stable
   primary-key batches and omits only Laravel's `migrations` bookkeeping table.
   Before copying, it clears the matching target tables so migration-provided
   defaults, partial prior copies, and data left by a rolled-back attempt cannot
   override the frozen MariaDB source. A retry rebuilds those target tables from
   the current source; MariaDB is never modified.
7. Run `php artisan portal:db-copy --verify-only` and the production smoke
   queries. The switch is permitted only after every check passes. The
   verifier compares every `settings` row exactly by `key`, `brand`, and
   `value` (including null values and null brands), not just row counts or
   sums.
8. Atomically edit the protected host `.env` and recreate the backend against
   PostgreSQL. The MariaDB source is not modified; no data is copied back.

### Stable identity for photographer group assignments

`photographer_gallery_groups` intentionally permits a null
`gallery_group_id` after a referenced group is deleted. Its unique
`(user_id, gallery_group_id)` index therefore is not a complete row identity:
SQL unique indexes permit multiple rows with the same user and a null group.
V008 removed the earlier composite primary key to allow that null value, so the
cutover copier cannot safely keyset-chunk this table by the remaining pair.

V060 adds an auto-generated `bigint id` primary key on both MariaDB and
PostgreSQL. Existing rows receive distinct generated IDs; no assignments are
deleted, merged, or rewritten, and the existing nullable group behavior and
composite unique index remain unchanged. Application pivot relations ignore the
surrogate ID. The copy carries it verbatim and resets the PostgreSQL sequence
afterward. If a copy attempt is abandoned, the additive ID remains on the
MariaDB source; no row rewrite and no repository migration change is required.

The cutover takes one exclusive host lock before any preflight check (`flock`
on `/var/lib/portal-postgres-cutover/cutover.lock`). A concurrent run fails
closed with a named error instead of interleaving a second backup, copy, or
environment flip. The lock is released when the process exits.

There is no rollback path in the repository. The script keeps a timestamped
copy of the host `.env` for inspection, sets the PostgreSQL coordinates, and
recreates the backend. If patching, recreation, or the healthcheck fails it
stops with a named `FATAL`; it never restores a previous engine and never
copies data back. An unfinished run leaves
`/var/lib/portal-postgres-cutover/in-progress` behind and blocks a second run.

> **OPEN MANUAL GATE — not checked from this host.** Before removing the legacy
> `portal_db` container and the `db_data` volume on the production host, the
> operator must evaluate the host-side marker
> `/var/lib/portal-postgres-cutover/switch-attempted` (state directory mode 0700,
> marker mode 0600; it records the MariaDB `.env` backup path and survives a
> successful switch) and must have a tested PostgreSQL backup/restore path with
> a completed restore drill. Neither the marker nor the drill is reachable from
> this repository host, and neither has been checked here. A Compose topology
> change requires a `recreate`, not a `restart`; this does not delete the
> legacy volume data. No in-repo command reverts the switch.

The command copies rows only. Laravel migrations own the PostgreSQL schema;
the copy command does not infer types, create tables, alter columns, or migrate
schema. Source and target columns must agree for copied source data.

## PostgreSQL backup and restore precondition

Compose configures neither WAL archiving nor WAL retention for `db_pg`; the
`db_pg_data` volume is not a backup. The ongoing dump schedule lives outside
this repository in `strato-vps` (`proposed/backup.sh`, deployed as
`/usr/local/bin/volume-backup.sh`): Task 1 reads the selector `DB_CONNECTION`
from the host `.env` and runs `pg_dump --clean --if-exists --no-owner --no-acl`
inside `db_pg`, writing `db/portal/portal_db-<DATE>.sql.gz` plus the marker
`db/portal/engine`, which records the engine for the restore. The marker name
is retained from the old dumps; the dumps it describes are PostgreSQL-oriented
(`pg_dump`/`psql`). The cutover script's own gzip dump, checksum, and row
manifest remain a one-time backup of the MariaDB source for this migration;
they are not the ongoing backup.

The second precondition — a tested restore path — is implemented in
`strato-vps/proposed/restore.sh`: stage 4 reads the marker, starts only `db_pg`
on a fresh host, and replays the dump with `psql -v ON_ERROR_STOP=1
--single-transaction`. A drill against real PostgreSQL is the evidence that
counts, not the offline tests: fetch the day's dump from the backup, restore it
into a throwaway database on the running server, and compare row counts and the
table count with the active database, then drop it.

```bash
rclone copyto "pCloud:Backup/strato-v2/db/portal/portal_db-<DATE>.sql.gz" /var/tmp/drill.sql.gz
gzip -t /var/tmp/drill.sql.gz
docker exec portal_db_pg sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d postgres \
  -c "CREATE DATABASE portal_restore_drill"'
gunzip -c /var/tmp/drill.sql.gz | docker exec -i portal_db_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" exec psql -U "$POSTGRES_USER" -d portal_restore_drill \
     -v ON_ERROR_STOP=1 --single-transaction -q'
# compare users/photos/galleries/settings/locations/migrations and the table count
docker exec portal_db_pg sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" \
  -d portal_restore_drill -tAc "select count(*) from information_schema.tables where table_schema=current_schema()"'
docker exec portal_db_pg sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d postgres \
  -c "DROP DATABASE portal_restore_drill"'
```

Measured on 2026-11-22 with `portal_db-2026-11-22.sql.gz` (696 KiB gzip): restore
exit 0; `users/photos/galleries/settings/locations/migrations` =
5/15/1/33/19477/60 in both the drill database and the active `portal_db`; 63
tables in each.

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
  `--chunk` batch bounds memory and query size for this column-parity stream.
  Settings verification is a separate exception: `PostgresCopy::settingRows()`
  materializes every settings row from both databases before comparing them, so
  memory use grows with the settings table. The bounded-memory follow-up is
  tracked in issue #50; the `--chunk` option does not bound this verifier path.
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
`DB_CONNECTION` and rejects a stale non-`pgsql` selection fail-closed. The host
cutover script's backup, switch, lock contention, explicit config-cache
override, and low-target-space and unsupported/mismatched target-volume
refusals before downtime are exercised against a Docker CLI test double in CI;
the copy itself is tested against real MariaDB and PostgreSQL services.

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
unique index, password-reset token identity through V059's `LOWER(email)`
unique expression index, and coupon codes through V057's tested ICU collation
plus `RTRIM()` unique index. Organization domain identity uses V058's
`LOWER(domain)` lookup and unique expression index, preserving case-insensitive
matching. This is only case-folding parity: it does not reproduce MariaDB's
accent-insensitive or trailing-space equivalence for domains. V057 documents
that ICU's Unicode collation version is an approximation, not byte-for-byte
equivalent to MariaDB's UCA version. The copy itself never case-folds data or
merges duplicate rows. Every other collation-dependent business key must have
an explicit compatibility contract; collisions that remain unhandled block the
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
- A rollback path after PostgreSQL accepts production writes. The legacy
  MariaDB source is not updated from PostgreSQL, and the repository no longer
  ships a command that reverts the environment or copies data back. Removing
  the old container and its volume on the host stays an operator decision with
  the open manual gate documented above; the `switch-attempted` marker name is
  retained there because the marker lives in the host state directory, outside
  this repository.
