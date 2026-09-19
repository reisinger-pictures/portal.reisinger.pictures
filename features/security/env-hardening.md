# Environment File & Secret Hardening

## Status: Active (2026-07-21)

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

Die Super-Admin-Identität ist **vollständig env-getrieben** (`ADMIN_EMAIL`), mit generischem Fallback `admin@example.com`. Es gibt **keine hartcodierte persönliche E-Mail** mehr im Quellcode (vorher `florian@reisinger.pictures` als magische `brand=null`-Identität in `AuthController`, `DatabaseSeeder`, `E2ETestUserSeeder`, `V004`, `V018` und `company_email`-Setting). Prod setzt die reale Admin-E-Mail ausschließlich über Portainer/`.env`.

### Getrackte Dateien (committed)

Nur noch Template-Dateien mit Platzhaltern sind committed:

| Datei                             | Zweck                                                  |
| --------------------------------- | ------------------------------------------------------ |
| `backend/.env.example`            | Vollständiges Dev-Template (APP, DB, Mail, Scout, Stripe, AI, JWT) |
| `frontend/.env.example`           | Frontend-Default-Template (`VITE_STRIPE_PUBLIC_KEY` Platzhalter) |
| `frontend/.env.local.example`     | Frontend-Local-Dev-Template (override `.env`)          |

### Ungetrackte Dateien (lokal auf Disk, NICHT committed)

Diese Dateien enthalten reale Secrets und stehen nur auf der lokalen Disk bzw.
werden im Deployment injiziert:

- `backend/.env`, `backend/.env.local`, `backend/.env.production` etc.
- `frontend/.env`, `frontend/.env.local`
- `backend/storage/*.key`

### Test-Fixtures

`backend/phpunit.xml` enthält localhost Test-Credentials (`127.0.0.1:3307`,
`portal_user/admin`, `test_meili_secret`, Mail-Port 1025). Diese sind bewusst
als Fixtures akzeptiert (keine Third-Party-Secrets, nur lokal erreichbare
Services). Siehe `AGENTS.md` §7 Risk Register.

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
  Test-Key; `backend/.env.ci` einen CI-Key. `backend/.env.example` enthält nur
  den leeren Platzhalter.
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

```bash
cd backend
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# STRIPE_*, DB_*, MAIL_* in .env eintragen
php artisan migrate --force
php artisan db:seed   # legt Admin via ADMIN_EMAIL an (siehe AGENTS.md §6)
```

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
- **`phpunit.xml`-Credentials:** localhost Fixtures, akzeptiert.
- **Stripe-abhängige Checkout/Payout-Tests (2026-07-31):** `OrderCheckoutTest`
  (2 Tests), `PayoutSystemTest` (1 Test) und `Coupon/CheckoutCouponRevalidationTest`
  (3 Tests) benötigen einen **echten** Stripe-Test-Secret-Key, da sie ausgehende
  `PaymentIntent`-Calls an die Stripe-API auslösen. Mit Platzhalter
  `sk_test_<your_stripe_secret_key>` (`.env:59`) schlagen sie mit HTTP 401
  fehl. Ein Mock ist nur über Container-Binding (`StripePaymentService`/`CheckoutService`)
  möglich — payment-kritisch, außerhalb des Email-Template-Scopes. **Akzeptiert,**
  bis ein gültiger `STRIPE_SECRET` in der lokalen `.env` hinterlegt oder die
  Service-Mock-Strategie etabliert ist. Nachweis: Analyse vom 2026-07-31,
  71 vorbestehende Fehler auf 6 reduziert (Rest vorbestehend, Root Cause
  `BrandConfig::__construct` fehlende Parameter-Defaults — gefixt).
- **C1/C2 (`APP_KEY`/`JWT_SECRET` Fallbacks):** ✅ RESOLVED (2026-07-21) — Schlüssel rotiert.
  Deployment-Guard in `docker-compose.yml` prüft nun generisch auf leere Werte
  statt auf konkrete Strings — keine erneute Exposition über das Repository möglich.
  Seit 2026-09-19 prüft derselbe Guard zusätzlich `FILE_ENCRYPTION_KEY`
  (fail-closed, siehe „File Encryption at Rest").

## DoD / Verifikation

```bash
# 1. Secret-Scan über getrackte Dateien (muss leer sein)
git grep -nE 'sk_test_[0-9A-Za-z]{20,}|sk_live_|pk_live_[0-9A-Za-z]{20,}' -- ':!*.md' ':!features/'

# 2. Nur *.example-Dateien getrackt
git ls-files | grep -E '(^|/)\.env'   # erwartet: backend/.env.example, frontend/.env.example, frontend/.env.local.example
```
