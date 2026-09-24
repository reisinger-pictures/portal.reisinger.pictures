# Environment File & Secret Hardening

## Status: Active (reviewed 2026-09-24)

## Motivation

Das Repository soll öffentlich werden (Going-Public). Bis Session 2026-07-21
waren reale Secrets in getrackten Dateien committed:

- `frontend/.env` enthielt einen **Live**-Stripe-Publishable-Key (`pk_live_…`).
- `frontend/.env.local` enthielt einen **Test**-Stripe-Publishable-Key (`pk_test_…`).
- `backend/.env.testing` enthielt einen **Test**-Stripe-Secret-Key (`sk_test_…`)
  sowie DB-/Meilisearch-Credentials.
- `backend/config/services.php` enthielt einen hardcoded `sk_test`/`pk_test`-Fallback
  im Sourcecode.

Wurzelursache für die Frontend-Lecks: `frontend/.gitignore` enthielt die
Negationen `!.env` und `!.env.local`, die Git zwangen, die echten Env-Dateien
zu tracken und damit das korrekte Ignore-Pattern im Root-`.gitignore`
übersteuerten.

## SOLL-Zustand

### Admin-Identität (S5b, 2026-08-13)

Die aktuelle Runtime-Admin-Konfiguration ist vollständig env-getrieben:
`backend/config/admin.php` liest `ADMIN_EMAIL` und `ADMIN_PASSWORD` ohne
Runtime-Fallback. `admin@example.com` darf nur als expliziter Wert in
lokalen/CI-/E2E-Fixtures oder im `.env.example` vorkommen; es ist kein aktueller
Produktionsdefault. Es gibt keine hartcodierte persönliche E-Mail im aktuellen
Runtime-Code. Historische Migrations-Fixtures können einen generischen Wert
enthalten, sind aber kein Config-Fallback für Seeder oder `admin:update`.
Produktion setzt die reale E-Mail und das Passwort ausschließlich über
Portainer/`.env`.

### Getrackte Dateien (committed)

Die ausdrücklich erlaubten Dateien sind nicht alle Starter-Templates:

| Datei                             | Klassifizierung und Zweck                              |
| --------------------------------- | ------------------------------------------------------ |
| `backend/.env.example`            | Dev-Template mit Platzhaltern (APP, DB, Mail, Scout, Stripe, AI, JWT, File Encryption) |
| `backend/.env.ci`                 | **CI-only Fixture**: wird von den Workflows als `.env` verwendet; enthält Test-/Platzhalterwerte, keine Produktionskonfiguration und kein Developer-Setup |
| `backend/.env.encrypted`          | **Verschlüsselter Blob/Backup**, kein Template und keine Starterlaubnis; nicht ungeprüft kopieren, entschlüsseln oder in einen lokalen `.env` übernehmen |
| `frontend/.env.example`           | Frontend-Default-Template (`VITE_STRIPE_PUBLIC_KEY` Platzhalter) |
| `frontend/.env.local.example`     | Frontend-Local-Dev-Template (override `.env`)          |

Die beiden Backend-Dateien `.env.ci` und `.env.encrypted` sind bewusste
Allowlist-Ausnahmen im Root-`.gitignore`; ihre Existenz bedeutet **nicht**, dass
sie als lokales Entwicklungs- oder Produktions-Setup verwendet werden dürfen.

### Ungetrackte Dateien (lokal auf Disk, NICHT committed)

Diese Dateien können reale Secrets enthalten und stehen nur auf der lokalen Disk
bzw. werden im Deployment injiziert:

- `backend/.env`, `backend/.env.local`, `backend/.env.production` etc.
- `frontend/.env`, `frontend/.env.local`
- `backend/storage/*.key`

`.env.ci` und `.env.encrypted` sind davon ausgenommen: Beide sind tracked, aber
mit der jeweils oben beschriebenen CI-/Backup-Klassifizierung.

### Test-Fixtures

**Kanonischer PHPUnit-Testbetrieb:** `backend/phpunit.xml` setzt
`DB_CONNECTION=sqlite` und `DB_DATABASE=:memory:`. Die dort gesetzten
localhost-Service-Fixtures (`127.0.0.1:7701`, `test_meili_secret`, Mail-Port
`1025` usw.) sind Test-Konfiguration und keine MySQL/MariaDB-Datenbank.

`backend/.env.ci` ist ein **getracktes CI-Setup** und enthält in seinem
allgemeinen Abschnitt MariaDB-Werte für den separaten E2E-Servicebetrieb. Der
Backend-/PHPUnit-CI-Job kopiert die Datei als `.env`, überschreibt aber die
Datenbank für PHPUnit durch `phpunit.xml` auf SQLite `:memory:`; der E2E-Job
passt die Werte separat auf seine MariaDB-/Meilisearch-/Mailpit-Service-Namen
an. Die alten `127.0.0.1:3307`-/MariaDB-Angaben dürfen daher nicht als
PHPUnit-Standard beschrieben werden. Siehe `AGENTS.md` §8 (Security Risk
Register).

### Code-Fallbacks

`backend/config/services.php` hat **keinen** Stripe-Test-Key-Fallback mehr.
`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` sind verpflichtend über
Env-Variablen zu setzen. Tests verwenden `Config::set(...)` mit Mock-Werten.

## File Encryption at Rest (2026-09-19)

Sensible Model-Dateien (**Altersnachweis** + **Personen-Fotos**) werden
verschlüsselt auf der privaten `local`-Disk abgelegt, ebenso der
`model_profiles.answers`-Snapshot in der DB (Laravel `encrypted:array`).
Such-/Filterfelder (`gender`, `customer.city`, `birthdate`) bleiben plaintext.

- **Mechanismus Dateien:** AES-256-GCM, chunked streaming (64 KB Default) über
  das MIT-Paket `ercsctt/laravel-file-encryption` (`FileEncrypter`-Facade).
  Kein Voll-Load in Memory; pro Chunk eigener Nonce + GCM-Auth-Tag, Header-HMAC.
- **Eigener Key (NICHT `APP_KEY`):** `FILE_ENCRYPTION_KEY`, Format
  `base64:<32 Byte>`. Generieren:
  `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
- **Key-Rotation:** Neuen Key als `FILE_ENCRYPTION_KEY` setzen, den alten Key
  kommagetrennt in `FILE_ENCRYPTION_PREVIOUS_KEYS` übernehmen. Beim Entschlüsseln
  werden Primary + Previous-Keys der Reihe nach probiert; ein falscher Key
  scheitert am GCM-Auth-Tag (`DecryptException`). Altdaten lassen sich bei Bedarf
  mit `php artisan file:decrypt`/`file:encrypt` re-encrypten.
- **Tests:** `phpunit.xml` setzt einen deterministischen, nicht-geheimen
  Test-Key; `backend/.env.ci` enthält einen separaten CI-Test-Key. Diese beiden
  Dateien sind Test-Fixtures, keine Produktionsschlüssel. `backend/.env.example`
  enthält nur den leeren Platzhalter.
- **Metadata-Stripping:** Raster-Bilder (jpg/png/webp) werden vor dem
  Verschlüsseln per GD re-encodiert (EXIF/GPS entfernt); PDF/Unbekannt bleiben
  unverändert (aber weiterhin verschlüsselt).
- **Deployment-Guard (fail-closed):** `deployment/docker-compose.yml` verweigert
  den Start, wenn `FILE_ENCRYPTION_KEY` — zusammen mit `APP_KEY` und
  `JWT_SECRET` — leer ist. Ohne Key wären gespeicherte Ausweise/Fotos und
  `answers`-Snapshots unwiederbringlich; der Guard verhindert einen stillen
  Fehlstart mit unbrauchbarem Storage.

## .gitignore-Strategie

Root-`.gitignore` (Frontend-Sektion):

```
frontend/.env*
!frontend/.env.example
!frontend/.env.local.example
```

`frontend/.gitignore` enthält **keine** `!.env`/`!.env.local`-Negationen mehr
(diese waren der Wurzel-Bug). Die Datei dokumentiert diese Regel als Kommentar.

## Setup-Workflow (für Devs & CI)

### Backend

`PHOTO_STORAGE_PATH` muss eine nicht leere absolute Laufzeit sein. Das lokale
Template `backend/.env.example` verwendet dafür den dokumentierten Pfad
`/tmp/portal-reisinger-photos`; vor dem ersten lokalen Backend-Start
muss das Verzeichnis mit `mkdir -p /tmp/portal-reisinger-photos`
angelegt werden. `backend/.env.ci` und die Produktions-Compose behalten
unverändert `/var/www/photos` als Container-/Deployment-Pfad. `.env.ci` bleibt
davon unabhängig ein CI-only Fixture.

`composer setup` ist **kein Konfigurations-Wizard**. Auf einem frischen Checkout
kopiert das Script `.env.example` nur, wenn `.env` fehlt, generiert einen
Application Key und führt anschließend `php artisan migrate --force --seed` aus.
Leeres `ADMIN_PASSWORD` (wie in der Vorlage) lässt den Seeder fehlschlagen; das
Script fragt nicht nach Zugangsdaten und erzeugt keinen JWT- oder
File-Encryption-Key. Deshalb zuerst die `.env` vollständig konfigurieren:

```bash
cd backend
cp .env.example .env
# In .env mindestens setzen:
# ADMIN_EMAIL=<lokale Admin-Adresse>
# ADMIN_PASSWORD=<nicht leeres lokales Passwort>
# Danach die übrigen benötigten JWT-, File-Encryption-, DB-, Mail- und Stripe-Werte.
php artisan key:generate
php artisan jwt:secret
# Generate FILE_ENCRYPTION_KEY and paste the output into .env:
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
composer setup
# Alternativ nach expliziter Konfiguration:
# composer install && php artisan migrate --force --seed
```

Der Seed ist Teil des Setup-Vertrags und provisionsiert den Bootstrap-Admin
über `ADMIN_EMAIL`/`ADMIN_PASSWORD` (siehe `backend/AGENTS.md`, „Database Setup
Policy“). `.env.ci` und `.env.encrypted` sind keine Ersatzkopien für diese lokale
Konfiguration.

### Frontend

```bash
cd frontend
cp .env.example .env           # oder: cp .env.local.example .env.local
# VITE_STRIPE_PUBLIC_KEY eintragen
pnpm install
```

## Verbleibendes Risiko (akzeptiert)

- **Git-History (Stripe-Keys):** ✅ Bereinigt (2026-07-21). 3 Stripe-Secrets mit
  `git-filter-repo --replace-text` durch `*_REDACTED` ersetzt. Force-Push zu
  GitHub. Backup: `portal-backup-20260721-133753.bundle`.
- **Git-History (APP_KEY/JWT_SECRET/Test-Password):** ✅ Bereinigt (2026-07-21).
  2. `git-filter-repo`-Durchlauf: `backend/.env.local` aus History entfernt,
  APP_KEY/JWT_SECRET-Fallbacks und `SuperSecret123!` durch `*_REDACTED` ersetzt.
  Verifikation via `git log -S` (alle Patterns leer). Force-Push erforderlich.
  Backup: `portal-backup-20260721-155854.bundle`.
- **Tracked Test-/Backup-Dateien:** `backend/.env.ci` ist ausschließlich ein
  CI-Fixture; `backend/.env.encrypted` ist ein verschlüsselter Blob und darf
  nicht als `.env`-Vorlage oder als Beweis für sichere lokale Secrets verwendet
  werden. Ihre getrackte Präsenz ist kein Freibrief zum Kopieren/Entschlüsseln.
- **`phpunit.xml`-Credentials:** SQLite-`:memory:` plus localhost-Service-Fixtures,
  akzeptiert. Die MariaDB-Werte in `.env.ci` gehören zum separaten E2E-Service-
  betrieb und sind nicht der PHPUnit-Test-DB-Vertrag.
- **Stripe-abhängige Checkout/Payout-Tests (2026-07-31):** `OrderCheckoutTest`
  (2 Tests), `PayoutSystemTest` (1 Test) und `Coupon/CheckoutCouponRevalidationTest`
  (3 Tests) benötigen einen **echten** Stripe-Test-Secret-Key, da sie ausgehende
  `PaymentIntent`-Calls an die Stripe-API auslösen. Mit Platzhalter
  `sk_test_<your_stripe_secret_key>` (im `backend/.env.example`) schlagen sie mit HTTP 401
  fehl. Ein Mock ist nur über Container-Binding (`StripePaymentService`/`CheckoutService`)
  möglich — payment-kritisch, außerhalb des Email-Template-Scopes. **Akzeptiert,**
  bis ein gültiger `STRIPE_SECRET` in der lokalen `.env` hinterlegt oder die
  Service-Mock-Strategie etabliert ist. Nachweis: Analyse vom 2026-07-31,
  71 vorbestehende Fehler auf 6 reduziert (Rest vorbestehend, Root Cause
  `BrandConfig::__construct` fehlende Parameter-Defaults — gefixt).
- **C1/C2 (`APP_KEY`/`JWT_SECRET` Fallbacks):** ✅ RESOLVED (2026-07-21) — Schlüssel rotiert.
  Deployment-Guard in `deployment/docker-compose.yml` prüft nun generisch auf leere Werte
  statt auf konkrete Strings — keine erneute Exposition über das Repository möglich.
  Seit 2026-09-19 prüft derselbe Guard zusätzlich `FILE_ENCRYPTION_KEY`
  (fail-closed, siehe „File Encryption at Rest").

## DoD / Verifikation

```bash
# 1. Secret-Scan über getrackte Dateien (muss leer sein)
git grep -nE 'sk_test_[0-9A-Za-z]{20,}|sk_live_|pk_live_[0-9A-Za-z]{20,}' -- ':!*.md' ':!features/'

# 2. Getrackte Env-Dateien klassifizieren (nicht ungeprüft als Starter verwenden)
git ls-files | grep -E '(^|/)\.env'   # enthält neben *.example auch backend/.env.ci und backend/.env.encrypted
```
