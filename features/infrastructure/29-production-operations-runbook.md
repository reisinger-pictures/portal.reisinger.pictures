---
domain: infrastructure
topic: production-operations
status: active
---

# Production Operations Runbook — Queue, Mail, Worker, and Scheduler

## Scope and evidence boundary

This runbook defines the production process topology for the existing Docker
stack. It does not claim that a live deployment, SMTP provider, worker health
probe, or scheduler run has been observed. Static policy tests and shell/PHP
syntax checks are repository evidence only; operators must collect the runtime
evidence listed below on the target host.

The current deployment uses the provisioned MariaDB service. It does **not**
introduce Redis, Horizon, Supervisor, or another external process manager.
The shell supervisor in `deployment/backend-supervisor.sh` is intentionally
small and runs inside the existing PHP-FPM container as UID/GID `1000:1000`.
The base-image workflow rebuilds the image when that script or the raw preflight
script changes; refreshing the digest consumed by the deployment compose file
remains a release/rollout step and is not asserted by this repository change.
The compose bootstrap also fails closed if an old image does not contain the
executable preflight and supervisor binaries.

## 1. Required production configuration

The following values are passed through `deployment/docker-compose.yml` and
must be supplied by the host/Portainer environment. The raw-value preflight
(`/usr/local/bin/validate-production-env`) runs before any Laravel command, so
whitespace, fractional numbers, invalid topology, and a missing supervisor
cannot be hidden by Laravel casts or reach migration/seed. The effective
configuration gate (`php artisan ops:validate-production`) then runs before
migrations, queue workers, the scheduler, or PHP-FPM are started.

| Variable | Required value/constraint |
|---|---|
| `APP_ENV` | `production` for the production policy to be active |
| `DB_CONNECTION` | Explicit application connection, currently `mariadb` |
| `DB_QUEUE_CONNECTION` | Exactly the same value as `DB_CONNECTION` |
| `QUEUE_CONNECTION` | Exactly `database` |
| `DB_QUEUE_RETRY_AFTER` | Raw positive integer (no whitespace, no decimal point); currently `90` seconds |
| `QUEUE_WORKER_TIMEOUT` | Raw positive integer, strictly less than `DB_QUEUE_RETRY_AFTER`; currently `60` seconds |
| `QUEUE_WORKER_RESTART_DELAY` | Raw positive integer used between worker restarts; currently `5` seconds |
| `QUEUE_FAILED_DRIVER` | `database-uuids` |
| `CACHE_STORE` | A shared store; the current stack uses `database` |
| `DB_CACHE_CONNECTION` | For the current database cache, exactly `DB_CONNECTION` |
| `DB_CACHE_LOCK_CONNECTION` | For the current database cache, exactly `DB_CACHE_CONNECTION` |
| `MAIL_MAILER` | Exactly `smtp` |
| `MAIL_SCHEME` | Exactly `smtp` (STARTTLS) or `smtps` (implicit TLS) |
| `MAIL_REQUIRE_TLS` | Exactly `true`; Symfony must fail if TLS is unavailable |
| `MAIL_HOST` | Non-empty SMTP host without whitespace |
| `MAIL_PORT` | Raw integer from `1` through `65535` |
| `MAIL_USERNAME` | Non-empty SMTP username/API key |
| `MAIL_PASSWORD` | Non-empty SMTP password/API key |
| `MAIL_FROM_ADDRESS` | Valid, non-placeholder sender address |
| `MAIL_FROM_NAME` | Non-empty sender name |

The same database queue connection is a correctness boundary, not merely a
performance setting. Invoice and cleanup dispatches use transactional database
writes. A queue connection that points at a second database breaks the
claim/queue transaction boundary and is rejected.

The local and CI fixtures intentionally remain different:

- `backend/.env.example` uses `QUEUE_CONNECTION=sync` and a local database
  cache/queue connection.
- `backend/.env.ci` and `phpunit.xml` use synchronous jobs; CI does not start a
  production worker or scheduler.
- Mailpit is a local/CI SMTP sink, configured with `MAIL_SCHEME=smtp` and
  `MAIL_REQUIRE_TLS=false`. Its lack of production credentials and TLS does not
  weaken the production gate because the policy is production-only; the
  production-only `false` for `MAIL_REQUIRE_TLS` would fail the gate.

## 2. Startup and worker supervision

The production compose command performs the existing identity, credential, and
path checks, then follows this order **before any migration or seed**:

1. verify that the pinned image contains the executable raw preflight and
   supervisor binaries;
2. run `/usr/local/bin/validate-production-env` against the raw environment;
3. clear/optimize Laravel configuration;
4. run `php artisan ops:validate-production` against the effective Laravel and
   Symfony transport configuration;
5. run migrations, seed, and admin provisioning;
6. start Scout settings, the queue restart signal, and the supervisor.

Neither the preflight nor the supervisor binary is present in the currently
pinned GHCR digest. Until the base image is rebuilt, pushed, and the compose
digest is updated, startup stops at step 1 with an explicit rebuild/digest
message; no migration, seed, or worker is started. This repository does not
claim that the pinned image is deployable.

`backend-supervisor.sh` performs these actions:

1. re-validates the database queue connection, worker timeout, retry-after, and restart-delay environment values already checked by the preflight;
2. removes stale PID markers at process start;
3. starts `php artisan queue:work` with the configured timeout and three tries;
4. records the supervisor and worker PIDs under `/tmp`;
5. restarts the worker after an exit, with a bounded delay;
6. runs `php artisan schedule:run` in a persistent loop and records its PID;
7. leaves PHP-FPM in the foreground as PID 1 via `exec php-fpm -F`.

The container health check requires PHP-FPM plus the queue supervisor, queue
worker, and scheduler PID markers to exist and to accept `kill -0`. A worker
that exits therefore makes the health contract fail while the supervisor
attempts its restart; a stale PID from a previous container cannot pass because
the markers are removed at startup.

### Operator checks after a deploy

Run these from the repository root on the target host and retain the output as
runtime evidence:

```bash
docker compose --project-directory "$PWD" -f deployment/docker-compose.yml ps
docker inspect --format '{{.State.Health.Status}}' portal_backend
docker logs --since=10m portal_backend
```

Re-running the two gates inside the running container is path-correct and
orders them the same way the startup path does (raw values first, effective
Laravel/Symfony configuration second):

```bash
docker exec portal_backend /usr/local/bin/validate-production-env
docker exec portal_backend php artisan ops:validate-production
```

Then inspect the health process markers without printing secrets:

```bash
docker exec portal_backend sh -c '
  for marker in /tmp/portal-queue-supervisor.pid /tmp/portal-queue-worker.pid /tmp/portal-scheduler.pid; do
    test -s "$marker" || exit 1
    pid=$(cat "$marker")
    kill -0 "$pid" || exit 1
    printf "%s %s\\n" "$marker" "$pid"
  done
'
```

A green repository test is not a substitute for these checks. No SMTP or
worker-delivery success should be inferred from a healthy process alone.

## 3. Queue recovery and failure handling

- Inspect terminal jobs in `failed_jobs` using the existing UUID-aware failed
  job tooling; do not edit queue rows manually without a reviewed recovery
  plan.
- A queue/SMTP failure can cause an at-least-once delivery attempt. The
  database queue and mail claim prevent a new automatic enqueue after a
  terminal invoice failure, but they do not provide exactly-once SMTP delivery.
- If the queue worker is repeatedly restarting, stop rollout activity and
  preserve the container logs, failed-job rows, and the relevant environment
  names. Do not paste passwords or API keys into tickets.
- `queue:restart` remains part of the normal deploy path. The supervisor
  starts a fresh worker after that signal rather than leaving an unbounded
  one-shot background process.

## 4. Mail operations

Production mail is an SMTP operation. The gate requires an explicit mailer,
SMTP endpoint, `MAIL_SCHEME=smtp|smtps`, `MAIL_REQUIRE_TLS=true`,
credentials, and sender identity before the container is allowed to start.
For `smtp`, Symfony's `require_tls` setting makes a missing STARTTLS upgrade a
failure; for `smtps`, TLS is implicit. The gate validates configuration and the
constructed transport contract; it does not prove that the provider accepted a
message.

For a controlled post-deploy check, use the application's existing test-mail
path and verify the provider's own delivery/logs. A Mailpit result is local or
CI evidence only and must not be reported as production SMTP evidence.

On an SMTP incident:

1. check the worker and `failed_jobs` state first;
2. verify only non-secret configuration names and the provider status;
3. do not blindly re-enqueue invoice mail, because the durable claim is
   intentionally at-most-once for automatic dispatch;
4. document any operator-approved manual recovery separately.

## 5. Scheduler and shared cache

All current scheduled events use both `withoutOverlapping()` and
`onOneServer()`. `onOneServer()` is only meaningful when the scheduler mutex
store is shared by all backend replicas. The deployment therefore uses the
existing MariaDB cache store by default and sets `DB_CACHE_CONNECTION` to the
same application database connection. `file` and `array` stores are not
acceptable for the production `onOneServer` path.

The policy command is intentionally conservative but does not provision a new
cache service. If a future deployment selects Redis or Memcached, the operator
must provision that service, configure all replicas consistently, update the
runbook, and retain the static policy test. Merely naming a shared driver does
not constitute runtime evidence.

A scheduler command failure is logged and retried on the next one-minute loop;
it must not be hidden by redirecting all output to `/dev/null`. The health
check verifies the scheduler loop process, while command-level success still
requires the target host's scheduler logs and job-specific evidence.

## 6. Rollback and release evidence

A rollback must restore the same production topology contract. Do not roll
back to a compose file that starts a naked background `queue:work`, uses a
`file` scheduler mutex store, uses the ignored `MAIL_ENCRYPTION` key, or
silently falls back to `log` mail. If the
application code is rolled back, retain the operations policy and supervisor
or provide an explicitly reviewed replacement before restarting the stack.

The repository checks for this runbook are:

```bash
# From the repository root
bash -n deployment/backend-supervisor.sh
bash -n deployment/validate-production-env.sh
(cd backend && php artisan test --filter ProductionOperationsPolicyTest)
(cd backend && php -l app/Support/ProductionOperationsPolicy.php)
(cd backend && php -l app/Console/Commands/ValidateProductionOperations.php)
(cd backend && php -l config/mail.php)
(cd backend && ./vendor/bin/pint --test \
  app/Support/ProductionOperationsPolicy.php \
  app/Console/Commands/ValidateProductionOperations.php \
  config/mail.php \
  tests/Feature/ProductionOperationsPolicyTest.php)
git diff --check
```

These commands validate source and policy only. The base-image GHCR rebuild and
push, the compose digest update, and live queue, SMTP, scheduler, health, and
provider evidence remain external release/runtime work.
