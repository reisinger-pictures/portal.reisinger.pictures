# AGENTS.md — Backend (Laravel PHP)

Module-scoped operating guidelines for the Laravel backend in `backend/`.

Global rules (Definition of Done, AI workflow & TODO management, E2E tag policy, agent roles, IntelliJ run-config conventions) live in the repo root `AGENTS.md` and apply here as well. The Security Risk Register (accepted risks, resolved C1–C7) is maintained in root `AGENTS.md` §8 — the guards listed there must not regress.

## Commands

Backend tests — **`php` auf dem PATH muss 8.5 oder neuer sein.** `backend/composer.json:12`
verlangt `"php": "^8.5"`, und `backend/vendor/composer/platform_check.php:7` erzwingt das mit
`if (!(PHP_VERSION_ID >= 80500))`.

**Vor dem ersten Befehl prüfen, nicht danach:**

```bash
php -r 'echo PHP_VERSION, PHP_EOL;'   # muss 8.5+ sein, sonst artisan startet nicht
```

Für Skripte ist `scripts/check-php-version.sh` die Fassung zum Aufrufen: es liest die Anforderung
aus `composer.json` (keine zweite Kopie der Zahl), prüft zusätzlich ein verwaistes
`auto_prepend_file` und bricht mit einer klaren Meldung ab. `scripts/e2e-up.sh` ruft es in
Schritt 0 auf, also **bevor** irgendein Container angefasst wird — gemessen am 2026-09-28 starb
das Skript sonst erst in Schritt 3, und der Playwright-Lauf meldete daraufhin
`net::ERR_CONNECTION_REFUSED` für Specs, die völlig in Ordnung waren.

**Verweist `php` auf eine falsche Version, sieht der Fehler aus wie ein Abhängigkeitsproblem
und ist keiner.** Jeder `php artisan`-Aufruf bricht dann ab mit
`Composer detected issues in your platform` — ein Fatal in
`vendor/composer/autoload_real.php`, also genau dort, wo ein kaputtes `vendor/` oder eine
fehlgeschlagene Installation vermutet wird. Die wahrscheinlichste Ursache ist trotzdem nur
die PHP-Version. `composer install` löst das nicht, es erzeugt den Fehler erst.

macOS (Homebrew) — `php` wird via Homebrew installiert und liegt unter `/opt/homebrew/bin/php`:

```bash
cd backend && php artisan test
```

Windows (Git Bash) — derselbe Zweck, anderer Pfad:

```bash
export PATH="/c/Users/flori/.config/herd/bin/php85:$PATH"
cd backend && php artisan test
```

Verlässlicher als ein `export` ist der Aufruf über das Binary selbst, wenn das `export`
vergessen wurde: `/opt/homebrew/bin/php artisan test` aus `backend/`.

**Belegt 2026-09-28:** `php` zeigte auf 8.4.25, `php85` auf 8.5.10. Mit dem 8.4-Shim starzte
weder `php artisan --version` nor `scripts/e2e-up.sh`; der Fehler dort lautete
`Composer detected issues in your platform`, und die E2E-Suite schlug daraufhin mit
`net::ERR_CONNECTION_REFUSED` fehl, weil das Backend gar nicht hochkam. Zwei Ebenen der
Fehlermeldung, eine Ursache.

Backend Formatting — Pint (STRICT, CI-Gate seit 2026-09-26):

```bash
cd backend && ./vendor/bin/pint          # formatiert um
cd backend && ./vendor/bin/pint --test   # prüft nur, schreibt nichts (CI-Modus)
```

`pint --test` läuft als eigener Step im `Backend (PHPUnit)`-Job und lässt den
Build bei Formatverstößen fehlschlagen. Im Gegensatz zum Frontend gibt es hier
kein `--fix` als Auto-Fix-Policy für den Agenten: Pint **ist**
der Auto-Fix. Verstöße werden **nicht** von Hand korrigiert, sondern mit
`./vendor/bin/pint` behoben und committet. Beim Anlegen des Gates wurde die
gesamte Codebasis einmal normalisiert (165 Dateien), das ist die Baseline.

## Database Setup Policy (STRICT)

Nach `php artisan migrate:fresh` MUSS `php artisan db:seed` (oder `--seed` Flag) ausgeführt werden. Ohne Seed existiert kein Admin-User — Login und Auth sind tot. Der `DatabaseSeeder` legt den Admin via `firstOrCreate` mit `ADMIN_EMAIL`/`ADMIN_PASSWORD` an. `composer setup` ist kein Config-Wizard: Es kopiert eine fehlende `.env` nur ungefragt und ruft am Ende `migrate --force --seed` auf; `ADMIN_EMAIL` und ein nicht leeres `ADMIN_PASSWORD` müssen deshalb vor dem Setup gesetzt sein.

**Lokale Dev-DB:** SQLite-Datei `backend/database/database.sqlite` (gitignored, leer). Kein DB-Container nötig — `php artisan migrate:fresh --seed` erstellt das Schema direkt in dieser Datei.

**Settings-Autorität des Seeders (STRICT):** Der `DatabaseSeeder` ist für die 28 von ihm deklarierten `settings`-Zeilen (`price_*`, `mult_*`, `term_*`, `calc_*`, `base_price`, `setup_fee`, `privacy_fee`, `extra_image_fee`, `bank_*`, `company_*`) **autoritativ** — er schreibt per `upsert` auf den zusammengesetzten Primary Key `(key, brand)` seinen eigenen Wert. Ein `php artisan db:seed` **überschreibt damit in Produktion diese 28 Keys**; über die Oberfläche geänderte Produktionswerte werden von einem Seed **nicht** erhalten. Das ist eine bewusste Owner-Entscheidung (2026-09-27). Vor `db:seed` in Produktion deshalb prüfen, ob betroffene Werte abweichen — und **auch vor `php artisan app:seed-if-fresh`**, denn es ruft denselben Seeder auf, nur eben nicht unbedingt. Keys außerhalb dieser Liste (z. B. `term_editorial`, `term_commercial`, `term_1_year`, `term_unlimited`) legt der Seeder weiterhin nur an, wenn sie fehlen. Grund der Änderung: `insertOrIgnore` übersprang jede bereits von einer Migration angelegte Zeile stillschweigend — u. a. `base_price` (`V004__ecommerce_and_governance.php:131` legte die Zeile als **35,00 Euro** an, der Seeder schreibt **8000 Cent** in denselben Key, `DatabaseSeeder.php:165`), was den Rechner-Speichern mit 422 scheitern ließ. Die Einheitenregel für alle Geldfelder steht in `features/tech/02-backend-architecture.md` § 4, das Felderinventar mit Belegen in `features/infrastructure/28-settings-key-meaning.md`.

**`app:seed-if-fresh` — der Seed beim Container-Start (STRICT, 2026-09-28):** `deployment/docker-compose.yml:267` führt `php artisan migrate --force && php artisan app:seed-if-fresh && php artisan admin:update` aus. **`app:seed-if-fresh` ist die Automatik für die Erstinstallation und läuft nur, wenn die `users`-Tabelle leer ist** — ein Neustart seedet eine laufende Produktion **nicht** mehr. Vorher stand dort `db:seed --force` unbedingt; da `AGENTS.md` §13 nach jedem Sync mit PHP-Änderungen `docker restart portal_backend` vorschreibt, überschrieb jeder vorgeschriebene Neustart die 28 autoritativen Keys — ohne dass ein Mensch den Seed ausgelöst hätte, also auch ohne die Vorprüfung oben. Bei einem echten Deploy am 2026-09-28 entdeckt.

Das Freshness-Signal ist bewusst die leere `users`-Tabelle und **nicht** eine leere `settings`-Tabelle: ohne Seed existiert kein Admin-User und der Login ist tot (`backend/AGENTS.md` oben) — das ist die Invariante, auf die der Start angewiesen ist. Eine leere `settings`-Tabelle ist kein brauchbares Signal, weil Migrationen dort bereits Zeilen anlegen (V004 `base_price`/`term_*`, V005 die Bank-Keys); keine Migration legt dagegen einen User an (V004 und V018 aktualisieren nur bestehende User-Zeilen). Die Reihenfolge im Entrypoint ist Teil des Vertrags: `app:seed-if-fresh` läuft **vor** `admin:update`, sonst wäre der Admin aus `admin:update` selbst das Signal und der Erstinstallations-Seed entfiele.

Ein bewusster Seed in Produktion bleibt möglich und ist dann die ausschließlich manuelle Variante: `php artisan db:seed --force`, mit der Vorprüfung aus dem Absatz darüber. Bekannte Grenze: bricht ein Seed **nach** dem Anlegen des Admin-Users ab, ist `users` beim nächsten Start nicht mehr leer und `app:seed-if-fresh` überspringt die Datenbank — Abhilfe ist das manuelle `db:seed --force`, der Seeder ist idempotent (`upsert` plus `firstOrCreate`). Vertrag getestet in `backend/tests/Feature/SeedIfFreshCommandTest.php`: frische DB wird geseedet, geseedete DB bleibt unangetastet (der umgekehrte `settings`-Wert überlebt), zweiter Lauf ist ein No-op, ein werfender Seed endet mit Exit-Code 1, damit die `|| exit 1`-Kette des Entrypoints den Start verweigert.

**Migration-Regel (STRICT):** Bei JEDER Migration gilt: **immer seeden**, nie nur migrieren. `php artisan migrate` (bzw. `migrate:fresh`) allein reicht nicht — anschließend IMMER `php artisan db:seed` (oder `--seed` Flag) ausführen, sonst funktioniert das Anmelden (Login/Auth) nicht, weil kein Admin-User existiert.

**E2E Location Fixtures:** `DatabaseSeeder` remains network-free and does not load E2E locations. Fresh E2E databases use the explicit `E2ELocationSeeder` plus the Scout location-index commands from `scripts/e2e-up.sh` or `.github/workflows/ci.yml`. Never call `app:import-locations` from the standard seed or production startup; production keeps its weekly, locked scheduler.

**Migration Policy (CRITICAL):** Der aktuelle Migrations-Stand steht **ausschließlich** in `features/tech/07-architectural-decisions.md` (AD-2) und wird dort gegen Repository **und** Produktion gemessen. Diese Datei führt ihn absichtlich nicht — eine hier abgeschriebene Zahl veraltet stillschweigend und lädt zu einer doppelten Migrationsnummer ein. Eine bereits deployte Migration bleibt unverändert. Eine neue Migration ist nur zulässig, wenn die Anforderung mit bestehenden Tabellen, Indizes und Job-Verträgen nicht sicher erfüllbar ist; vorab ist die konkrete Schema-/Backfill-/Rollback-Entscheidung zu dokumentieren. **`down()`-Methoden werden nie ausgeführt und können als Regel leer gelassen werden** (etabliert 2026-08-03).

## Backend Parallel Testing (PHP) — SQLite `:memory:` (2026-08-07)

`paratest` ist installiert (`brianium/paratest`). Die Tests laufen via `phpunit.xml` vollständig auf **SQLite `:memory:`** — kein DB-Container, keine MariaDB-Grants, keine Worker-DBs:

- Canonical Test-DB: SQLite `:memory:` (aus `phpunit.xml`).
- `php artisan test --parallel` funktioniert out-of-the-box: jeder paratest-Worker-Prozess startet eine eigene, isolierte In-Memory-DB. Es existiert keine geteilte Instanz, auf der sich parallele Läufe gegenseitig zerstören können.

**File-backed SQLite-Tuning (Dev + E2E):** Die vier Tuning-Keys der `sqlite`-Verbindung in `config/database.php` (`busy_timeout`, `journal_mode`, `synchronous`, `transaction_mode`, je per `DB_*`-Env übersteuerbar) wirken **nur auf dateibasierte DBs** — auf `:memory:` ist `journal_mode=WAL` still ein No-op und bleibt `memory`. Sie existieren für die lokale Dev-DB und die E2E-DB (`scripts/e2e-up.sh`). **Der eigentliche Fix gegen `database is locked` ist `transaction_mode=IMMEDIATE`:** eine `DEFERRED`-Transaktion, die erst liest und dann schreibt, bekommt beim Lock-Upgrade `SQLITE_BUSY`, ohne dass der Busy-Handler gefragt wird — dagegen helfen weder `busy_timeout` noch WAL. Abgesichert durch `tests/Unit/Support/SqliteConnectionTuningTest.php`.

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
