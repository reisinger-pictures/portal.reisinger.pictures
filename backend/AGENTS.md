# AGENTS.md — Backend (Laravel PHP)

Module-scoped operating guidelines for the Laravel backend in `backend/`.

Global rules (Definition of Done, AI workflow & TODO management, E2E tag policy, agent roles, IntelliJ run-config conventions) live in the repo root `AGENTS.md` and apply here as well. The Security Risk Register (accepted risks, resolved C1–C7) is maintained in root `AGENTS.md` §8 — the guards listed there must not regress.

## Commands

Backend tests (PHP via Herd — PATH muss das PHP-Binary enthalten):

```bash
export PATH="/c/Users/flori/.config/herd/bin/php85:$PATH"
cd backend && php artisan test
```

## Database Setup Policy (STRICT)

Nach `php artisan migrate:fresh` MUSS `php artisan db:seed` (oder `--seed` Flag) ausgeführt werden. Ohne Seed existiert kein Admin-User — Login und Auth sind tot. Der `DatabaseSeeder` legt den Admin via `firstOrCreate` mit `ADMIN_EMAIL`/`ADMIN_PASSWORD` an. `composer setup` ist kein Config-Wizard: Es kopiert eine fehlende `.env` nur ungefragt und ruft am Ende `migrate --force --seed` auf; `ADMIN_EMAIL` und ein nicht leeres `ADMIN_PASSWORD` müssen deshalb vor dem Setup gesetzt sein.

**Lokale Dev-DB:** SQLite-Datei `backend/database/database.sqlite` (gitignored, leer). Kein DB-Container nötig — `php artisan migrate:fresh --seed` erstellt das Schema direkt in dieser Datei.

**Migration-Regel (STRICT):** Bei JEDER Migration gilt: **immer seeden**, nie nur migrieren. `php artisan migrate` (bzw. `migrate:fresh`) allein reicht nicht — anschließend IMMER `php artisan db:seed` (oder `--seed` Flag) ausführen, sonst funktioniert das Anmelden (Login/Auth) nicht, weil kein Admin-User existiert.

**E2E Location Fixtures:** `DatabaseSeeder` remains network-free and does not load E2E locations. Fresh E2E databases use the explicit `E2ELocationSeeder` plus the Scout location-index commands from `scripts/e2e-up.sh` or `.github/workflows/ci.yml`. Never call `app:import-locations` from the standard seed or production startup; production keeps its weekly, locked scheduler.

**Migration Policy (CRITICAL):** Bei jeder Migration muss der Agent vorher nachfragen, ob die Änderung als **neue, separate Migration** oder als **Erweiterung der aktuell letzten Migration** erfolgen soll. **V038 ist die aktuelle Repository-Frontier; V037 ist die vorhergehende Guest-Ownership-Migration, V036 bleibt der Card-Testing-Migrationsstand, und V035 ist laut aktuellem Deployment-Status die zuletzt deployte Migration.** Neue Schema-Änderungen erfolgen als separate Migrationen ab V039. **`down()`-Methoden werden nie ausgeführt und können als Regel leer gelassen werden** (etabliert 2026-08-03).

## Backend Parallel Testing (PHP) — SQLite `:memory:` (2026-08-07)

`paratest` ist installiert (`brianium/paratest`). Die Tests laufen via `phpunit.xml` vollständig auf **SQLite `:memory:`** — kein DB-Container, keine MariaDB-Grants, keine Worker-DBs:

- Canonical Test-DB: SQLite `:memory:` (aus `phpunit.xml`).
- `php artisan test --parallel` funktioniert out-of-the-box: jeder paratest-Worker-Prozess startet eine eigene, isolierte In-Memory-DB. Es existiert keine geteilte Instanz, auf der sich parallele Läufe gegenseitig zerstören können.

**KONKURRENZ-REGEL (STRICT, Subagenten):**

- `RefreshDatabase` migriert die In-Memory-DB bei jedem PHPUnit-Prozessstart frisch. SQLite `:memory:` macht parallele Läufe von Natur aus isoliert — Kollisionen durch Tabellen-Drop auf einer geteilten DB sind ausgeschlossen.
- Trotzdem: Die volle Suite läuft zur Reproduzierbarkeit IMMER NUR in EINEM Subagenten (einmal).
- Scoped-Runs (`--filter`) sind ohne Worker-DB-Setup direkt möglich — kein `DB_DATABASE=`-Präfix und kein `docker exec ... mariadb` mehr nötig:

```bash
php artisan test --filter <TestClass>
```

**PHP Group-Strategy (noch nicht implementiert):**

1. Tests mit `@group=smoke` taggen für schnelle Regression
2. Nur Feature-Tests bei Feature-Arbeit: `php artisan test --testsuite=Feature --parallel`
