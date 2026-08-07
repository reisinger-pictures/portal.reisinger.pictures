# AGENTS.md — Backend (Laravel PHP)

Module-scoped operating guidelines for the Laravel backend in `backend/`.

Global rules (Definition of Done, AI workflow & TODO management, E2E tag policy, agent roles, IntelliJ run-config conventions) live in the repo root `AGENTS.md` and apply here as well. The Security Risk Register (accepted risks, resolved C1–C7) is maintained in root `AGENTS.md` §7 — the guards listed there must not regress.

## Commands

Backend tests (PHP via Herd — PATH muss das PHP-Binary enthalten):

```bash
export PATH="/c/Users/flori/.config/herd/bin/php85:$PATH"
cd backend && php artisan test
```

## Database Setup Policy (STRICT)

Nach `php artisan migrate:fresh` MUSS `php artisan db:seed` (oder `--seed` Flag) ausgeführt werden. Ohne Seed existiert kein Admin-User — Login und Auth sind tot. Der `DatabaseSeeder` legt den Admin via `firstOrCreate` mit `ADMIN_EMAIL`/`ADMIN_PASSWORD` an.

**Migration-Regel (STRICT):** Bei JEDER Migration gilt: **immer seeden**, nie nur migrieren. `php artisan migrate` (bzw. `migrate:fresh`) allein reicht nicht — anschließend IMMER `php artisan db:seed` (oder `--seed` Flag) ausführen, sonst funktioniert das Anmelden (Login/Auth) nicht, weil kein Admin-User existiert.

**Migration Policy (CRITICAL):** Bei jeder Migration muss der Agent vorher nachfragen, ob die Änderung als **neue, separate Migration** oder als **Erweiterung der aktuell letzten Migration** erfolgen soll. **V027 ist die letzte (deploy-bereite) Migration** (V025–V026 wurden 2026-08-04 deployed; V027 verifiziert in `portal_test_db`). Neue Schema-Änderungen erfolgen daher als separate Migrationen ab V028. **`down()`-Methoden werden nie ausgeführt und können als Regel leer gelassen werden** (etabliert 2026-08-03).

## Backend Parallel Testing (PHP) — EINRICHTUNG AKTIV (2026-08-03)

`paratest` ist installiert (`brianium/paratest`). Die Test-Infra ist parallel-fähig:

- Canonical Test-DB: `portal_test_db` (MariaDB :3307, aus `phpunit.xml`).
- Worker-DBs: `php artisan test --parallel` legt pro Prozess automatisch `portal_test_db_test_<n>` an. `portal_user` hat die Wildcard-Grants `ON portal\_test\_db\_test\_%` + CREATE-Recht (verifiziert 2026-08-03).

**KONKURRENZ-REGEL (STRICT, Subagenten):**

- `RefreshDatabase` leert und re-migriert die DB bei jedem PHPUnit-Prozessstart. Mehrere gleichzeitige `php artisan test`-Läufe auf DERSELBEN DB kollidieren (Tabellen-Drop im Parallelbetrieb) → Kaskaden-Fehler.
- Die volle Suite läuft IMMER NUR in EINEM Subagenten (einmal, canonical DB).
- Subagenten, die Backend-Tests ausführen müssen, nutzen für Scoped-Runs (`--filter`) eine eigene Worker-DB, z. B.:

```bash
DB_DATABASE=portal_test_db_test_<task> php artisan test --filter <TestClass>
```

  (Anlegen der Worker-DB vorab via `docker exec portal_db_test mariadb ...`; der Name MUSS `portal_test_db_test_%` matchen, damit der Grant greift.)

**PHP Group-Strategy (noch nicht implementiert):**

1. Tests mit `@group=smoke` taggen für schnelle Regression
2. Nur Feature-Tests bei Feature-Arbeit: `php artisan test --testsuite=Feature --parallel`
