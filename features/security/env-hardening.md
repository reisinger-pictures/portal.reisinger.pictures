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
Produktion setzt die reale E-Mail und das Passwort ausschließlich über die
Host-Datei `/opt/stacks/portal-reisinger-pictures/.env`.

### Getrackte Dateien (committed)

Die ausdrücklich erlaubten Dateien sind nicht alle Starter-Templates:

| Datei                             | Klassifizierung und Zweck                              |
| --------------------------------- | ------------------------------------------------------ |
| `backend/.env.example`            | Dev-Template mit Platzhaltern (APP, DB, Mail, Scout, Mollie, AI, JWT, File Encryption) |
| `backend/.env.ci`                 | **CI-only Fixture**: wird von den Workflows als `.env` verwendet; enthält Test-/Platzhalterwerte, keine Produktionskonfiguration und kein Developer-Setup |
| `frontend/.env.example`           | Frontend-Default-Template (`VITE_TURNSTILE_SITE_KEY`-Platzhalter; kein Zahlungs-Key) |
| `frontend/.env.local.example`     | Frontend-Local-Dev-Template (override `.env`)          |
| `deployment/production-env.keys`  | Inventar der Produktionsvariablennamen ohne Werte      |

The real production file is host-only at
`/opt/stacks/portal-reisinger-pictures/.env`, owned by `root:root` with mode
`0600`. `deployment/deploy.sh` transfers only Compose and never creates or
overwrites `.env`; include the host file in protected backups. The directory
name is fixed because it determines the Compose volume namespace. See the
[Portainer-exit migration plan](../../../strato-vps/docs/plans/portainer-exit.md).

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

`backend/config/services.php` hat **keinen** hartcodierten Payment-Key-Fallback.
Der einzige Zahlungsanbieter ist Mollie (D-63): `MOLLIE_KEY` kommt ausschließlich
aus `env(...)`, Test- und Live-Modus wählt das Präfix des Schlüssels. Es gibt
keinen Stripe-Block, kein Stripe-SDK und keinen Stripe-Webhook mehr, also auch
kein `STRIPE_*`-Env, das gesetzt werden müsste. Tests verwenden `Config::set(...)`
mit Mock-Werten bzw. faken das SDK am Service-Seam.

### Stripe-Test-Key: aus dem Dashboard, nicht aus der CLI (2026-10-15) — historisch

> **Historisch (retired 2026-11-16).** Die Sektion belegt einen Incident vom
> 2026-10-15 und bleibt als Evidenz stehen. Die Handlungsanweisung gilt nicht
> mehr: das Stripe-CLI ist global entfernt, Stripe nimmt keine neuen Zahlungen
> mehr an (D-63, Mollie hosted checkout), und einen Frontend-Bundle-Key gibt es
> nicht mehr ([#58](https://github.com/reisinger-pictures/portal.reisinger.pictures/issues/58)).
> Mollie braucht keinen Bundle-Key; seine Konfiguration ist serverseitig und im
> [Mollie-Vertrag](../infrastructure/30-mollie-payments.md) festgehalten. Wer hier
> `stripe login` nachliest, folgt einem überholten Ablauf.

`stripe login` legt in `~/.config/stripe/config.toml` einen **eigenen** Test-Key
ab — nicht den Standard-Key des Kontos. Er trägt ein Ablaufdatum
(`test_mode_key_expires_at`), und Stripe **erzwingt** es: der Vorgänger-Key
antwortet nach seinem Datum mit `401 {"code":"api_key_expired"}`. Genau das hat
die E2E-Kette ab 2026-10-12 rot gemacht; seit dem 2026-10-15 ist sie grün
(Run `38738659755`, 11/11 Jobs), der Key-Tausch als Ursache bestätigt. Die
Ursache sah wie ein Testfehler aus, war aber ein abgelaufener Key.

Damals galt für `backend/.env` und die GitHub-Secrets:

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

## Super-Admin Mollie-Selbsttest `POST /api/management/settings/mollie-check`

Der Endpunkt bündelt fünf reine Diagnosen über die Mollie-Verdrahtung, ohne Repo
oder Host öffnen zu müssen. Er beantwortet die Frage, die ein Betreiber
tatsächlich hat: *kann diese Installation gerade eine Zahlung annehmen, und in
welchem Modus?* (Owner Decision
[D-63](../owner-decisions.md#d-63-mollie-collects-the-payment-the-portal-issues-the-invoice-and-offers-only-non-bnpl-methods);
Konfigurationsvertrag: [Mollie payments](../infrastructure/30-mollie-payments.md)).
Er ersetzt den Stripe-Selbsttest aus
[#59](https://github.com/reisinger-pictures/portal.reisinger.pictures/issues/59):
Stripe ist vollständig ausgebaut — kein Konto, kein Credential, kein Code —, und
die frühere D-24-Klasse
(falscher Modus / fehlender Zahlungs-Key im Frontend-Bundle) ist mit
[#58](https://github.com/reisinger-pictures/portal.reisinger.pictures/issues/58)
entfallen — Mollies gehostete Checkout-Seite lädt ihre eigene Seite, das Bundle
trägt keinen Provider-Key. Die Modus-Prüfung des Schlüssels leistet jetzt
`key_mode`.

**Route.** `backend/routes/api.php` — im `Route::middleware(['super_admin'])`-Block,
direkt neben dem SMTP-Nachbarn mit dem Namen `api.management.settings.test-email`;
eigener Name `api.management.settings.mollie-check`. Kette: äußerer
`['auth:api', 'management']`-Group, dann `SuperAdminMiddleware`. Gast → **401**
(`auth:api`), Nicht-Super-Admin → **403** (`SuperAdminMiddleware::handle` prüft
`AuthorizationService::isSuperAdmin`), Fotograf → **403** (schon die
`ManagementMiddleware`, denn `settings/mollie-check` ist nicht in ihrer
Prefix-Allowlist).

**Request.** Kein Body. Das Frontend sendet `{}`; es gibt keinen Modus-Parameter
und keine Nutzereingabe, die den Befund verändern könnte.

**Response 200.** Immer `{"checks":[…]}`, stabile Reihenfolge, jedes Element
`{"key","status","message"}` mit deutscher `message`. Außer Authentifizierung gibt
es **keinen** Provider-Fehlerpfad: jeder fehlgeschlagene Mollie-Aufruf — und jede
fehlende oder kaputte Konfiguration — wird zur `fail`-Meldung, nie zum 500.
`status`: `ok` = geprüft und korrekt, `warn` = auffällig, aber nicht blockierend,
`fail` = kaputt/inkonsistent.

| # | `key` | Prüft | warum `warn` statt `fail` |
|---|-------|-------|---------------------------|
| 1 | `key_mode` | Ist `MOLLIE_KEY` gesetzt, und welchen Modus trägt sein Präfix (`test`/`live`, über `MolliePaymentService::mode()`)? Rein lokal, kein Netz. Ein fehlender oder unkenntlicher Schlüssel ist ein Befund, keine Exception. | — (jeder Verstoß ist `fail`) |
| 2 | `webhook_url` | Ist `MOLLIE_WEBHOOK_URL` gesetzt, HTTPS und mit Host (`MolliePaymentService::webhookUrlIssue()`)? Die Prüfung ist in **jedem** Environment Pflicht; ein fehlender oder reiner HTTP-Wert deaktiviert die Zahlungsanlage mit `503`. Die konfigurierte URL wird berichtet — eine URL ist kein Secret. | — (jeder Verstoß ist `fail`) |
| 3 | `methods` | Die aufgelöste Allowlist aus `MOLLIE_PAYMENT_METHODS` (bzw. dem deprecated Einzelwert): nicht leer, jede ID gültig und keine BNPL-/Ratenzahlungsfamilie (`FORBIDDEN_PAYMENT_METHODS`). Eine kaputte Liste leert die **ganze** Liste und armt dasselbe `503`. Die Meldung nennt die ID. | — (jeder Verstoß ist `fail`) |
| 4 | `account` | Genau **ein** authentifizierter Provider-Aufruf über `MolliePaymentService::account()` (aktuelles Profil, `GET /v2/profiles/me` über das SDK). Berichtet Profil-ID, Modus und Status. Der Profil-Modus ist rein informativ: Mollie meldet dort auch für einen Test-Schlüssel `live` (gemessen 2026-11-16), deshalb entscheidet allein der Status. | Ein erreichbares, aber unverifiziertes Profil bremst die Live-Annahme, blockiert die Diagnose aber nicht: `warn`. `blocked` ist `fail`. |
| 5 | `readiness` | Genau der Boolean, den der Checkout-Gate benutzt: `canCreatePayment()` (Key **und** brauchbare Webhook-URL **und** ≥1 zugelassene Methode). Bei `fail` nennt die Meldung den Grund aus `configurationIssue()`. | — (der Gate ist binär) |

**Kein Key-Material in der Antwort.** Der Schlüsselwert wird nie gedruckt — auch
nicht in `key_mode`, das nur den Modus nennt. Zusätzlich laufen alle aufgebauten
Meldungen durch `MolliePaymentCheckService::redactSecrets()`, das
`test_…`/`live_…`-Muster entfernt; der Adapter (`MolliePaymentException`) trägt
ohnehin nur ein geschlossenes `reason`-Vokabular und keinen Provider-Text. Die
Profil-ID (`pfl_…`) ist ausdrücklich erlaubt und gewollt — der Admin soll sehen,
*welches* Profil verdrahtet ist. Ein abgelehnter Schlüssel wird zu
`request_failed` (Schlüssel ungültig/abgelaufen/ohne Berechtigung), nicht zum
Rohfehler von Mollie.

**Naht & Tests.** `MolliePaymentCheckService::__construct(MolliePaymentService $mollie)`
injiziert die bestehende Adapter-Naht; die Tests fälschen über
`Tests\Support\FakesMollie` und den SDK-eigenen `MockMollieClient` einen
deterministischen Provider statt Netz zu rühren. Die Modus-Ableitung bleibt im
Adapter (`mode()`), die Provider-Aufruf- und Exception-Abbildung ebenfalls. Gepinnt
in `MolliePaymentCheckControllerTest`: super_admin 200 / normaler Admin 403 / Gast
401 / Fotograf 403, je ein Fall für fehlenden Schlüssel (kein 500), fehlenden und
reinen HTTP-Webhook, denylistete und leere und ungültige Methodenliste,
Provider-401 (`account` `fail`, `key_mode` weiter `ok`), unverifiziertes Profil
(`warn`), gesunde Konfiguration (alle `ok`, `readiness` `ok`) und ein
Canary-Test, der einen erkennbaren `test_…`-Schlüssel in die Config legt und
belegt, dass er in keiner Antwort auftaucht.

**E2E.** Die Suite `frontend/tests/e2e/admin/settings/mollie-check.spec.ts` prüft
nur, was jede Umgebung garantiert: die Karte existiert für den Super-Admin und
fehlt für einen normalen Admin, nichts läuft vor dem Klick, alle fünf Befunde
erscheinen in fester Reihenfolge mit nicht-leerer Meldung und einem Status aus
`ok|warn|fail`, und der Schlüsselwert steht nirgends auf der Seite. Die
provider- und umgebungsabhängigen Status werden **nicht** assertiert:
`backend/.env.e2e` lässt `MOLLIE_WEBHOOK_URL` bewusst leer (kein öffentlicher
Tunnel), also sind `webhook_url` und `readiness` dort legitim `fail`, und
`account` ist eine echte Netzabfrage. Die Zweige jedes Befunds leben in PHPUnit.
`scripts/e2e-up.sh` setzt deshalb keinen Stripe-Dummy mehr
(`E2E_STRIPE_WEBHOOK_SECRET` ist entfallen): der frühere Wert existierte nur für
Prüfung 1 des alten Selbsttests.

**Retired (Historie, kein Soll-Zustand):** Bis zum Stripe-Ausstieg blieb der
Altbestand über den Stripe-Webhook `/api/webhooks/stripe` erreichbar, der mit
`App\Support\StripeWebhookSecret::resolve()` und `STRIPE_WEBHOOK_SECRET` prüfte
und in `local`/`testing` auf das Tunnel-Artefakt `storage/app/private/stripe_secret.txt`
zurückfiel. Mit dem Stripe-Ausbau entfallen Route, Secret-Auflösung, SDK und
dieses Artefakt; die Historie bleibt hier nur als Beleg.

**Verifikation** (aus `backend/`): `php artisan test --filter MolliePaymentCheck`
für den Feature-Test und `./vendor/bin/pint --test` für die Formatierung.

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
# Danach die übrigen benötigten JWT-, File-Encryption-, DB-, Mail- und Mollie-Werte.
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
pnpm install
```

Das Frontend kennt nur `VITE_TURNSTILE_SITE_KEY`; einen Zahlungs-Key gibt es
nicht mehr. Mollie wird ausschließlich serverseitig konfiguriert
(`MOLLIE_KEY`, `MOLLIE_PAYMENT_METHODS`, `MOLLIE_WEBHOOK_URL` in `backend/.env`,
Vertrag: [Mollie payments](../infrastructure/30-mollie-payments.md)).

**Welche Datei gewinnt — und warum das für den Deploy zählt.** Vite lädt
`.env.local` in **allen** Modi, und `pnpm build` läuft als mode=production. Die
Rangfolge, höchste zuerst — nachprüfbar in der installierten Quelle
(`grep -n 'getEnvFilesForMode' frontend/node_modules/vite/dist/node/chunks/node.js`,
Vite 8.2.2 liefert `[.env, .env.local, .env.<mode>, .env.<mode>.local]`; die
Reihenfolge ist die Aussage, nicht die Zeilennummer):

```
.env.production.local  (gitignored)  ← optionaler lokaler Override; höchste Stufe
.env.production        (gitignored)  ← Werte, die nur der Live-Build tragen darf
.env.local             (gitignored)  ← Dev-Override; schlägt `.env` in ALLEN Modi
.env                   (gitignored)
```

`VITE_*` wird beim Build **eingebacken**; die Host-`.env` und lokale
`.env.production`-Dateien erreichen das Bundle also nie — die Host-`.env`
versorgt nur den Backend-Container. Die Regel bleibt für jeden
`VITE_*`-Wert gültig (heute nur Turnstile), ist aber keine Zahlungsregel mehr:
der Mollie-Key ist serverseitig und wird nie an das Frontend übergeben.

**Gegenprobe nach dem Build** (dieselbe Zeile wie in
[`deployment/README.md`](../../deployment/README.md), Abschnitt „Sync und der
Container-Neustart"): sie muss leer bleiben, also weder einen Provider-Key noch
einen Live-Credential-Wert im Bundle zeigen —

```bash
cd frontend && pnpm lint:fix && pnpm build
grep -rIlE 'MOLLIE_KEY|pk_live_|sk_live_|rk_live_|whsec_' dist | head
```

Die Muster für Live-Credentials bleiben im Scan, weil sie auch historische
Artefakte in einem beliebigen Checkout finden sollen; im Bundle darf keines
davon auftauchen.

**Historisch (2026-10-15, gemessen):** `assets/ClientCartView-3L6eh2lZ.js` der
Live-Seite und dasselbe Chunk im lokalen `frontend/dist/` hatten identische
`md5` (`67b769fd0dbc1c4f293410433d3d8f9d`) und enthielten beide
`pk_test_51TJyXb0zjsh…`, während der Backend-Container
`STRIPE_SECRET=sk_live_…` führte. Dieser Beleg ist der Grund, warum die
Rangfolge oben dokumentiert bleibt — der damalige Live-Key-Bundle-Check
(`grep -rho 'pk_live_…' frontend/dist/assets` nach jedem Build) ist mit #58
entfallen: das Bundle trägt keinen Zahlungs-Key mehr, den man prüfen könnte.

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
- **Stripe-abhängige Checkout/Payout-Tests:** ✅ **erledigt durch den
  Stripe-Ausbau.** Der Befund von 2026-07-31 (Tests brauchten einen echten
  `STRIPE_SECRET`, weil sie ausgehende `PaymentIntent`-Calls auslösten) gilt
  nicht mehr: es gibt keinen Stripe-Client und keinen Key. Zahlungs-Tests laufen
  gegen das gefakte Mollie-SDK bzw. gegen `MolliePaymentService` als Seam und
  brauchen keine Provider-Credentials. Der Befund bleibt als Historie stehen,
  weil er die damalige Entscheidung gegen ein Service-Mock dokumentiert.
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
