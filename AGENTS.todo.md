# Task Board — Portal Reisinger Pictures

> Stand: 2026-07-14. Bereinigt um abgeschlossene Bereiche (Contract Templates, Security-Hardening, B2B-Parity-Fixes).

---

## Security & Architecture Audit (2026-07-11)

> ~942 PHPUnit- + ~69 Playwright-Tests. Stack: Laravel 13 (PHP 8.4) JWT-API · React 19 / Vite 8 / Tailwind 4 · MariaDB · Meilisearch · Stripe.

### Status-Entscheidungen (bewusst akzeptiert)

- **[C1] Hardcoded `JWT_SECRET`-Fallback** — `backend/config/jwt.php:18`. `env('JWT_SECRET', 'wrW2nVh...')` — bekanntes Secret in git. **Akzeptiert**, da Deployment-Guard in `docker-compose.yml` Production abfängt und nur Dev-/Test-Keys betroffen sind.
- **[C2] Hardcoded `APP_KEY`-Fallback** — `backend/config/app.php:110`. Gleiche Begründung wie C1.
- **[C3] Stripe Test-Secret committed** — `backend/.env.testing:18`. Test-Account, niedriges Risiko.
- **[C4] Live Stripe-Publishable-Key committed** — `frontend/.env`. `!.env`-Negation im `.gitignore`. Publishable = niedrigkritisch, Hygiene-Footgun.

### Open Issues

#### Critical (2 offen)

- [ ] **[C1] Hardcoded `JWT_SECRET`-Fallback** — `backend/config/jwt.php:18`. Siehe Status-Entscheidungen oben. Falls der Deployment-Guard je entfernt wird → sofort fixen.
- [ ] **[C2] Hardcoded `APP_KEY`-Fallback** — `backend/config/app.php:110`. Siehe Status-Entscheidungen oben.

#### High (1 offen)

- [ ] **[H5] Keine CI-Pipeline** — kein `.github/workflows`, kein GitLab-CI. ~942 + ~69 Tests existieren, gate aber nie ein Merge. → CI-Matrix: PHPUnit + Pint + pnpm lint + Vitest + Playwright-Smoke.

#### Medium (9 offen)

- [ ] **[M1] Rate-Limit-Default praktisch offen** — `backend/routes/api.php:39`. `AUTH_THROTTLE_LIMIT` defaultet `9999/min`. → Login/Register/Reset: 5/min/IP als Real-Default + per-Email-Throttling.
- [ ] **[M2] Runtime-`env()`-Aufrufe app-weit** — `AuthController.php:104`, `WebhookController.php:94`, `CheckoutService.php:325`, `OrgInviteController.php:119`, `Controller.php:14`. Klassischer Laravel-Footgun: `config:cache` liefert `null`. → Alle in `config(...)`-Keys kapseln.
- [ ] **[M3] CSRF nur via `SameSite=Lax`** — SPA Cookie-Auth, kein `X-XSRF-TOKEN`. → Sanctum Stateful-CSRF ODER `SameSite=Lax`/`strict` als alleinige Kontrolle dokumentieren.
- [ ] **[M4] `APP_DEBUG` default `true`** — `backend/config/app.php:42`. Stacktrace-Leak bei unset Env. → Default `false`.
- [ ] **[M5] N+1 in GalleryFrontendController** — `backend/app/Http/Controllers/GalleryFrontendController.php:47-67`. Per-Photo Rating-Query in `transform()` (50 Queries/page). → Bulk-Load via `whereIn` + In-Memory-Map.
- [ ] **[M6] Frontend-Nginx: kein HSTS, kein CSP** — `frontend/nginx.conf`. → HSTS + striktes CSP ergänzen.
- [ ] **[M7] Watermark-SVG-Upload ohne MIME/Size-Validierung** — `backend/app/Http/Controllers/SettingsController.php:66-89`. SVG kann JS ausführen. → `mimes:svg` + Size-Cap + optional Sanitize.
- [ ] **[M8] Prod-Compose macht `optimize:clear` statt `optimize`** — `deployment/docker-compose.yml:131`. Config/Routes/Views werden pro Request neu geparst. → `php artisan optimize` nach Migrate.
- [ ] **[M9] Dual Lockfiles** — `pnpm-lock.yaml` + `bun.lock`. Können driften, kein `packageManager`-Pin in `package.json`. → Eines entfernen, `packageManager`-Field setzen.

#### Low (5 offen)

- [ ] **[L1] Service-Locator-Reste (`app()` in ~30 Stellen)** — u.a. `ContractJoinController.php:80,87,109,126,148,177,216,222`, `ContractController.php:76,142`, `User.php:96,113`, `Gallery.php:117,125`. → Constructor-Injection / Method-Args.
- [ ] **[L2] God-Controller** — `CouponController.php` (383 Z.), `DownloadController.php` (374 Z.). → Aufspalten + Duplikation extrahieren.
- [ ] **[L3] Deprecated Accessor `is_customer_manager`** — `backend/app/Models/User.php:90-91`. Noch in API-Output (`AuthController.php:225`). → Entfernen nach Consumer-Check.
- [ ] **[L4] Breite `catch(\Throwable)` im Mail-Body** — `backend/app/Services/CheckoutService.php:323-327`. Möglicher Info-Leak. → Serverseitig loggen, sanitize Meldung senden.
- [ ] **[L5] Gast-JWT-Tokens nicht revozierbar** — `backend/app/Http/Controllers/InviteController.php:128-136` + `TransientUserProvider.php`. Blacklist-Write-Path fehlt. → Schreibpfad oder bewusste TTL-Dokumentation.

### Erledigt (2026-07-11)

- [x] C5: XSS via `dangerouslySetInnerHTML` → `sanitizeHtml.ts` (DOMPurify)
- [x] C6: Stripe-Webhook Underpayment-Guard → `amount_received < total_amount`-Check
- [x] H1: Text-Snippet-Preview XSS → Defense-in-depth via `sanitizeHtml()`
- [x] H2: IDOR Notification Opt-In → `canAccessGallery()` Guards
- [x] H3: IDOR `MailController::finishRating` → `canAccessGallery($gallery->id)` Guard
- [x] H4: `ManagementMiddleware` null-deref → Null-Check + `auth('api')`
- [x] H6: Security-Header-Middleware → `SetSecurityHeaders.php` (nosniff, X-Frame-Options DENY, Referrer-Policy, HSTS)
- [x] H7: V025-Migrations-Split → hinfällig (V026 live)

---

## B2B Feature-Parity + Tarif-Umbenennung (2026-07-13)

> Branch `feat/b2b-feature-parity`. Ziel: Alle Features brand-agnostisch über Settings steuerbar, nicht mehr über hardcoded `isSrp`/`Brand::SRP`-Gates. Vorbereitung für SRP-Stilllegung in separatem PR.


### Offen

- [ ] **Playwright @smoke verifizieren** — AGENTS.todo.md meldete 40 Failures vom 2026-07-13 (vermutlich Volume-Licensing-Default ohne Setting-Eintrag). Keine `.last-run.json` auf Disk, Status unbekannt. Nach dem DB-Brand-Refactor (`4075ad8`) neu messen: `pnpm test:e2e:smoke`. Timeout: 15 min (900000ms, ca. 2× Laufzeit).
- [ ] **PR: Multi-Tenant-Fertigstellung** (Generalisierung der SRP-Forks) — siehe Abschnitt "Multi-Tenant-Fertigstellung" unten. *Strategie-Pivot: statt SRP auf RP zu kollabieren, werden die `isSrp`-Forks generalisiert, damit neue Brands ohne Code-Änderung anlegbar sind.*
- [ ] **PR: Per-Gallery Licensing Override** (später). Architektur-Refactor:
  - Neue Gallery-Spalte `licensing_mode` (nullable = inherit Brand-Setting).
  - Mixed-Cart-Problem: Warenkorb mit Fotos aus Volume- und Scope-Licensing-Galerien.
  - Migration + Model + UI + `PricingStrategy::calculateCart`-Refaktorierung.
- [ ] **`features/`-Dokumentation** aktualisieren: Neuer SOLL-Zustand für Pricing-Architektur (Setting-basiert, nicht Brand-basiert).



## Multi-Tenant-Fertigstellung — Generalisierung der SRP-Forks (2026-07-14)

> **Strategie-Pivot:** Die ursprüngliche "SRP-Stilllegung = auf RP kollabieren" wird verworfen. Sie wäre anti-Multi-Tenant (neue Brands fielen immer auf RP zurück). Stattdessen: SRP-Forks **generalisieren** auf `BrandConfig`/DB-Driven, sodass **Brand anlegen = DB-Zeile in `brands` + optional ein daisyUI-Theme** ist — kein Refactoring bestehender Code-Forks pro neue Marke.
>
> Stand: Exploration des aktuellen `main` (inkl. Refactor `4075ad8` "DB-driven brand config"). **Noch nichts umgesetzt** — alle SRP-Referenzen intakt.

### Status-Check: Was bereits pro-Tenant funktioniert (kein Handlungsbedarf)

| Aspekt | Mechanismus | Beweis |
|--------|-------------|--------|
| **Features pro Brand** | `features` JSON auf `brands`-Tabelle (`coupons`, `orgs`, `volume_licensing`) | `V027__create_brands_table.php`, `BrandConfig::hasFeature()` (`Values/BrandConfig.php:29`) |
| **Pricing-Settings pro Brand** | `settings`-Tabelle mit `brand`-Spalte; `SettingResolver::get()` scoped nach Current-Brand, Fallback auf `rp` | `SettingResolver.php:23-37` |
| **Pricing-Strategie pro Brand** | Setting `pricing_strategy` aktiviert `VolumeLicensingStrategy` — **nicht** mehr `isSrp()`-gebunden (Refactor `4a291ed`) | `VolumeLicensingStrategy.php`, `useLicensingMode.ts` |
| **Frontend-Bezug** | `/api/settings/brand-config` liefert alles, `useBrandConfig()` (SWR) | `brandRegistry.ts:42-52` |
| **Brand-Isolation** | `BrandRegistry` + `BrandContextMiddleware` + `forCurrentBrand()`-Scopes | bestätigte Stärke (s. Bestätigte Stärken) |

**Antwort auf User-Frage "Pricing pro Tenant konfigurierbar?": Ja, vollständig umgesetzt.**

### Strategie-Entscheidungen (User, 14.07.2026)

- SRP-Forks werden **generalisiert** (`BrandConfig`/DB-getrieben), **NICHT** auf RP kollabiert.
- `Brand::SRP`-Enum-Case, `brands`-DB-Zeile (SRP), Scoping-Middleware, Multi-Brand-Maschinerie **bleiben erhalten**.
- **Operational-Felder** (`frontend_url`, `from_address`, `from_name`, `accounting_email`) → neue Spalten auf `brands`-Tabelle (Migration V029). Single Source of Truth.
- **Farben NICHT generalisiert** — PDF-Farben und daisyUI-Themes bleiben hardcoded/templatet. Begründung (User): zu großer Theme-Refactor für marginalen Nutzen. Wer später Brand-spezifische Farben will → eigenes Ticket.
- Keine Datenmigration bestehender SRP-Daten. Keine Domain-Stilllegung `buy.reisinger.pictures`.

### 1. Schema — `brands`-Tabelle erweitern (Migration V029)

- [ ] **Neue Migration V029** — Spalten hinzufügen (alle nullable, daoptional pro Brand):
  - `frontend_url` (nullable string) — pro-Brand Frontend-URL (ersetzt `config('app.frontend_url_srp')`).
  - `from_address` (nullable string) — Mail-Absender-Adresse (ersetzt `config('mail.from_srp.address')`).
  - `from_name` (nullable string) — Mail-Absender-Name (ersetzt `config('mail.from_srp.name')`).
  - `accounting_email` (nullable string) — BCC-Accounting (ersetzt `config('services.accounting_email_srp')`).
- [ ] **Default-Werte** für bestehende `rp`- und `srp`-Zeilen aus aktuellen Config-Keys befüllen (RP: `portal.reisinger.pictures`, SRP: `buy.reisinger.pictures`).
- [ ] **Migration-Policy-Check:** V028 ist die letzte deployte Migration. V029 als neue separate Migration (nicht V028 erweitern, da V028 live). Vor Deployment ggf. mit anderen ≥V025 konsolidieren.
- [ ] **Deprecation-Notiz:** `config/app.php:65 frontend_url_srp`, `config/mail.php:42-45 from_srp`, `config/services.php:53-54 accounting_email_*` werden zu totem Code nach Generalisierung. Entfernen im selben PR.

### 2. `BrandConfig` Value Object erweitern (`backend/app/Values/BrandConfig.php`)

- [ ] Neue readonly-Felder: `?string $frontendUrl`, `?string $fromAddress`, `?string $fromName`, `?string $accountingEmail`.
- [ ] `backend/app/Models/Brand.php::toConfig()` — DB-Spalten → Value Object mappen.
- [ ] `backend/app/Support/BrandRegistry.php::hardcodedConfig()` L130-149 — Fallback-Configs für `rp`/`srp` um neue Felder ergänzen (für Tests / Missing-Table-Szenario).

### 3. Backend Production-Forks generalisieren (`=== 'srp'` → `$config->...`)

- [ ] **`AbstractBrandAwareMailable.php:27-56`** — drei Methoden auf `BrandConfig` umstellen:
  - `brandFrontendUrl()` L27-33: `$this->brand?->value === 'srp' ? config('app.frontend_url_srp') : config('app.frontend_url')` → `BrandRegistry::configForBrand($this->brand?->value)?->frontendUrl ?? config('app.frontend_url')`.
  - `brandBcc()` L40-44: Ternary → `$config?->accountingEmail ?? config('services.accounting_email')`.
  - `applyBrandFrom()` L46-56: SRP-Zweig → `$config?->fromAddress ?? config('mail.from.address')`.
- [ ] **`BrandRegistry.php`** — `fromHost()` L24-26: Produktions-Fallback `str_starts_with('buy.') → Brand::SRP` **entfernen** (DB-Pfad via `resolveConfigFromHost()` matched die `hostnames`-Spalte). Stattdessen **Dev-Fallback auf `.localhost`-Subdomains** einführen: matched `*.localhost` → Brand aus Subdomain ableiten (`srp.localhost → srp`, `acme.localhost → acme`). Erlaubt lokales Testen/Debuggen neuer Brands ohne DB-Konfig/hosts-Eintrag. `isSrp()` L61-64 **entfernen** (anti-Multi-Tenant, keine Caller nach Phase 3).
- [ ] **`SettingResolver.php:18-21`** — `isSrp()`-Wrapper entfernen. Class-Docblock L8-15 von `srp_*`-Prefix-Historie bereinigen.
- [ ] **`InvoiceController.php`** — L35 `'isSrp' => $brand->value === 'srp'` löschen; L73 `$isSrp = ...` + L83 `'isSrp' => $isSrp` löschen.
- [ ] **`ContractPdfService.php:56`** — `'isSrp' => $brand->value === 'srp'` löschen.
- [ ] **`InvoiceMail.php:44`** — `'isSrp' => $this->brand?->value === 'srp'` löschen.
- [ ] **`UpdateUserRequest.php:28`** — `in:rp,srp` → dynamisch gegen `brands`-Tabelle: `Rule::in(\App\Models\Brand::pluck('id'))`. Comments L26, L56, L61 bereinigen.
- [ ] **`ProcessCollectiveInvoices.php:12`** — Signatur-Helptext `rp|srp` → `rp` (oder dynamisch).

### 4. PDF Blade Views — Farben hardcoded, `isSrp`-Variable entfernen

> **Entscheidung (User):** PDF-Farben bleiben hardcoded RP-Palette. Keine Theme-Generalisierung in diesem PR.

- [ ] **`resources/views/pdf/header.blade.php:8-9`** — Ternaries auflösen zu festen Farben (`#1E5631` primary / `#A4B494` secondary). `$isSrp`-Variable nicht mehr referenzieren.
- [ ] **`resources/views/pdf/invoice.blade.php:7-8`** — Ternaries → fest. L28: `isSrp` aus `@include('pdf.header', ...)` entfernen.
- [ ] **`resources/views/pdf/manual_offer.blade.php:7-8,27`** — gleiches Pattern.
- [ ] **`resources/views/pdf/contract_signatures.blade.php:33`** — `isSrp` aus `@include` entfernen.

### 5. Frontend Generalisierung

- [ ] **`frontend/src/logic/brandRegistry.ts`**
  - L4 `BrandId = string` bleibt locker (bereits korrekt für N-Brands).
  - L23-26 `themeMap`: `srp`-Eintrag **behalten** (SRP-Theme existiert). Für neue Brands → Theme-Name aus `brand-config` API (`config.theme`) statt hartes `themeMap`-Lookup. `getBrandTheme` auf Config-getrieben umstellen.
  - L30-36 `getBrandFromHostname`: `buy.`/`srp.localhost`-Branch **entfernen** — Brand-Auflösung erfolgt bereits via `/api/settings/brand-config` (DB-getrieben). Hostname-Detection nur noch Default `rp` als Pre-Boot-Fallback.
- [ ] **`frontend/index.html:11-14`** — Boot-Script: `brand = host.startsWith('buy.') ? 'srp' : 'rp'` → Production-Pfad defaultet auf `rp`; **Dev-Fallback für `*.localhost`** (z.B. `srp.localhost → srp`, `acme.localhost → acme`) zur lokalen Brand-Subdomain-Ableitung. `themeColors.srp` entfernen (Theme wird nach React-Mount via `useBrandConfig()` korrigiert). Mit `BrandRegistry::fromHost()` synchron halten (gleiche Dev-Fallback-Logik).
- [ ] **Frontend-Typen `'rp' | 'srp' | null` → `string | null`:**
  - `UserPermissionsModal.tsx` L13, L33, L42, L128 — Union → `string`. L132 `<option value="srp">` **entfernen** (oder dynamisch aus `/api/brands` rendern — Entscheidung: hardcoded entfernen, Folgeticket für dynamische Brand-Auswahl). L37 Comment bereinigen.
  - `ManagementUserView.tsx:33` — `brand: 'rp' | 'srp' | null` → `string | null`.
  - `useUsers.ts:26-28,54,61` — `brand?: 'rp' | 'srp' | null` → `string | null`.
  - `ManagementFtpInbox.tsx:8-11` — `brandLabels`-Map: `srp`-Eintrag entfernen oder auf dynamische Brand-Labels umstellen.
- [ ] **`CouponInput.tsx:1-11`** — Stale Docblock bereinigen (entfernt fälschliche `useBrand().isSrp`-Referenz; tatsächlicher Guard ist `useLicensingMode()`).
- [ ] **`vite.config.ts:46`** — `buy.localhost` aus `allowedHosts` entfernen.
- [ ] **`index.css:62-114,117`** — `srp-light`/`srp-dark` daisyUI-Themes **behalten** (SRP-Theme als Beispiel).

### 6. Tests (intern gestaffelt: DELETE → SEARCH-AND-REPLACE → REWRITE)

**6a. SRP-only Test-Files LÖSCHEN (4):**
- [ ] `backend/tests/Feature/SrpSettingsSeederTest.php`
- [ ] `backend/tests/Feature/Checkout/CheckoutServiceSrpTest.php` — *VolumeLicensingStrategy bleibt produktiv; Logik bleibt durch `VolumeLicensingStrategyTest.php` covered (wird in 6c genericisiert).*
- [ ] `frontend/tests/e2e/brand/srp-dashboard.spec.ts`
- [ ] `frontend/tests/e2e/client/srp-volume-pricing.spec.ts`

**6b. SEARCH-AND-REPLACE (generische Fixture-Tests):** `Brand::SRP`/`'srp'` bleibt als gültige zweite Brand-Fixture (SRP-Case existiert ja weiter!), aber `isSrp()`-Assertionen entfernen.
- [ ] Backend: `CouponServiceTest.php` (54), `CouponControllerTest.php` (17), `CheckoutServiceTest.php` (10), `BrandQueueResetTest.php` (3), `Authorization/AccessControlServiceTest.php` (3), `FtpImportTest.php` (2), `TextSnippetControllerTest.php` (2), `Contract/ContractControllerTest.php` (1), `CustomerControllerTest.php` (1), `CheckoutStripeErrorTest.php` (1).
- [ ] E2E: `coupon-admin-crud.spec.ts`, `coupon-gallery-scope.spec.ts`, `coupon-checkout-revalidation.spec.ts`.

**6c. REWRITE — Isolation-Tests (Entscheidung: "Behalten & anpassen"):** Generische Scoping/Leak-Assertions bleiben erhalten (Multi-Brand-Maschinerie existiert weiter), nur SRP-spezifische Assertions entfernen (`isSrp()`-Aufrufe, PDF-Farb-Assertions, "SRP hat Volume-Licensing"-Hardcoding).
- [ ] `BrandRegistryTest.php` — `isSrp`-Tests entfernen; `fromHost`-Tests generalisieren (DB-getrieben statt `buy.`-Fallback).
- [ ] `SettingResolverTest.php` — `isSrp()`-Tests entfernen; Brand-Scoped Read/Write-Tests bleiben.
- [ ] `SettingsBrandPrefixTest.php` — `srp_*`-Prefix-Logik-Tests reviewen (Prefix-Mapping wird deprecated).
- [ ] `BrandLeakTest.php` — Brand-Leak-Konzept bleibt (reconstruct-from-order, not host); SRP-spezifische Assertionen generalisieren.
- [ ] `Auth/BrandLoginTest.php` — Cross-Brand-Login bleibt gültig (SRP als zweite Brand); Assertions generalisieren.
- [ ] `BrandScopingTest.php`, `SitemapControllerTest.php` — Brand-Scoping-Tests bleiben, SRP als zweite Brand.
- [ ] `CheckoutCouponRevalidationTest.php` — von SRP-brand-hardcoded auf `pricing_strategy=volume_licensing` Setting umstellen.
- [ ] `Pricing/VolumeLicensingStrategyTest.php` — genericisieren: `Brand::SRP` setUp → `Brand::B2B` + `pricing_strategy=volume_licensing` Setting.
- [ ] `AuthControllerTest.php`, `OrgOrganizationCoreTest.php` — Cross-Brand-Assertions reviewen.
- [ ] E2E-Helper `E2ESessionHelper.ts:34-35,46,84` — `brand?: 'rp' | 'srp'` → `brand?: string`; `buy.localhost`-Referer nur wenn SRP-Fixture gebraucht.
- [ ] E2E: `brand-isolation.spec.ts`, `gallery-brand-scoping.spec.ts`, `brand-theming.spec.ts`, `brand-e2e-infra.spec.ts`, `brand-conflict.spec.ts`, `brand-favicon.spec.ts` anpassen.
- [ ] Vitest: `brandRegistry.test.ts`, `useBrand.test.ts` — `getBrandFromHostname` SRP-Tests entfernen (DB-getrieben); Theme-Tests generalisieren.

### 7. SRP-Seeder & Daten — BEHALTEN

- [ ] `backend/database/seeders/SrpSettingsSeeder.php` + Aufruf in `DatabaseSeeder.php:73-78` — **behalten** (SRP als Beispiel-Brand in DB bleibt erhalten laut Strategie).
- [ ] `frontend/public/brands/srp/` Static Assets — **behalten** (SRP-Theme existiert).

### 8. DoD-Validierung (nach Umsetzung)

```bash
export PATH="/c/Users/flori/.config/herd/bin/php85:$PATH"
cd backend && php artisan test
cd frontend && pnpm vitest run
cd frontend && pnpm lint:fix && pnpm build
cd frontend && pnpm test:e2e:smoke   # Timeout 900000ms
```

### 9. Folgearbeiten (NICHT Teil dieses Plans)

- [ ] **Admin-UI zum Anlegen neuer Brands** (CRUD auf `brands`-Tabelle) — dann ist "Brand anlegen ohne Code" Realität.
- [ ] **Per-Gallery Licensing Override** (separater PR, Mixed-Cart-Problem).
- [ ] **Theme-Override pro Brand** (falls PDF/daisyUI-Farben später doch dynamisch gewünscht).
- [ ] **`features/`-Dokumentation** der Multi-Tenant-Architektur (neuer SOLL-Zustand: BrandConfig-getrieben statt `isSrp`-Forks).
- [ ] **`ShootingCalculatorModal.tsx` calcMode-Labels** `'rp'`→`'standard'`, `'srp'`→`'flex'` (optional, NOT load-bearing).
- [ ] **`VolumeLicensingStrategy.php:78,89,90`** Invoice-Labels `'srp'`→`'volume'`, `'SRP Lizenz'`→`'Volume Lizenz'` (optional).

### Geklärte Entscheidungen (User, 14.07.2026)

- **Hostname-Fallback:** Produktions-Fallback `str_starts_with('buy.') → Brand::SRP` wird **entfernt** (DB-Pfad reicht). Stattdessen **Dev-Fallback auf `*.localhost`-Subdomains** (z.B. `srp.localhost → srp`, `acme.localhost → acme`), damit lokal neue Brands ohne DB-Konfiguration testbar sind. Synchron in `BrandRegistry::fromHost()` und `frontend/index.html` Boot-Script halten.
- **`brands`-Editierbarkeit:** Neue Brands ausschließlich via **Migration/Seeder** anlegen (kein Admin-UI in diesem PR). Passt zur bisherigen Praxis (V027 legt rp/srp per Migration an). Admin-CRUD ist optionaler Folgeschritt (siehe Folgearbeiten).

---

## Bestätigte Stärken (nicht regredieren lassen)

- Brand-/Org-Isolation (`BrandRegistry` + `BrandContextMiddleware` + `forCurrentBrand()`-Scopes)
- httpOnly-Cookie-Auth (kein Token in localStorage/sessionStorage, deduped Refresh)
- Keine Raw-SQL mit User-Input, Shell-Outs via `Process` mit Array-Args
- `$fillable`-Disziplin (kein `$guarded=[]`, kein `Model::create($request->all())`)
- Bildupload mehrstufig validiert (`image`-Rule + `mimes` + `exiftool`-MIME-Check)
- File-Delivery auth-gated (`FileDeliveryController`)
- Frontend-Disziplin (0× `any`/`@ts-ignore`/`eslint-disable`, Zod-Resolver auf allen Forms, `lint --max-warnings 0`)
- Preisberechnung server-autoritativ (signiertes Offer-Token)
- HTML-Sanitize beim Persistieren (Symfony `HtmlSanitizer`) + beim Render (DOMPurify)
- Vertragssigning mit optimistischer Concurrency (`content_version` in UPDATE-WHERE)
