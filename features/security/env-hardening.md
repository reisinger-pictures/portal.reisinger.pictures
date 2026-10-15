# Environment File & Secret Hardening

## Status: Active (reviewed 2026-09-28)

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
| `frontend/.env.example`           | Frontend-Default-Template (`VITE_STRIPE_PUBLIC_KEY` Platzhalter) |
| `frontend/.env.local.example`     | Frontend-Local-Dev-Template (override `.env`)          |

Die einzige Allowlist-Ausnahme neben den `*.example`-Templates ist
`backend/.env.ci` (`.gitignore:55` → `!.env.ci`); das Ignore-Muster `.env*`
(`.gitignore:54`) greift ansonsten für alle Env-Dateien. `backend/.env.encrypted`
ist **keine** Allowlist-Ausnahme und wird deshalb nie getrackt; die Absicht steht
als Kommentar im Root-`.gitignore` (`.gitignore:58` → `# Verschlüsselte
.env-Backups bleiben bewusst lokal und werden nie committed.`). Diese
Klassifizierung ist maschinell prüfbar: `git check-ignore -v backend/.env.encrypted`
→ `.gitignore:54:.env*	backend/.env.encrypted`.

### Ungetrackte Dateien (lokal auf Disk, NICHT committed)

Diese Dateien können reale Secrets enthalten und stehen nur auf der lokalen Disk
bzw. werden im Deployment injiziert:

- `backend/.env`, `backend/.env.local`, `backend/.env.production` etc.
- `frontend/.env`, `frontend/.env.local`
- `backend/storage/*.key`
- `backend/.env.encrypted` — ein lokaler, verschlüsselter Env-Blob/Backup. Die
  Datei ist **nicht** getrackt und existiert in einem frischen Checkout **nicht**
  (Stand HEAD); sie ist kein Bestandteil der Wiederherstellung. Ein früherer Stand
  hatte sie kurzzeitig getrackt (hinzugefügt in `6eaf5d4`, entfernt in `e1f397d`,
  reproduzierbar mit
  `git log --diff-filter=AD --format='%h %ad %s' --date=short -- backend/.env.encrypted`).

`.env.ci` ist davon ausgenommen: Es ist tracked, mit der oben beschriebenen
CI-Klassifizierung.

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

### Stripe-Test-Key: aus dem Dashboard, nicht aus der CLI (2026-10-15)

`stripe login` legt in `~/.config/stripe/config.toml` einen **eigenen** Test-Key
ab — nicht den Standard-Key des Kontos. Er trägt ein Ablaufdatum
(`test_mode_key_expires_at`), und Stripe **erzwingt** es: der Vorgänger-Key
antwortet nach seinem Datum mit `401 {"code":"api_key_expired"}`. Genau das hat
die E2E-Kette ab 2026-10-12 rot gemacht; seit dem 2026-10-15 ist sie grün
(Run `38738659755`, 11/11 Jobs), der Key-Tausch als Ursache bestätigt. Die
Ursache sah wie ein Testfehler aus, war aber ein abgelaufener Key.

Deshalb gilt für `backend/.env` und die GitHub-Secrets:

- **Test-Secret aus dem Dashboard** (Testmodus → API keys → Secret key). Er
  trägt kein Ablaufdatum; gemessen sind CLI-Key und Dashboard-Key zwei
  verschiedene Werte (`sha256` `949838090def` vs. `5ce6d2c3849b`).
- **Der Publishable-Key ist in beiden Quellen derselbe** (`sha256`
  `c9b329739363`) — pro Konto und Modus gibt es nur einen Standard-`pk_test`.
  Hier ist die Herkunft also egal.
- **Eine Rotation per CI ist nicht möglich:** Stripe hat keine Key-API
  (`GET /v1/api_keys` und `/v1/restricted_keys` antworten **404**, gemessen);
  Keys rollen ist reine Dashboard-Handarbeit. Das Netz ist der CI-Preflight
  „Validate Stripe API key (read + write)". Er schlägt beim ersten Lauf nach
  einer nicht weitergereichten Rotation fehl — laut, nicht still.

## Super-Admin Stripe-Selbsttest `POST /api/management/settings/stripe-check`

Dauerhafter SOLL-Zustand. Der Endpunkt bündelt fünf reine Diagnosen, mit denen ein
Super-Admin die Stripe-Verdrahtung prüft, ohne Repo oder Host öffnen zu müssen. Er
adressiert die D-24-Klasse (falscher Modus / fehlender `VITE_STRIPE_PUBLIC_KEY` im
Bundle) als *sichtbaren Befund* statt als stillen Deploy-Fehler.

**Route.** `backend/routes/api.php` — im `Route::middleware(['super_admin'])`-Block,
direkt neben dem SMTP-Nachbarn mit dem Namen `api.management.settings.test-email`;
eigener Name `api.management.settings.stripe-check`. Kette: äußerer
`['auth:api', 'management']`-Group, dann `SuperAdminMiddleware`. Gast → **401**
(`auth:api`), Nicht-Super-Admin → **403** (`SuperAdminMiddleware::handle` prüft
`AuthorizationService::isSuperAdmin`), Fotograf → **403** (schon die
`ManagementMiddleware`, denn `settings/stripe-check` ist nicht in ihrer
Prefix-Allowlist).

**Request.** `{"bundle_mode": "test"|"live"|null}`. `bundle_mode` ist
`nullable|in:test,live`, inline validiert in `StripeKeyCheckController::check`.
`null` oder fehlend ist **kein** 422, sondern der Befund der Prüfung 2 — genau die
Fehlerklasse „kein `VITE_STRIPE_PUBLIC_KEY` im Bundle", die der Selbsttest sichtbar
machen soll.

**Response 200.** Immer `{"checks":[…]}`, stabile Reihenfolge, jedes Element
`{"key","status","message"}` mit deutscher `message`. Außer Authentifizierung gibt
es **keinen** Stripe-Fehlerpfad: selbst ein fehlgeschlagener Stripe-Aufruf — oder
ein Client, der sich mit der konfigurierten Umgebung gar nicht erst bauen lässt —
wird zur `fail`-Meldung. `status`: `ok` = geprüft und korrekt, `warn` = auffällig, aber
nicht blockierend, `fail` = kaputt/inkonsistent.

| # | `key` | Prüft | warum `warn` statt `fail` |
|---|-------|-------|---------------------------|
| 1 | `local_key_mode` | `STRIPE_KEY` und `STRIPE_SECRET` tragen denselben Modus, dieser entspricht dem Soll-Modus, `STRIPE_WEBHOOK_SECRET` ist gesetzt (Env oder — nur in `local`/`testing` — das Tunnel-Artefakt, beides über `App\Support\StripeWebhookSecret`). Rein lokal, kein Netz. | — (jeder Verstoß ist `fail`) |
| 2 | `frontend_bundle_mode` | `bundle_mode` gleich dem Modus des Server-`STRIPE_KEY`; `null` heißt „Schlüssel fehlt im Bundle". Es wird nur der Modus verglichen, nie ein Key-Wert. | — |
| 3 | `stripe_balance` | `$stripe->balance->retrieve()`, gültig und `livemode` gleich Soll-Modus. Ein Stripe-Code (z. B. `api_key_expired`, `more_permissions_required`) wird wörtlich durchgereicht, nicht verflacht. | — |
| 4 | `stripe_account` | `$stripe->accounts->retrieve()`; sobald das Konto abrufbar ist, steht die `id` (`acct_…`) in der Meldung. | Nicht gesetzte `charges_enabled`/`payouts_enabled`/`details_submitted` sind `warn`, weil Zahlungen trotzdem gehen können. |
| 5 | `stripe_webhook_endpoint` | `$stripe->webhookEndpoints->all(['limit' => 100])`; gesuchte URL wird **so** gebaut, wie die App sie sonst baut (`rtrim(BrandRegistry::frontendUrl(), '/').'/api/webhooks/stripe'`). Kein Endpunkt / nicht `enabled` → `fail`; `enabled`, aber die vier Events fehlen → `warn` (fehlende benannt). | Fehlende Events bremsen die Verarbeitung, blockieren aber keine Zahlung. |

Die vier erforderlichen Webhook-Ereignisse: `payment_intent.succeeded`,
`payment_intent.payment_failed`, `charge.dispute.created`, `charge.refunded`.

**Soll-Modus aus APP_ENV.** `StripeKeyMode::fromEnvironment()` bildet
`config('app.env')` ab: `production` → `live`, alles andere (`local`, `testing`, …)
→ `test`. **Keine** zusätzliche Setting-Zeile, **kein** neues Env. Den Modus eines
einzelnen Keys leitet `StripeKeyMode::fromKey()` rein aus dem Token direkt hinter
dem Präfix ab (`pk`/`sk`/`rk` × `_test_`/`_live_`); ein `_test_`/`_live_` im
Zufallsanteil zählt **nicht**, unbekanntes Präfix, leerer Wert oder `null` ergeben
`null`. Beides sind reine Funktionen ohne `config()`-Zugriff — die Config liest der
Aufrufer (`StripeKeyCheckService`), damit der Config-Cache nicht durch `env()`
unterlaufen wird.

**Kein Key-Material in der Antwort.** Niemals `sk_…`/`pk_…`/`rk_…`/`whsec_…`-Werte.
Die Konto-ID (`acct_…`) und die Webhook-Endpunkt-ID (`we_…`) sind ausdrücklich
erlaubt und gewollt — ein Admin soll sehen, *welches* Konto verdrahtet ist. Da
Stripe einen fehlerhaften/abgelaufenen Key u. U. maskiert (`sk_test_…***`) im
Fehlertext spiegelt, läuft jede übernommene Stripe-Fehlermeldung vor dem
Rausschreiben durch `StripeKeyCheckService::describeStripeError()` →
`redactSecrets()`, das Key-Fragmente auf `***entfernt***` reduziert.

**Naht & Tests.** `StripeKeyCheckService::__construct(?StripeClient $stripe = null)`
übernimmt 1:1 das Muster von `StripePaymentService`: ein injizierbarer
`StripeClient`, damit die Tests über die `Stripe\ApiRequestor::setHttpClient()`-Naht
 deterministisch faken (Helper `MocksStripeClient::mockStripeApi`) statt Netz zu
rühren. Der Client wird **erst beim Bedarf** aufgelöst (je Netz-Prüfung, nicht im
Konstruktor): mit fehlendem oder leerem `STRIPE_SECRET` wirft `new StripeClient(…)`
selbst, und genau diese Fehlkonfiguration soll der Endpunkt diagnostizieren — sie
wird deshalb wie jeder andere Stripe-Fehler zur `fail`-Meldung, nicht zum 500.
Prüflogik lebt vollständig im Service, der Controller hält nur Validierung
und Aufruf. Gepinnt: `StripeKeyModeTest` (Grenzfälle der Modus-Ableitung) und
`StripeKeyCheckControllerTest` (super_admin 200 / normaler Admin 403 / Gast 401 /
Fotograf 403, plus je ein Fall pro Befund inkl. `bundle_mode: null`, abgelaufener
Key, fehlender/disabled/event-unvollständiger Endpunkt, Konto ohne
`charges_enabled`, ein Stripe-Fehler auf dem Konto- und dem Endpunkt-Pfad sowie
ein gar nicht baubarer Client; `assertNoSecretMaterial()` prüft jede Antwort auf
fehlende `sk_`/`pk_`/`whsec_`-Werte).

**Prüfung 5 bleibt in jedem Environment streng** — fehlender Endpunkt ist überall
`fail`, nicht umgebungsabhängig. Der E2E-Lauf prüft deshalb nur die vier
umgebungsdeterministischen Befunde (im Test-Modus existiert kein adressierbarer
Live-Endpunkt); die Zweige der Prüfung 5 leben in PHPUnit
(`StripeKeyCheckControllerTest`). Eine dieser vier, `local_key_mode`, hängt neben
Env und Key-Modus zusätzlich an einem nicht-leeren `STRIPE_WEBHOOK_SECRET`:
`scripts/e2e-up.sh` setzt dafür einen Dummy (`E2E_STRIPE_WEBHOOK_SECRET`), weil
`backend/.env` den Wert bewusst leer lässt (Tunnel-Fallback) und ein kalter Lauf
sonst rot wäre.

**Verifikation** (aus `backend/`): `php artisan test --filter StripeKeyCheck` für
den Feature-Test, `php artisan test --filter StripeKeyMode` für die pure
Modus-Ableitung, `./vendor/bin/pint --test` für die Formatierung.

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
Policy“). `.env.ci` ist keine Ersatzkopie für diese lokale Konfiguration;
`backend/.env.encrypted` existiert nicht im Repo (siehe „Ungetrackte Dateien“)
und ist daher schon gar kein Setup-Ersatz.

### Frontend

```bash
cd frontend
cp .env.example .env           # oder: cp .env.local.example .env.local
# VITE_STRIPE_PUBLIC_KEY eintragen
pnpm install
```

**Welche Datei gewinnt — und warum das für den Deploy zählt.** Vite lädt
`.env.local` in **allen** Modi, und `pnpm build` läuft als mode=production. Die
Rangfolge, höchste zuerst — nachprüfbar in der installierten Quelle
(`grep -n 'getEnvFilesForMode' frontend/node_modules/vite/dist/node/chunks/node.js`,
Vite 8.2.2 liefert `[.env, .env.local, .env.<mode>, .env.<mode>.local]`; die
Reihenfolge ist die Aussage, nicht die Zeilennummer):

```
.env.production.local  (gitignored)  ← optionaler lokaler Override; höchste Stufe
.env.production        (gitignored)  ← der Live-Key gehört HIERHIN
.env.local             (gitignored)  ← Dev-Override; schlägt `.env` in ALLEN Modi
.env                   (gitignored)
```

`VITE_*` wird beim Build **eingebacken**; Portainer/`.env.production` im Repo-Root
erreichen das Bundle also nie — die versorgen nur den Backend-Container. Ein
Prod-Build braucht deshalb `frontend/.env.production` mit dem Live-Key. Ohne
diese Datei trägt das Bundle den Test-Key aus `.env.local` (bzw. `.env`), und
Stripe.js kann den `client_secret` eines Live-PaymentIntent nicht bestätigen.

**Beleg (2026-10-15, gemessen):** `assets/ClientCartView-3L6eh2lZ.js` der
Live-Seite und dasselbe Chunk im lokalen `frontend/dist/` hatten identische
`md5` (`67b769fd0dbc1c4f293410433d3d8f9d`) und enthielten beide
`pk_test_51TJyXb0zjsh…`, während der Backend-Container
`STRIPE_SECRET=sk_live_…` führte. Prüfzeile nach jedem Build:

```bash
grep -rho 'pk_live_[A-Za-z0-9]\{12\}' frontend/dist/assets | head -1
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
  CI-Fixture. `backend/.env.encrypted` ist **nicht** getrackt und liegt in keinem
  Checkout; ein lokal gehaltenes verschlüsseltes Backup darf nicht als
  `.env`-Vorlage oder als Beweis für sichere lokale Secrets verwendet werden.
  Reproduzierbar mit `git ls-files | grep -E '(^|/)\.env'` — es erscheinen nur
  `backend/.env.ci` und die drei `*.example`-Dateien, kein `.env.encrypted`.
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
git ls-files | grep -E '(^|/)\.env'
# Ausgabe (Stand HEAD, geprüft 2026-09-28):
#   backend/.env.ci
#   backend/.env.example
#   frontend/.env.example
#   frontend/.env.local.example
# backend/.env.encrypted ist bewusst NICHT enthalten (siehe „Ungetrackte Dateien").
```
