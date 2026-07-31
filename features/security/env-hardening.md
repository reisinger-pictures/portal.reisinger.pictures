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

## DoD / Verifikation

```bash
# 1. Secret-Scan über getrackte Dateien (muss leer sein)
git grep -nE 'sk_test_[0-9A-Za-z]{20,}|sk_live_|pk_live_[0-9A-Za-z]{20,}' -- ':!*.md' ':!features/'

# 2. Nur *.example-Dateien getrackt
git ls-files | grep -E '(^|/)\.env'   # erwartet: backend/.env.example, frontend/.env.example, frontend/.env.local.example
```
