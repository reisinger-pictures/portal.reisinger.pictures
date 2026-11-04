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
4. Start PostgreSQL and run the Laravel migrations to create its schema.
5. Run `php artisan portal:db-copy`. It copies source base tables in stable
   primary-key batches, omits only Laravel's `migrations` bookkeeping table,
   and uses `insertOrIgnore` so an interrupted copy can be rerun.
6. Run `php artisan portal:db-copy --verify-only` and the production smoke
   queries. The switch is permitted only after every check passes.
7. Atomically edit the protected host `.env` and recreate the backend against
   PostgreSQL. MariaDB stays running and untouched.

Rollback restores the timestamped host `.env`, then explicitly selects
`DB_CONNECTION=mariadb`, `DB_HOST=db`, and `DB_PORT=3306`. It also sets the
queue/cache connection keys to `mariadb`, adding them if an older `.env`
omitted them, before recreating the backend. The host/port keys are added too
when an older `.env` never contained them, so Compose defaults cannot redirect
rollback traffic. The MariaDB data and volume remain the pre-cutover source.
The exact host-side command is:

```bash
ssh root@reisinger.pictures 'bash -s -- --rollback' < deployment/migrate-to-postgres.sh
```

The command copies rows only. Laravel migrations own the PostgreSQL schema;
the copy command does not infer types, create tables, alter columns, or migrate
schema. Source and target columns must agree for copied source data.

## Verification invariants

The verifier returns success only when all of these checks pass:

- **Per-table exact row counts:** every source base table except `migrations`
  must exist on the target with the same count. This detects missing inserts,
  ignored conflicts, and incomplete reruns.
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
database.

## Time and collation hazards

MariaDB `TIMESTAMP` values are converted using the connection's session time
zone. A dump or row export made in a host-local zone can shift instants when
imported elsewhere. The backup pins UTC with `mariadb-dump --tz-utc`, and the
copy connection pins MariaDB to `+00:00` and PostgreSQL to `UTC` before reading
or writing timestamps.

MariaDB's production `utf8mb4_unicode_ci` collation is case-insensitive;
PostgreSQL text comparisons and ordinary unique indexes are case-sensitive.
This can change equality, lookup, and uniqueness behavior after the switch.
The copy does not case-fold data or merge case-only duplicates. Case-only
collisions in unique business keys must be identified and resolved before the
cutover; a row-count or uniqueness failure blocks the switch rather than
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
  write, reverting the environment would discard those new writes.
