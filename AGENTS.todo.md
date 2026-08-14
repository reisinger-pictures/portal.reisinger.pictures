# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-07. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Kanban-SOLL (Rollen-Matrix, DnD-Desktop-only, Status-Select): `features/b2b/11-kanban-board.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
> - **Backlog-Ausarbeitung 2026-08-04:** Drei parallele Analyse-Aufträge (A1, F3, Stack-Konsolidierung) abgeschlossen — Pläne unten. Offene Fragen interaktiv geklärt (Entscheidungen dokumentiert). **Kein Code geändert.**
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## ✅ ERLEDIGT — Dependabot-Vulnerabilities (2026-08-14): 17 Composer-Alerts gefixt

**Symptom:** 17 offene Dependabot-Alerts im Backend (5 high / 10 medium / 2 low) — ausschließlich Composer-Transitives:

| Package | Version (vor) | Version (nach) | Alerts | Betroffen |
|---|---|---|---|---|
| `league/commonmark` | 2.8.2 | 2.10.0 | 6 (4 high, 2 medium) | via `laravel/framework` `^2.8.1` |
| `guzzlehttp/guzzle` | 7.13.1 | 7.15.3 | 6 (1 high, 5 medium) | via `laravel/framework` `^7.8.2` |
| `dompdf/dompdf` | v3.1.5 | v3.1.6 | 6 (4 medium, 2 low) | via `barryvdh/laravel-dompdf` `^3.0` |

**Umsetzung:** `composer update league/commonmark guzzlehttp/guzzle dompdf/dompdf --with-all-dependencies` (nur `backend/composer.lock` geändert, `composer.json` unverändert — Constraints erlaubten die Patches bereits). Nebeneffekt-Updates: `guzzlehttp/promises` 2.5.0→2.5.2, `guzzlehttp/psr7` 2.12.3→2.13.0, `nette/utils` v4.1.4→v4.1.5.

**Verifikation (separater Subagent):** `composer audit` = 0 Advisories; volle `php artisan test`-Suite **1168 passed / 0 failed** (Meilisearch 7701 OK). Alerts schließen sich nach Push automatisch (Lockfile auf default Branch gepatcht).

**Nachfolger-Alert #115 (nanoid, high, npm):** Nach dem Push waren alle Composer-Alerts geschlossen, aber ein 18. Alert blieb offen: `nanoid < 3.3.18` (Transitive von `postcss`). Fix: `pnpm.overrides` in `frontend/package.json` 3.3.17 → **3.3.18** + `pnpm install`. `pnpm audit` = 0. Verifikation: lint:fix + build + Vitest (580 passed) grün; E2E-@smoke nicht gelaufen (App-Stack nicht gestartet, Environment-Lücke).

**⚠️ Lektion (Overrides/Workspace):** `pnpm.overrides` in `package.json` wird vom effektiven pnpm (packageManager-Pin `pnpm@9.15.4` im Frontend) weiterhin gelesen und angewendet — die Deprecation-Warnung („keys were ignored") ist in 9.15.x nur eine Warnung, kein Ignore. `overrides` in `pnpm-workspace.yaml` wird von 9.15.4 NICHT gelesen (Versuch führte zu react-router 7.18.1 → audit high; zurückgerollt). `frontend/pnpm-workspace.yaml` ist getrackt und darf NICHT überschrieben werden — es steuert `allowBuilds`/`onlyBuiltDependencies` (esbuild).

— keine Regression.

---

## 🔍 REVIEW 2026-08-13 — Architektur / UX / Sicherheit

**Gesamturteil:** Überdurchschnittlich solide. Sicherheits-Basis (Stripe-Webhook-Verifikation + Underpayment-Guard, JWT-httpOnly-Cookie + SameSite=Lax, Money-Pattern mit bcmath, Brand-Isolation, Offer-Token) vorbildlich. Baustellen: Autorisierungs-Scatter, God-Entities, CSP-Favicon (bekannt).

**Befunde (Detail in Session-Review):**

| # | Befund | Ort |
|---|---|---|
| A1 | User.php God-Entity; ~170 `is_*`-Prüfungen verstreut; N+1 via Accessoren | `User.php:24–167` |
| A2 | CheckoutService 410 Z. (Validierung+Pricing+Order+Stripe gemischt) | `CheckoutService.php` |
| A3 | Autorisierungs-Scatter: Middleware-Prefix + Inline-Checks + Policies (inkonsistent) | `ManagementMiddleware.php:28–45` |
| A4 | Bool-Komposit `is_admin\|\|(is_photographer&&canPhotographerAccessGallery)` 5× kopiert | Policies + Download-Controller |
| A5 | Raw-SQL-CTE (legitim, parametrisiert) bricht Eloquent-Regel | `AccessControlService.php:105` |
| S1 | Photographer-Fläche in ManagementMiddleware breit (`orgs*`, `coupons*`, `lightroom-catalogs*`) | `ManagementMiddleware.php:29` |
| S2 | Kein `Permissions-Policy`-Header; CSP nur an Caddy (stale Hash → bekannter Prod-Crash) | `SetSecurityHeaders.php` |
| S3 | Rolling Refresh unbegrenzt (`refresh_iat=true`, `refresh_ttl=20160`) | `config/jwt.php:120–121` |
| S4 | `proxy_delivery_header` leakt FS-Pfad falls Proxy Header nicht konsumiert | `FileDeliveryController.php:153` |
| S5 | `/test/flush-queue` immer registriert; Admin-Email-Default hartcodiert | `api.php:79`, `AuthController.php:106` |
| U1 | God-Components: ManagementContractView 654 Z., OrgDetail 379, ClientCart 361 | `frontend/src/ui/**` |
| U2 | Dupliziertes Form-Muster (Load→Reset→disabled) | `BillingDetailsCard` u. a. |
| U3 | CSP-Favicon-Script → leerer Prod-Screen (stale Hash) | `AGENTS.todo.md:54` |

**Priorisiert:** (1) A1 durchziehen (reduziert A3/A4/S1), (2) A2 split, (3) S1 Policies, (4) U3/S2 CSP externalisieren, (5) S3/S4/S5 dokumentieren.

**Entscheidungen (interaktiv geklärt 2026-08-13):**
- [x] **S3 (gehärtet):** `JWT_REFRESH_TTL` von 20160 → **10080 (7 Tage)**. Rolling bleibt (`refresh_iat=true`), aber hartes Re-Auth-Fenster. → Task T4.
- [x] **S4 (akzeptiert):** `X-Accel-Redirect` ist interne Optimierung, Caddy konsumiert ihn (`handle_response @accel_header`, `root /srv/photos`) — Header erreicht den Client nie. Zusätzlich: stale `sha256`-Hash aus dem Caddyfile entfernen (Script ist seit T1 extern). → Task T6 (Caddyfile extern) + Doku.
- [x] **S5b (env-driven):** Super-Admin via ENV (`ADMIN_EMAIL`), kein hartcodierter Name im Source. Generischer Fallback `admin@example.com` (statt `florian@reisinger.pictures`). → Task T5. **Folge-Fund:** Die E-Mail dient als magische Super-Admin-Identität (Brand `brand=null`) in `V004:145`, `V018:129/133/293`, `DatabaseSeeder:17`, `E2ETestUserSeeder:21/31`, `DatabaseSeeder:123` (`company_email`) — wird in T5 mit bereinigt. Historische `features/*.md`-Notizen + `README.md` werden separat dokumentarisch angepasst.

### Umsetzung 2026-08-13 (2. Batch, delegiert + verifiziert)

- [x] **T4 (S3)** `config/jwt.php` Default `refresh_ttl` → 10080 + `backend/.env.example` + `backend/.env.ci` auf `JWT_REFRESH_TTL=10080`. **Verifikation approved** (Diff exakt, `git grep JWT_REFRESH_TTL=20160` leer, AuthControllerTest grün). **Manuell (Prod):** `JWT_REFRESH_TTL` in Portainer auf 10080 ziehen; lokale gitignored `.env` optional angleichen.
- [x] **T5 (S5b)** Admin-Email env-driven: `config/admin.php` Fallback `admin@example.com`; `AuthController::resetPassword` nutzt `config('admin.email')`; `DatabaseSeeder` + `E2ETestUserSeeder` + `V004` + `V018` auf `env('ADMIN_EMAIL', 'admin@example.com')`; Regressionstest (403-Guard). **Verifikation approved:** V018-Semantik (Super-Admin `brand=null` exklusiv via Admin-E-Mail) intakt, `env()` nicht in Query-Closures, Grep sauber (nur GmailRestTransportTest-`from()`-Fixtures), **volle Suite 1168 passed / 0 failed**. Doku: `features/security/env-hardening.md` §Admin-Identität, `README.md` Login-Daten.
- [x] **T6 (S4/CSP)** Caddyfile: `sha256`-Hash aus `script-src` entfernt + Kommentar bereinigt (`script-src 'self' https://js.stripe.com`). **Verifikation approved** (kein `sha256` mehr, X-Accel-Redirect-Handling unverändert). **Manuell (Prod):** `caddy reload` auf dem Server.

**⚠️ Environment-Lücke (erledigt):** Meilisearch (127.0.0.1:7701) läuft wieder (health 200) — die volle `php artisan test`-Suite ist **grün (1168 passed / 0 failed)**, die zuvor dokumentierte Meilisearch-Lücke ist damit geschlossen.

**Doku (Main Model):** S3 → `features/auth/01-roles-and-access.md` §Session Lifetime; S4 → `features/infrastructure/01-deployment.md` §4 Sicherheitshinweis; S5b → `features/security/env-hardening.md` §Admin-Identität + `README.md` Login-Daten.

### Umsetzung 2026-08-13 (delegiert, je eigener Implementer + separater Verifikator)

- [x] **T1 (Frontend)** CSP-Favicon externalisiert: Inline-Script → `frontend/public/brand-favicon-rewrite.js`, `<script src>` in `index.html`, Doku `01-deployment.md` §3 angepasst. **Verifikation approved:** `pnpm build` + `eslint` + `lint` grün; Script-Reihenfolge im `<head>` funktional unverändert. **Offen (manuell, extern):** `sha256`-Hash aus dem Caddyfile entfernen + Server-Reload.
- [x] **T2 (Backend)** `/test/flush-queue` nur noch in `local|testing` registriert. **Verifikation approved:** `route:list` in production ohne `/test/*`; Sanity-Test grün.
- [x] **T3 (Backend)** `AccessControlService` → `AuthorizationService` (A1 Schritt 1, additiv): 11 Prädikate + Rekursions-Guard; keine Caller-Migration. **Verifikation approved:** kein `AccessControlService`-Rest, kein `$user->is_` im Service, Prädikate semantisch identisch zu den Accessoren/`GalleryPolicy::manage`; 150 Scoped-Tests grün (`AuthorizationServiceTest` 55, `UserPermissionLogicTest` 74, `GalleryTreeServiceTest` 17, `AuthorizationTest` 4).

**⚠️ Environment-Lücke (RESOLVED):** Meilisearch war zu Sessionbeginn aus (Docker/Rancher Desktop aus). Nach Docker-Start (health 200) läuft die volle `php artisan test`-Suite **grün (1168 passed / 0 failed)** — siehe 2. Batch (T5-Verifikation).

---

## ✅ ERLEDIGT — Volume-Licensing-Presets: konfigurierbare Staffeln pro Brand + pro Gallerie (2026-08-13)

**Ergebnis (SOLL-Doku):** `features/infrastructure/27-volume-licensing-presets.md`.

**Entscheidungen (interaktiv geklärt 2026-08-13):**
1. **Rabatt-Semantik:** RETROAKTIV beibehalten (Gesamtmenge → Einheitspreis für alle Bilder), nur variable Staffelzahl.
2. **Gallery:** Lizenzmodus-Select (existierte schon) + neuer **Volume-Preset-Dropdown** (nur bei volume_licensing).
3. **Alt-Settings `srp_*`:** werden beim ersten Zugriff in ein auto-erzeugtes „Standard"-Preset pro Brand migriert.

**Umgesetzt:**
- Migration **V029** (`volume_presets`, `volume_preset_tiers`, `galleries.volume_preset_id`) + Seeder.
- `VolumePreset`/`VolumePresetTier`-Modelle, `VolumePresetService` (Default-Erzwingung, srp-*-Migration, CRUD, Delete-Constraints).
- `VolumeLicensingStrategy` auf `(VolumePreset, ?CouponService)` umgestellt (variable Staffeln, retroaktive `tier_breakdown`-Step-Diffs); Caller (`AppServiceProvider`, `CheckoutService`) aktualisiert.
- `CheckoutService` gruppiert nach `(mode, preset)`; Volume-Gruppen immer via Multi-Strategy (Gallery-Preset schlägt Brand-Default).
- `VolumePresetController` + Routen (GET für Management-Rollen, Schreiben super_admin); `SettingsController::getLicenseTerms` liefert `volume_pricing.tiers`.
- `GalleryRequest`-Validierung + `GalleryService::assertPresetForBrand` (Cross-Brand 422).
- Frontend: `PricingSettingsTabs` (Lizenz-Katalog + Volume-Pricing als 2 Tabs, responsive), `VolumePresetSettingsCard` (Staffel-Editor), `GalleryModal`-Preset-Dropdown, `useVolumeLicensing`/`VolumeLicensingCard`/`CartItemList` ohne Hardcodes (tierIndex/isMaxTier, Konfig aus API).

**Tests:**
- PHPUnit: `VolumePresetServiceTest` (10), `VolumePresetControllerTest` (7), `VolumeLicensingStrategyTest` (9, variabel), `MixedCartPricingTest` +2 (Gallery-Preset-Override), `SettingsControllerTest` +3 (volume_pricing), `GalleryUpdateDeleteTest` +2 (Cross-Brand-Guard). Gesamt: **1129 passed**.
- Vitest: `useVolumePresets.test` (5), `useVolumeLicensing.test` (18, variabel), `CartItemList.test` angepasst. Gesamt: **580 passed**.
- Playwright: `volume-presets.spec.ts` (CRUD + Gallery-Zuordnung, `@feature:admin:volume-pricing`, 1× `@smoke`); **58 @smoke + Spezifische Specs grün**.
- Per E2E entdeckte Bugs: Formular-Submit ohne `preventDefault` (Reload) + Cross-Brand-Enum-Vergleich — beide gefixt + Regressionstests.

---

## ✅ GELÖST — Produktions-Build blank: Lingui module-scope `t` + stale CSP-Hash (2026-08-12)

**Symptom (nach Deploy):** `portal.reisinger.pictures` zeigte eine leere Seite. Konsole:
1. `Lingui: Attempted to call a translation function without setting a locale` (pageerror) → App crasht, Body leer.
2. CSP-Violation für das Inline-Brand-Favicon-Script in `frontend/index.html`.

**Befund 1 (Lingui, Root Cause):** Im **Produktions-Bundle** (rolldown) evaluieren statisch importierte Shell-Chunks **vor** `i18n.activate("de")` in `I18nProvider.tsx`. Module-scope `t`-Schemas in Shell-Komponenten rufen daher `i18n._()` vor der Aktivierung auf → throw. **Dev-Server (native ESM) reproduziert das NICHT** — nur via `pnpm build` + `pnpm preview`. Betroffen: `SidebarLoginForm` (`loginSchema`), `GalleryModal` (`gallerySchema`), `GalleryGroupModal` (`groupSchema`) — alle statisch über `App`/`PageLayout`/`DashboardLayout` erreichbar.
- **Fix:** Schemas als **Factory-Funktionen** (`createXSchema()`) → Aufruf im Component-Body. `main.tsx` importiert `I18nProvider` zusätzlich als ersten Import (Safety).
- **Verifikation:** `pnpm build` → `pnpm preview` (4173) → Playwright-Console-Capture: kein pageerror, Body rendert. Dev (4321) war nie betroffen.
- **Regel dokumentiert:** `frontend/AGENTS.md` → „Lingui / i18n — No Module-Scope `t` (STRICT)".
- **Regressions-Guard (manuell):** Nach Shell-Komponenten-Änderungen `pnpm build` + `pnpm preview` laden und Console auf Lingui-pageerror prüfen. Ein automatisierter Guard (E2E gegen Preview statt Dev-Server) ist Backlog-Kandidat.

**Befund 2 (CSP-Hash, vorbestehend seit 2026-08-04, Commit `f92fb05`):** Caddyfile-Whitelist hatte `sha256-eHle+…`, aktuelles Inline-Script ergibt `sha256-2JqhWWJ9opkxqNUVZFFDVhn9EDRVGV651m6NLGm9waI=`. **Fix:** Hash im Caddyfile korrigiert (lokal `/Users/florianreisinger/dev/caddyfile/Caddyfile`) — **Server-Reload nötig!** Stripe war nicht betroffen — es ist im `script-src` explizit erlaubt.

- [x] **TODO (Automatisierung CSP):** Inline-Brand-Script aus `frontend/index.html` in statische Datei (`frontend/public/brand-favicon-rewrite.js`) externalisiert (2026-08-13, Task T1) → `script-src 'self'` deckt es ab. **Rest (manuell):** Hash aus dem Caddyfile entfernen + Server-Reload (Caddyfile liegt extern unter `/Users/florianreisinger/dev/caddyfile/Caddyfile`).

---

## 🔄 OUTDOOR-WERT: calc_outdoor_multiplier → calc_outdoor_images_per_hour (2026-08-12)

**Problem (User-Report):** „Ein Outdoor-Foto-Bonus von 1/3 ist nicht möglich." Der Outdoor-Faktor war ein Prozent-Multiplikator (`calc_outdoor_multiplier`, Default 0,5 = 50 % Bildpreis); via `step="5"`-Input nur in 5er-Schritten (10–100 %) eingebbar → 1/3 (= 33,33 %) nicht setzbar.

**Entscheidungen (interaktiv geklärt 2026-08-12):**
1. Outdoor-Wert wird eine **Anzahl Bilder pro Stunde** (immer ganze Bilder): Basis Indoor `calc_images_per_hour` = 6 / Outdoor `calc_outdoor_images_per_hour` = 8.
2. Berechnung: `imagesPrice = (hourlyRate / outdoorImagesPerHour) * images` (statt Multiplikator auf den Bildpreis).
3. Migration **preiserhaltend konvertieren**: `new = round(calc_images_per_hour / calc_outdoor_multiplier)` (Default 6/0,5 = 12). Seeder-Default: 8 (frische Installs).
4. Neuer Key `calc_outdoor_images_per_hour`; alter Key `calc_outdoor_multiplier` wird per Migration gelöscht.

**Umsetzung delegiert (Implementer + Verifikator, AGENTS.md §4):**
- [x] Backend: `V028__calc_outdoor_images_per_hour.php` (idempotente Daten-Migration, pro Brand)
- [x] Backend: `SettingsController` GET-Feld + PUT-Validierung (`nullable|integer|min:1`)
- [x] Backend: `DatabaseSeeder` 0.5 → 8
- [x] Backend-Tests: `ShootingCalculatorSettingsTest` (GET-Feld, Persistenz, 422-Fälle, Migrations-Konvertierung 0.5→12 + custom)
- [x] Frontend: `shootingCalculator.ts` (neues Feld, Formel), `useLicenseTerms.ts`, `CalculatorSettingsCard.tsx` (Label „Outdoor-Bilder/Std.", Integer-Validation), `ShootingCalculatorModal.tsx` (Label „Bilder/Std.: X")
- [x] Frontend-Tests: `shootingCalculator.test.ts` (neue Erwartungswerte, mit Testlauf verifiziert)
- [x] E2E: `package-calculator-config.spec.ts` (Label, Wert 20 → erwartete 229.00 € bleibt)
- [x] Verifikation: `php artisan test` (1103 passed), `pnpm test:run` (591 passed), `pnpm lint:fix && pnpm build` (0 Fehler), E2E `@feature:admin:calculator` (4/4) + `@smoke` (56/56)

---

## 🔄 portal-base:8.5 — Spezialisiertes Image im Portal-Repo (2026-08-07)

**Ziel:** Das Base-Image-Repo `reisi007/docker-base-images` (lokal `~/dev/php-apache-mod2rewrite`) wird **komplett entfernt** (lokal + Remote + GHCR-Packages), da das Portal der einzige Konsument ist. Das spezialisierte Image `ghcr.io/reisi007/portal-base:8.5` (PHP 8.5, mysql, Dockerfile 1:1 aus dem Base-Repo) wird künftig **per Cron (täglich 01:00)** aus **diesem** Repo gebaut.

**Umsetzungsplan:**
- [x] `deployment/Dockerfile` anlegen (identischer Inhalt wie Base-Repo-Dockerfile)
- [x] `.github/workflows/base-image.yml`: cron `0 1 * * *` + `workflow_dispatch` + `push` (paths: Dockerfile + Workflow); baut **NUR** `portal-base:8.5`/`latest` (PHP 8.5, mysql)
- [x] Commit 1 pushen → CI bleibt auf `php-base:8.5` (grün), base-image-Run baut `portal-base:8.5`
- [x] **portal-base:8.5-Run grün abwarten** (Gate für Löschung) — inkl. Multi-Arch-Fix (amd64+arm64, Commit `1f37a82`); `exec: php-fpm: not found` war Emulations-Problem auf Apple Silicon, arm64-Variante läuft nativ
  - [x] 2026-08-08: **arm64-Variante wieder gestrichen** (Cron-Build grün, aber arm64 nicht benötigt) → `platforms: linux/amd64` in `base-image.yml`
- [x] Commit 2: Referenzen `php-base:8.5` → `portal-base:8.5` in `deployment/docker-compose.yml:56`, `.github/workflows/ci.yml` (55, 67, 95, 291, 305), `backend/.env.ci` (4–5), `features/infrastructure/01-deployment.md:25`
- [x] CI nach Commit 2 grün (Run `31187394565` success)
- [ ] **⚠️ Prod manuell updaten:** `docker-compose.yml` referenziert jetzt `portal-base:8.5` → Portainer Stack-Redeploy nötig (User-Notify erledigt)
- [x] Base-Repo löschen: lokaler Klon (`~/dev/php-apache-mod2rewrite`) + `gh repo delete reisi007/docker-base-images` (2026-08-07, nach grünem portal-base-Run)
- [x] GHCR-Packages aufräumen: `php-base` (30 Versionen), `php-mysql` (20), `php-postgres` (20) gelöscht; `portal-base` verbleibt (frisches Package, gehört dem Portal-Repo)

---

## ✅ GELÖST — CI-E2E-Run 31160185815: 4 rote Tests gefixt (2026-08-07)

**Status: lokal grün; Commit + Push; CI beobachten.** Meine Änderung (nur `rclone-backend-filter.txt`) hatte 4 E2E-Failures aufgedeckt, die in Run `31032939108` (grün) noch nicht existierten → nach `93707b3` (React-Compiler, CI damals cancelled) entstanden.

**Befund & Fixes:**
1. **`billing-details.spec.ts:20` + `:55`** — schreiben **globale brand-weite Bankdaten-Settings** (`/api/management/settings/billing-details`). Desktop- UND Mobile-Shard fuhren dieselben Tests parallel (2 Shards × 2 Worker) gegen dieselbe DB → gegenseitige Überschreibung (nach Reload anderer Inhaber/IBAN; IBAN-Validierung sah fremde Daten). **Fix:** `ci.yml` → billing-details in den seriellen Shard (`--grep "projects-board|production-board|billing-details" --workers=1`), aus Desktop/Mobile-grep-invert. Lokal simuliert (Desktop+Mobile parallel) grün, aber deterministisch nur durch Serialisierung gelöst.
2. **`metadata.spec.ts:122` (Copyright)** + **`photographer.spec.ts:113` (FTP slug)** — Root Cause identisch: `ProfileSettingsCard.tsx` hatte einen `useEffect`-Reset ohne `!isDirty`-Guard (Muster `BillingDetailsCard.tsx:52`). SWR-Hydration von `/api/auth/me` konnte nach dem Füllen die getippten Werte (`metadata_copyright` / `ftp_slug`) überschreiben → leer persistiert → `artist` fiel auf `name`, FTP-Inbox zeigte User-ID statt Slug. **Fix:** `!isDirty`-Guard im `useEffect` (Deps `[user, profileForm, isDirty]`). Zeitbasiert → wirkte "flaky".
3. **19× `"use no memo";`** in Formular-Komponenten (lokale React-Compiler-Opt-Outs, konsistent mit `BillingDetailsCard`) — mit in den Commit aufgenommen.

**Verifikation lokal:** billing (2) + metadata + photographer-Files (10) + `@smoke` Desktop (28) + Vitest (591) + lint + build grün.

**Hinweis für CI-Beobachtung:** Shard-Name geändert in `serial (kanban, billing)`. Erwartung: Desktop/Mobile grün, serial-Shard grün.

---

## ✅ GELÖST — CI-Stripe-Failures (401 + "Postleitzahl ist ungültig") — 2 Root-Causes (2026-08-05)

**Finaler Run `30995040128` grün:** Backend + Frontend + alle 4 E2E-Jobs (3 Shards à 88 + Kanban 33) = 297 passed, 0 failed, 0 flaky, ~9 min E2E-Wall-Time.

1. **CI-401 `Invalid API Key provided: sk_test_************der>`** → `php artisan serve --no-reload`:
   - Laravel `ServeCommand` filtert Env-Vars raus, die nicht in `$passthroughVariables` stehen (nur `APP_ENV`/`PATH`/XDEBUG/…), **sobald `.env` existiert** (kein `--no-reload`). Das per Container-`-e` injizierte `STRIPE_SECRET` fehlt in der Liste → Serverprozess fällt auf den `.env.ci`-Platzhalter `sk_test_<ci_placeholder>` zurück → Stripe 401. `der>` = last4 von `<ci_placeholder>` (Stripe maskiert den Key: `sk_test_` + Sterne + last4).
   - **Beweis:** Pipeline-Nachstellung im arm64-Build des php-base-Images (QEMU-SIGSEGV durch nativem arm64-Build umgangen): ohne `--no-reload` wird `STRIPE_SECRET` im ServeCommand-Child auf `false` gesetzt (gefiltert), mit `--no-reload` überlebt es (len 107). Fingerprint/Dump (`php -r`) zeigt den echten Key, der serve-Prozess nicht — daher die scheinbare Contradiction.
   - Fix in `ci.yml` "Start backend server": `--no-reload` (mit Kommentar als Regressionsschutz).
2. **"Postleitzahl ist ungültig" / "Your ZIP is invalid" (nach 401-Fix)** → Billing-Adresse an Stripe übergeben:
   - Stripe PaymentElement sammelt eine PLZ mit **US-Default-Country**; österreichische PLZ `1010` (4-stellig) ist dafür invalide. Nur in CI reproduzierbar (Pipeline-Replica lokal 6/6 in beiden Locales/Workern).
   - Fix: `ClientCartView` speichert die Checkout-Billing-Adresse (`setCheckoutBilling`) → `StripeCheckoutForm` übergibt `billingDetails.address` (line1, postal_code, city, `country: 'AT'`) an das PaymentElement. Unit-Test ergänzt (`StripeCheckoutForm.test.tsx`).

**Nebenfunde/-Fixes (im selben Zuge):**
- **Kanban-Flakes deterministisch gefixt:** dedizierter serieller Kanban-Shard (`--grep 'projects-board|production-board' --workers=1`) + `production-board.spec.ts` `mode: 'serial'` (projects-board hatte es schon) + `waitForDelete`-**Race** (Promise VOR "Löschen"-Klick registrieren, analog `94c3fd6`). Die Flakes kamen von `fullyParallel` auf derselben DB im Shard.
- **Sharding (behalten):** 3 parallele Shards (`--workers=2`, `--grep-invert` kanban) + 1 Kanban-Shard. Nicht weiter aufteilen (Setup-Dominanz); nicht weniger (längere Wall-Time); Kanban nicht in parallele Shards integrieren (DB-Interferenz zwischen den beiden Board-Dateien).
- **de-AT-Locale-Experiment (`b77d532`) brachte NICHTS** (ZIP-Fehler blieb, nur Meldung deutsch) → **zurückgerollt** (`a1af17e`). Deutsche Decline-Regex in `stripe-checkout.spec.ts` blieb (harmlos, robust für beide Locales).
- **Keys verifiziert unverändert:** GitHub-Secrets == lokal (pk `pk_test_51TJ…JRJ8` md5 `869894885b…`, sk `sk_test_51TJ…vPQD` md5 `4f1bf871…`). Kein Restricted-Key.
- **Nicht gelöst/offen:** `metadata.spec.ts:31` + `metadata-defaults.spec.ts:38` (Location-Autocomplete "Salzburg"/"Graz") flaky in CI, lokal grün → separate Beobachtung.

---

## 🟡 OFFEN — CI-E2E-Run 30954666385: Flakes analysiert (2026-08-05)

**Run:** 286 passed, **9 failed, 2 flaky**. Lokal reproduziert (Herd, Vite :4321):
- ✅ **manual-documents.spec.ts:146 (deterministisch) — GEFIXT:** Strict-Mode-Violation (3 `<select>` im Modal) → Locator auf `.form-control` mit `hasText: 'Rabatt-Stufe'` gescoped. Volle Spec lokal grün (Desktop+Mobile).
- ✅ **empty-feed.spec.ts:33 (Mobile-only):** Assertion zählt Console-Fehler; kein `console.error` im App-Code → der CI-Error ist Resource-Load-Noise (Tracking-Domain aus GH-Runner-IP). Filter um `'Failed to load resource'` erweitert (App-Fehler werden weiterhin erfasst).
- ⚠️ **stripe-checkout.spec.ts:106/138 + quote-checkout.spec.ts:29 (CI-only):** Backend 502 „Die Zahlung konnte nicht verarbeitet werden" = `CheckoutService.php:400` fängt Stripe-SDK-Exception. Lokal (Herd) 3/3 grün → Environment-Flakiness (Rate-Limit / ApiConnection von CI-IPs, nicht reproduzierbar — Container-Repro durch QEMU-SIGSEGV blockiert). **Fix:** `test.describe.configure({ retries: 2 })` in beiden Specs + neuer CI-Step „Validate Stripe API key" (curl `/v1/balance` → lauter Fehler, falls Secret stale). Beim nächsten CI-Run prüfen: (a) Validation-Step grün? (b) Stripe-Tests grün?
- ⚠️ **projects-board.spec.ts:69 (flaky):** retry grün, lokal 3× grün — beobachten.
- **Nächster Schritt:** ci.yml (Summary-Header + Validation-Step) + Test-Fixes committen & force-pushen → CI-Run → wenn Stripe weiter fehlschlägt: playwright-report-Artifact (enthält Console-Logs) herunterladen und echten Stripe-Fehler extrahieren.

---

## 🟡 OFFEN — CI-E2E-Run 30979963995: Stripe weiter rot, projects-board-Race gefixt (2026-08-05)

**Run:** 287 passed, **7 failed, 1 flaky, 2 did not run.**
- ✅ **manual-documents + empty-feed-Filter waren grün** (deterministische Fixes halten).
- ✅ **projects-board:69/173 (Race) — GEFIXT:** CI-Snapshot zeigte leeres Board → DELETE lief bereits, aber `waitForResponse` war erst NACH dem „Löschen"-Klick registriert und verpasste die Response. Fix: Promise **vor** Klick registrieren (beide Delete-Tests). Volle Spec lokal 11/11 grün.
- ❌ **Stripe weiter rot (6×, Desktop+Mobile):** `stripe-checkout:110/142` + `quote-checkout:33`; jetzt **3 Attempts** (describe-retries: 2) je Test, alle failed → deterministisch. **Neuer Validate-Step (read+write) war grün** (balance=200), aber `paymentIntents->create` (write) schlägt im eigentlichen Flow fehl → Verdacht **Restricted-Key** (Read ja / Write nein) oder Rate-Limit. Nächster Run: Validate-Step testet jetzt explizit `payment_intents create` (write) + „Dump backend log" (60 Zeilen `storage/logs/laravel.log` mit echter Stripe-Exception aus `CheckoutService::respondBasedOnPayment` catch).
- Lokale Repro im php-base-Container (QEMU, Apple Silicon): curl `api.stripe.com/v1/balance` = 200, Stripe-SDK `paymentIntents->create` (Dev-Key aus `backend/.env`) = **OK** → Container-Netz/SSL/SDK sind nicht die Ursache; Diff ist der CI-Secret.
- **Falls Validate-Step (write) rot:** CI-Secret `STRIPE_SECRET` prüfen — muss die **volle sk_test** aus `backend/.env` sein, nicht ein Restricted-Key (Restricted Keys: `/v1/balance` liest ok, aber `payment_intents`-Write fehlt → exakt das Symptom).

---

## 🟡 OFFEN — Kanban: PDF-Drop auf Projekte-Seite — E2E-Test fehlt

Funktionalität vorhanden via `useProjectPdfDrop` (vorbefüllt client_name/email/amount/package, verdrahtet in `ManagementProjectsBoard.tsx`). **Offen: E2E-Verifikation** — kein Test in `frontend/tests/e2e/admin/projects-board.spec.ts`.

---

## 🟡 OFFEN (Future) — pricing_strategy als Brand-Setting in der UI konfigurierbar

**Kontext (Entscheidung 2026-08-04):** Coupon-Feature ist vom Lizenzmodus entkoppelt („offer both" — beide Lizenzmodelle immer mit Coupons; Gates in `Sidebar.tsx`/`ManagementCouponsView.tsx`/`CouponInput.tsx` entfernt). Langfristig soll das Brand-weite `pricing_strategy` (inkl. Coupon-Verfügbarkeit) per **Admin-UI konfigurierbar** sein.

**Umsetzung (Future-TODO, dokumentiert in `features/infrastructure/17-pricing-strategy-pattern.md` §7):**
- DB-Overlay-Muster (Option B, wie F3): `settings`-Tabelle (PK `(key, brand)`, V019), `config/brands.php` = Default/Fallback.
- Setting `pricing_strategy` je Brand in der Admin-UI editierbar (`BrandSettingsService`/`SettingsController`, Choke-Point `BrandRegistry::buildFromArray()`).
- Per-Gallery-Override (F2, `galleries.licensing_mode`) bleibt und hat Vorrang vor dem Brand-Setting.

---

## 📋 AUSGEARBEITETE BACKLOG-PLÄNE (2026-08-04)

> Nur Ausarbeitung (Planung) — noch **kein Code** geändert. Umsetzung erfolgt in separaten Sessions durch Implementer-Subagenten + Review durch Verifikator (AGENTS.md §4). **Offene Fragen wurden interaktiv geklärt (2026-08-04) und sind in den Plänen als Entscheidungen dokumentiert.**

---

### 🔙 A1 — User-God-Entity entschärfen + Role-Prüfungen konsolidieren

**Ziel:** Rollen-/Autorisierungslogik aus `backend/app/Models/User.php` in einen **konsolidierten `AuthorizationService`** (Umbenennung von `AccessControlService`, Entscheidung 2026-08-04) überführen; Scatter (~170 direkte `is_*`-Prüfungen in 20+ Dateien) beseitigen; N+1 Role-Queries beheben. Serialisierung (`$visible`, `AuthController::me()`, `UserResource`) bleibt unverändert → kein API-Break.

**Bestandsaufnahme (Kern):**
- God-Entity: `User.php:24–128` — `getIsSuperAdminAttribute` (24–27), `getIsPendingAttribute` (80–84), `getIsPhotographerAttribute` (86), `getIsAdminAttribute` (87), `getIsOrgAdminAttribute` (88), `getIsPowerUserAttribute` (91), `getAllowedGalleryIds` (93–96), `canPhotographerAccessGallery` (98–117), `canAccessGallery` (119–128).
- `hasPurchasedPhoto` (130–162) = Kauf-/Bestelllogik, **kein Rollen-Thema** → bewusst NICHT Teil von A1.
- Scatter gruppiert: `is_admin` 53× (Controllers, Policies, Requests, Provider), `is_super_admin` 34×, `is_photographer` 41×, `is_org_admin` 18×, `canAccessGallery` 16×, `canPhotographerAccessGallery` 7×, `getAllowedGalleryIds` 8×.
- **Rekursions-Falle:** `AccessControlService.php:57` ruft `$user->is_photographer` (Model-Accessor!) → sobald Accessor → Service delegiert, rekursiv. MUSS mitfixiert werden.
- Gates in `AppServiceProvider.php:72–97` (`manage-catalog`, `manage-users`, `purchase-upgrades`) nutzen rohe `pluck('name')`-Blocklisten.

**SOLL-Architektur:** Konsolidierter **`AuthorizationService`** (vorher `AccessControlService`) mit `hasRole(User, ...roles)`, `roleNames(User)`, `isSuperAdmin/isAdmin/isPhotographer/isPowerUser/isOrgAdmin/isPending/isClient/isPrivileged(User)`, `canAccessGallery(User, id)`, `canPhotographerAccessGallery(User, id)`, `canManageGallery(User, id)` (Komposit). **Regel: Service referenziert NIE `$user->is_*`-Accessor** (Rekursionsschutz); alle Prädikate via `$user->loadMissing('roles')`. Model wird dünne Delegation (1-Zeilen-Delegates), Relations bleiben.

**Priorisierte Migrationsschritte (jeder einzeln grün testbar):**
1. Service erweitern + umbenennen in `AuthorizationService` (rein additiv, keine Caller-Umbauten) — `AuthorizationServiceTest` (umbenannt aus `AccessControlServiceTest`).
2. Model auf Delegation umstellen — Guard: `AuthorizationTest`, `UserPermissionLogicTest`, `GalleryTreeServiceTest:304`.
3. Gates konsolidieren + Semantik verfeinern (`AppServiceProvider`) — Guard-Tests der Ist-Bool-Ergebnisse + neue `isClient`/`isPrivileged`.
4. Middleware (`SuperAdminMiddleware:14`, `ManagementMiddleware:21,28,37`) — Guard: `RoleAbortTest`.
5. Policies (`GalleryPolicy:15,20`, `PhotoPolicy:13,20,27,38,44,50` — 5×-Komposit `is_super_admin||is_admin||(is_photographer&&canPhotographerAccessGallery)` → `canManageGallery`).
6. Controller nach Fachgebiet (6a–6f, je eigener Commit): User/Org → Gallery/Frontend/Image → PhotoDownload/FileDelivery → Search/Mail/Notification/CheckoutService → Requests → Rest.
7. (Optional) `hasPurchasedPhoto` als eigener Backlog-Item extrahieren — NICHT in A1.

**Test-Strategie:** `AuthorizationServiceTest` (alle Prädikate, Prezedenz, Guest), neuer N+1-Regressionstest, bestehende Suiten als Guard (s. Schritt 2), E2E-@smoke nach jedem Schritt.

**Risiken:** Verhaltensdrift (`isAdmin` ⊃ `SuperAdmin`, `canAccessGallery`-Prezedenz), Model↔Service-Rekursion, Cache-Korrektheit (`unrestricted_photographer_gallery_ids`), `MailController.php:83–84` (`whereHas('roles')` ist Query, nicht pro-User-Check — darf NICHT in Service).

**Entscheidungen (interaktiv geklärt 2026-08-04):**
1. **Accessor bleiben dauerhaft** als dünne 1-Zeilen-Delegates im Model (kein API-Break, kein Frontend-PR). `$visible`/`me()`/`UserResource` unverändert. KEINE 2. Phase.
2. **Umbenennen in `AuthorizationService`** (statt `AccessControlService`) — inkl. Test-Datei (`AccessControlServiceTest.php` → `AuthorizationServiceTest.php`) und allen Referenzen.
3. **`ManagementMiddleware`: Path-Prefix-Logik bleibt in der Middleware**; nur die Rollen-Prädikate werden über den Service aufgelöst.
4. **Controller-Migration (Schritt 6): eigene Commits je Fachgebiet (6a–6f), direkter Push nach master wenn Tests grün**; wo fachlich unabhängig, parallel an Subagenten delegieren, aber Commits sauber splitten.
5. **Gates: Semantik verfeinern** (nicht 1:1) — `isClient`/`isPrivileged` und die Gate-Definitionen (`AppServiceProvider:79–97`) werden logisch neu modelliert; bestehende Bool-Ergebnisse je Gate vor der Umsetzung als Guard-Test einfrieren.

---

### 🔙 F3 — Admin-UI für Brand-Einstellungen (nur Settings, kein Full-CRUD)

**Ziel:** Admin-UI zum Editieren konfigurierbarer Brand-Felder. **Architektur-Entscheidung (getroffen):** Option **B — DB-Overlay** auf bestehender `settings`-Tabelle (PK `(key, brand)`, V019); `config/brands.php` bleibt Default/Fallback. Config-Write-Layer (Option A) verworfen (nicht haltbar / Deploy überschreibt / kein Audit). Kein Migration-Bedarf (V028 bleibt frei).

**Bestandsaufnahme (Kern):**
- Overridable Whitelist: `name`, `portal_name`, `impressum_url`, `primary_color`, `secondary_color`, `frontend_url`, `from_address`, `from_name`, `accounting_email`, **`features.orgs`** (Entscheidung 2026-08-04). Config-only (NICHT overridable): `theme`, `logo_path*`, `hostnames`, `is_active`. Dead-Flags `features.coupons`/`features.volume_licensing` werden aus `config/brands.php` entfernt (real schaltet `pricing_strategy`).
- Einbaupunkt: `BrandRegistry::buildFromArray()` (`BrandRegistry.php:156–177`) = Choke-Point aller 23 BrandConfig-Konsumenten (Middleware, Mails, Queue, public `brand-config`).
- Queue: `BrandRegistry::clearCache()` zusätzlich im `Queue::before`-Hook (`AppServiceProvider.php:123–125`).
- UI-Muster: `BillingDetailsCard.tsx` (react-hook-form + zodResolver, Load→Reset-Hydration, `disabled={!canEdit}`, showToast). Einbau als Card in `ManagementSettingsView.tsx:63–68`. Kein neuer Sidebar-Eintrag/Route nötig.

**SOLL-API-Vertrag** (`routes/api.php` im `management`-Block):
- `GET /api/management/brand-settings` → `{brands:[{id, editable_fields, defaults, overrides, effective}]}`.
- `PUT /api/management/brand-settings/{brand}` (+ `super_admin` Middleware, Muster `api.php:183`) → partial Payload; `null`-Wert = Reset auf Config-Default (löscht DB-Row). Response `{success, effective}`.
- Validierung: `StoreBrandSettingsRequest` (Route-Brand `Rule::in(array_keys(config('brands')))`; hex `regex:/^#[0-9a-fA-F]{6}$/`, email, url; Whitelist via `only()`). Frontend-Zod-Schema spiegelnd.
- Write via `BrandSettingsService` (neuer Service) mit `Setting::updateOrCreate(['key','brand'])` — NICHT `SettingResolver::set()` (der hängt am Host-Kontext).

**Umsetzungsplan (priorisiert):**
1. `BrandSettingsService` + Merge in `buildFromArray()` + Queue-Cache-Clear — `BrandSettingsServiceTest`, `BrandRegistryTest`.
2. Endpoint: `StoreBrandSettingsRequest`, `SettingsController::getBrandSettings/updateBrandSettings`, 2 Routen — `BrandSettingsControllerTest`.
3. Frontend: `useBrandSettings.ts` + Vitest.
4. `BrandSettingsCard.tsx` + Einbau in `ManagementSettingsView.tsx` — `pnpm lint:fix && pnpm build`.
5. Playwright `brand-settings.spec.ts` (`@feature:admin:brand-settings`).
6. Doku: `21-brand-config-driven.md` (§Follow-up F3 → Implementiert), `features/infrastructure/22-brand-settings-overlay.md` (SOLL, nach Implementierung).

**Test-Strategie:** PHPUnit (401/403/200-Matrix, Persistenz mit brand='rp' + `brand_config.*`-Key, 422-Fälle, null-Reset, Merge-Precedence, public `brand-config` reflektiert Override, kein Cross-Brand-Leak); Vitest (Hook, Zod-Schema); Playwright (Super-Admin ändern→persistiert, ungültige Hex→Client-Validierung, Reset, Plain-Admin read-only).

**Entscheidungen (interaktiv geklärt 2026-08-04):**
1. **features.orgs wird DB-overridable** (Whitelist erhält `features.orgs`; Sidebar-Gating `Sidebar.tsx:110` wird darüber gesteuert). **Dead-Flags `features.coupons` + `features.volume_licensing` werden aus `config/brands.php` entfernt** (real schaltet das DB-Setting `pricing_strategy` — `ManagementCouponsView.tsx:85`). `theme` bleibt config-only (Frontend-Theme-Abhängigkeit).
2. **Logos (`logo_path*`) bleiben config-only** (Upload = eigener Scope, nicht F3).
3. **Audit: Ja — einfacher Laravel-Log-Eintrag** bei jedem Brand-Settings-Write (User, Brand, geänderte Felder), da `Setting::$timestamps = false`.

---

### 🔙 Stack-Konsolidierung — Ein Compose statt zwei

**Ziel:** Ein `docker-compose.yml` im Root (Default-Name); `docker-compose.local.yml` + `docker-compose.test.yml` + `docker/test/` löschen; Configs/`.run`/Doku auf einen Port-Satz; `scripts/e2e-up.sh` (existiert NICHT → neu, idempotent).

**Zielbild (SOLL, angepasst an Entscheidungen 2026-08-04):** Ein `docker-compose.yml` im Root mit **generischen Container-Namen** (mysql, mailpit, meili), Standard-Ports, `SCOUT_PREFIX=test_` für Test-Meili-Indizes. **Kein `search-test`-Service.** Grants via `docker/init/01-init.sql` (Portal-Test-DB + Wildcard). Details in den Entscheidungen unten.

**Wichtig (Ist-Zustand):** `backend/.env:27` nutzt `DB_PORT=3307` bei `DB_DATABASE=portal_dev_db` → Dev-Backend läuft heute gegen den **Test-Container**; Local-Container (3306) faktisch ungenutzt. Dev-Daten liegen in Volume `portalreisingerpictures_db_data_test`.

**Risiken:** Dev-Datenverlust (Entscheidung: Option B akzeptiert → Dev-Daten werden verworfen), Parallelbetrieb bricht ohne Wildcard-Grant (Init + e2e-up), Meili-Prefix-Trennung (SCOUT_PREFIX=test_ — Indexnamen `test_photos` etc. sauber getrennt), Port-Kollisionen (erst beide Stacks stoppen), `.run`-Stop-Bug.

**Entscheidungen (interaktiv geklärt 2026-08-04):**
1. **Option B: frischer Start** — Dev-Daten (alte Volume `portalreisingerpictures_db_data_test`) werden **verworfen**. Danach zwingend `migrate:fresh --seed` (AGENTS.md §6). Kein Volume-Kopierschritt. Schritt 3 (Datenmigration) entfällt.
2. **Ein Meilisearch auf 7700** (`search-test`-Service + Port 7701 entfallen). Tests via `SCOUT_PREFIX=test_` (getrennte Indizes `test_photos`, `test_galleries`, …). `phpunit.xml`: `MEILISEARCH_HOST=http://127.0.0.1:7700`, einheitlicher Key (`local_meili_secret`), `SCOUT_PREFIX=test_` ergänzen. Dev nutzt unpräfixte Indizes. Scout-Re-Import (`scout:sync-index-settings` + `scout:import`) nach Setup.
3. **Geteilte Mailpit-Instanz 8025/1025** für Dev + PHPUnit. `backend/tests/Support/MailpitAssertions.php:9` → 8025; E2E-`MailpitHelper` (8025) unverändert. Filterung per Empfänger.
4. **.run-Configs: „Start/Stop Docker (Test)" löschen**; „Start/Stop Docker (Dev)" → `docker compose up -d`/`down`; §9-Namenskonvention anwenden. „DB Migration (Test)" um `--seed` ergänzen.
5. **Generische Container-Namen** (`mysql`, `mailpit`, `meili`?) + Standard-Ports (3306/7700/8025+1025) für lokale Konsistenz und Duplizierbarkeit in anderen Projekten. **Keine separate globale Infra-Compose** — andere Projekte duplizieren die Compose (User-Präferenz: „lieber duplizieren, globale Infra-Compose potenziell nervig"). Konkrete Namen/Volumes bei der Umsetzung festlegen.

**Korrigierter Umsetzungsplan (angepasst an Entscheidungen):**
0. Beide alten Stacks down (`--project-name portal_local` + `portal_test`).
1. Neues `docker-compose.yml` (ein Meili-Service, generische Namen, `docker/init/01-init.sql` mit `CREATE DATABASE IF NOT EXISTS portal_test_db` + `GRANT ALL ON portal_test_db.*` + Wildcard `portal_test_db\_test\_%`); alte Compose-Dateien + `docker/test/` löschen — `docker compose config --quiet`.
2. `docker compose up -d` (frisches Volume → Init-Script läuft) + Grant-Check (`SHOW GRANTS FOR 'portal_user'@'%'`).
3. `migrate:fresh --seed` (Dev-DB aufbauen) — Login mit `florian@reisinger.pictures` verifizieren.
4. Configs: `backend/.env` + `.env.example` DB_PORT 3306; `phpunit.xml` DB_PORT 3306, MAIL_PORT 1025, MEILISEARCH_HOST 7700, MEILISEARCH_KEY `local_meili_secret`, `SCOUT_PREFIX=test_`; `tests/Support/MailpitAssertions.php:9` 8025.
5. `scripts/e2e-up.sh` neu (idempotent: `up -d` → DB-Ready-Wait → Grants → optional `--fresh`+Seed).
6. `.run`-Configs (s. Entscheidung 4).
7. Doku: `README.md:15–34`, `CLAUDE.md:82–85,175,304`, `AGENTS.md:140–153`, `features/security/env-hardening.md:45–46`, `features/search/01-search-and-discovery.md:15–17` → neue Ports/Compose. Grep-Check: keine `3307|8026|1026|docker-compose.test.yml|docker-compose.local.yml`-Treffer mehr.
8. Gesamtverifikation: `php artisan test` (ein Prozess), `php artisan test --parallel` (Worker-DBs auf 3306), `DB_DATABASE=portal_test_db_test_<x>`-Scoped-Run, `pnpm test:e2e:smoke`, `pnpm lint:fix && pnpm build`, Scout-Sync/Import (Meili-Indizes inkl. `test_`-Prefix).

---

## 🔄 DEPENDABOT + CI-Pipeline (2026-08-04, Entscheidungen interaktiv geklärt)

**Ziel:** Dependabot wieder aktivieren (war aktiv bis PR #8, Config wurde entfernt) + CI-Build-Pipeline (lint/test/e2e) + Auto-Merge für grüne patch/minor-PRs. **Kein Branch Protection** (Entscheidung User).

**Entscheidungen (2026-08-04):**
1. **Gruppen nach Risiko:** `minor`+`patch` pro Ecosystem gebündelt in je einem PR; **Major-Updates einzeln** (eigener PR, manueller Review).
2. **Ecosystems:** npm (`/frontend` + `/`), composer (`/backend`), docker (`/deployment` + `/` für local/test-compose). Schedule: **npm+composer täglich, docker wöchentlich**.
3. **Auto-Merge:** ja für patch+minor **nur wenn CI grün** — da kein Branch Protection, via Workflow-Gate (`pull_request_target` + `dependabot/fetch-metadata` → update-type ≠ major → wait-on-check → `gh pr merge --squash`). Docker-Gruppen nie auto-mergen.
4. **CI (`.github/workflows/ci.yml`):** 3 Jobs:
   - `backend`: PHP 8.4, Service-Container **mariadb:11.4 (Port 3307)**, **meilisearch:v1.48.3 (7701)** → `composer install` → `php artisan test` (serial, kein Seed nötig — RefreshDatabase). Ports passen zu `phpunit.xml` (3307/7701/1026).
   - `frontend`: Node 22 + pnpm 9.15.4 → `pnpm install --frozen-lockfile` → `pnpm lint` → `pnpm build` (inkl. tsc+check-i18n) → `pnpm test:run`.
    - `e2e` (nur wenn `secrets.STRIPE_KEY` gesetzt): Services mariadb(3307)/meili(7701)/**mailpit(8025+1025)**; Backend `.env` generieren (APP_KEY/JWT_SECRET via `key:generate`, MAIL_PORT=1025, MEILI auf 7701, STRIPE_KEY=pk_test, STRIPE_SECRET=sk_test, STRIPE_WEBHOOK_SECRET); `migrate --force` + `db:seed` mit `ADMIN_EMAIL=admin@example.com` (E2E nutzt überall `admin@example.com/admin`, s. AuthHelper/E2ESessionHelper); `php artisan serve` auf 8000; Frontend `pnpm dev` auf 4321 mit `VITE_API_PROXY=http://127.0.0.1:8000` + `VITE_STRIPE_PUBLIC_KEY`; `npx playwright install --with-deps chromium`; `CI=1 pnpm test:e2e`.
   - **Dependabot-PRs bekommen KEINE Secrets bei `pull_request`** (Fork-Treat) → CI auf `on: push` (alle Branches, dependabot-Branches sind in-repo) + `on: pull_request`; E2E-Job nur bei gesetzten Secrets. Stripe-Test-Keys als GitHub Secrets hinterlegen (Namen unten).
   - **Seed-Risiko:** `DatabaseSeeder` ruft `app:import-locations` (GeoNames-Download, Netz) — im CI akzeptiert (Runner haben Internet), bei Flakiness Retry/log.
5. **`.github/dependabot.yml`:** npm×2 + composer×2-Update (jeweils groups `update-types: [minor, patch]`; Major ungruppiert) + docker×2 (weekly, eine Gruppe). `open-pull-requests-limit: 8`, `labels: [dependencies]`, `versioning-strategy: auto`.
6. **Repo-Settings (via gh API, nach Files-Merge):** Automated Security Fixes + Vulnerability Alerts aktivieren.

**Stripe-Test-Keys als GitHub Secrets** (User legt an; Quelle `frontend/.env.local` pk_test + `backend/.env` sk_test/whsec_test): `STRIPE_KEY` (pk_test, publishable), `STRIPE_SECRET` (sk_test, secret API-Key — `StripePaymentService` liest `config('services.stripe.secret')` als API-Key), `STRIPE_WEBHOOK_SECRET` (whsec_test), `VITE_STRIPE_PUBLIC_KEY` (pk_test, redundant zu STRIPE_KEY). Konsistente Projekt-Konvention (`.env.example` + App): `STRIPE_KEY`=pk, `STRIPE_SECRET`=sk. `.env.production` wurde am 2026-08-04 an diese Konvention angeglichen (war invertiert, nur lokal genutzt).

**Hinweis:** `.env.production`/`frontend/.env` sind **korrekt gitignored** (`.gitignore:50`) — kein Exposure (User-Frage geklärt 2026-08-04). Repo ist **public** → CI-Security: `pull_request_target` nur für actor==dependabot, kein Checkout untrusted Code.

**TODO (Implementierung delegiert an Subagent):**
- [ ] `.github/dependabot.yml` (Ecosystems, Groups, Schedule)
- [ ] `.github/workflows/ci.yml` (backend/frontend/e2e)
- [ ] `.github/workflows/automerge.yml` (Gate ohne Branch Protection)
- [ ] Verification: actionlint/lint der YAMLs, Push auf Feature-Branch, CI-Runs beobachten, E2E-Job iterieren (Max-3-Regel)
- [ ] Repo-Settings via gh API (automated-security-fixes, vulnerability-alerts)
- [ ] Doku-Follow-up: `features/` SOLL-Notiz optional
