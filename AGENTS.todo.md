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

> Branch `feat/b2b-feature-parity` (gemerged). Ziel: Alle Features brand-agnostisch über Settings steuerbar, nicht mehr über hardcoded `isSrp`/`Brand::SRP`-Gates. SRP-Entfernung erfolgt im Brand-Refactor (`1831116`, siehe unten).


### Offen

- [ ] **Playwright @smoke** — nach dem Brand-Refactor (`1831116`) sind die `@smoke`-Brand-Tests kaputt (siehe "Offen Punkt 2" im Abschnitt "Brand-Refactor" unten). PHPUnit/Vitest jedoch grün.
- [x] **Brand-Refactor umgesetzt** (`1831116`, `config/brands.php`-Route) — siehe Abschnitt "Brand-Refactor" unten. *2 offene Punkte vor Push: Datenmigration + E2E-Tests.*
- [ ] **PR: Per-Gallery Licensing Override** (später). Architektur-Refactor:
  - Neue Gallery-Spalte `licensing_mode` (nullable = inherit Brand-Setting).
  - Mixed-Cart-Problem: Warenkorb mit Fotos aus Volume- und Scope-Licensing-Galerien.
  - Migration + Model + UI + `PricingStrategy::calculateCart`-Refaktorierung.
- [ ] **`features/`-Dokumentation** aktualisieren: Neuer SOLL-Zustand für Pricing-Architektur (Setting-basiert, nicht Brand-basiert).



## Brand-Refactor — SRP entfernt, config-Driven (Commit `1831116`, 2026-07-14)

> **Umgesetzt** in Commit `1831116` (auf `main`, 1 ahead of origin, ungepusht). Strategie weicht vom ursprünglich dokumentierten Plan ab — siehe "Strategie-Historie" unten. **2 offene Punkte** vor Push (Datenmigration + E2E-Tests).

### Was der Commit tatsächlich gemacht hat

Der Commit implementiert eine **statische `config/brands.php`**-Route (statt der ursprünglich geplanten DB-Tabelle-Erweiterung) und **entfernt SRP vollständig** (statt `Brand::SRP` zu erhalten):

| Maßnahme | Detail |
|----------|--------|
| **`config/brands.php` neu** | Statische Brand-Konfiguration (aktuell nur `rp`), felder: `name`, `theme`, `hostnames`, `features`, `frontend_url`, `from_address`, `from_name`, `accounting_email`, `primary_color`, `secondary_color`. |
| **`brands`-Tabelle gedroppt** | `V029__drop_brands_table.php` — `Schema::dropIfExists('brands')`. `V030` erweitert `contracts.brand` auf `VARCHAR(20)`. |
| **`Brand::SRP`-Enum-Case entfernt** | `app/Enums/Brand.php` nur noch `case B2B = 'rp'`. `prefix()`/`domain()` vereinfacht. |
| **`BrandConfig` Value Object erweitert** | +6 Felder: `frontendUrl`, `fromAddress`, `fromName`, `accountingEmail`, `primaryColor`, `secondaryColor`. |
| **`BrandRegistry` auf config umgestellt** | `loadAllConfigs()` liest `config('brands')` statt DB; `hardcodedConfig()` entfernt; `isSrp()` → `currentId()`; `fromHost()`-`buy.`-Fallback ersetzt durch `*.localhost`-Dev-Fallback. |
| **`AsBrand`-Cast neu** | `app/Casts/AsBrand.php` — `Brand::tryFrom($value) ?? $value` (liefert rohen String bei unbekannten Werten). Auf 14 Models angewandt. |
| **Backend-Forks generalisiert** | `AbstractBrandAwareMailable`, `InvoiceController`, `InvoiceMail`, `ContractPdfService`, `SettingResolver` — `isSrp` entfernt, auf `BrandConfig`-Felder umgestellt. |
| **`UpdateUserRequest` dynamisch** | `Rule::in(array_keys(config('brands')))` statt `in:rp,srp`. |
| **PDF-Blade** | `isSrp`-Ternaries → feste RP-Farben (`#1E5631`/`#A4B494`). |
| **Frontend** | `brandRegistry.ts` Dev-Fallback `*.localhost`, `themeMap` nur `rp`, daisyUI `srp-*`-Themes entfernt, Typen → `string`. |
| **Gelöscht** | `SrpSettingsSeeder.php`, `app/Models/Brand.php`, `frontend/public/brands/srp/*` (10 Assets), 5 SRP-only Testfiles, `SettingsBrandPrefixTest.php`. |

### Verifikation (Stand 14.07.2026)

| Suite | Ergebnis |
|-------|----------|
| **PHPUnit** | ✅ 973 passed (2279 Assertions), 36.62s |
| **Vitest** | ✅ 469 passed (46 Files), 3.13s |
| **ESLint** | ✅ `--max-warnings 0` |
| **Build (tsc+vite)** | ✅ 931ms |
| **Playwright @smoke** | ❌ **nicht lauffähig** — siehe offener Punkt 2 |

`grep`-Verifikation (Production-Code): Keine fatalen Referenzen auf `Brand::SRP`, `isSrp()`, `BrandModel`, `SrpSettingsSeeder` — alle sauber entfernt. 13 `brand`-Spalten in der Live-DB = `VARCHAR(20)` (konsistent).

### 🔴 Offen — vor Push zu lösen

#### 1. Datenmigration für 63 verwaiste `brand='srp'`-Zeilen fehlt

**Befund:** V029 dropt nur die `brands`-Tabelle, migriert aber **nicht** die Fremddaten. In der Live-DB existieren noch:
```
products: 16, settings: 38, license_use_cases: 4, license_modifiers: 3, customers: 1, text_snippets: 1  (= 63 Zeilen)
```
**Auswirkung:** Der `AsBrand`-Cast liefert für `'srp'` den rohen String (kein Crash), aber jede `forCurrentBrand()`/`where('brand', ...)`-Query matched nur `'rp'` → die SRP-Daten sind **still unsichtbar** (38 Settings, 16 Produkte etc. werden nie geladen).

- [ ] **Neue Migration V031** — `UPDATE <table> SET brand='rp' WHERE brand='srp'` für alle 14 `brand`-Spalten-Tabellen (`orders`, `invoice_snapshots`, `users`, `galleries`, `gallery_groups`, `orgs`, `products`, `license_use_cases`, `license_modifiers`, `settings`, `customers`, `text_snippets`, `coupons`, `contracts`). Vorher klären, ob SRP-Daten übernommen oder bewusst verworfen werden (38 Settings überschreiben evtl. RP-Werte — Konfliktprüfung nötig, insb. bei `settings` mit gleichem `key`).
- [ ] **`V029.down()` ist leer** (recreatet `brands`-Tabelle nicht) — akzeptabel, aber dokumentieren dass V029 nicht sauber reversibel ist.

#### 2. E2E-Tests kaputt (`@smoke` betroffen)

**Befund:** 7 Specs rufen `createIsolatedUser('...', { brand: 'srp' })` auf → das PUT `/api/management/users` wird **422** ablehnen, weil `UpdateUserRequest` jetzt nur noch `['rp']` akzeptiert (`config('brands.php')`). `E2ESessionHelper.ts:34` `loginAs`/`createIsolatedUser` haben noch `brand?: string` mit `buy.localhost`-Referer-Pfad. Betroffen:

- [ ] **`brand-isolation.spec.ts`** — enthält `@smoke`-Test (L21 "Admin sees B2B Mandanten section") + 4× `@regression`. Brand-Isolation-Konzept ist mit Single-Brand obsolet → **Spec löschen** ODER umschreiben als Single-Brand-Regression (z.B. dass `brand:'srp'` vom Backend mit 422 abgelehnt wird). Beachte: `UserPermissionUpdateTest.php` (PHPUnit) deckt die Super-Admin-null-brand-Logik bereits ab → E2E-Coverage für dieses Konzept vorhanden.
- [ ] **`gallery-brand-scoping.spec.ts`** (3× `@feature:brand:scoping`) — Spec löschen (Single-Brand → kein Cross-Brand-Redirect mehr) ODER umschreiben.
- [ ] **`brand-conflict.spec.ts`** (`@feature:brand:isolation`) — Spec löschen (Cross-Brand-Login-Konzept obsolet).
- [ ] **`brand-theming.spec.ts`, `brand-favicon.spec.ts`, `brand-e2e-infra.spec.ts`** — SRP-spezifisch (asserten `data-brand='srp'`, `buy.localhost`-Assets). Löschen oder auf `rp` umschreiben.
- [ ] **`download-invoice-brand-leak.spec.ts`** — prüfen ob noch relevant (Brand-Leak-Konzept, Single-Brand).
- [ ] **Coupon-Specs** (`coupon-admin-crud.spec.ts`, `coupon-gallery-scope.spec.ts`, `coupon-checkout-revalidation.spec.ts`) — `brand: 'srp'`-Fixture → `brand: 'rp'` umstellen (Funktionalität bleibt, nur Brand-Fixture ändert). PHPUnit-Coverage der Coupon-Logik vorhanden (`CouponControllerTest` 20 Tests, `CouponServiceTest`, `CheckoutCouponRevalidationTest`).
- [ ] **`E2ESessionHelper.ts:34,46,84`** — `buy.localhost`-Referer-Pfad entfernen; `brand`-Option nur noch für Dev-Fallback nötig.
- [ ] **Nach Fix: `pnpm test:e2e:smoke` laufen lassen** (Timeout 900000ms) — muss grün sein vor Push.

**Hinweis:** PHPUnit-Coverage für Brand-Scoping/Isolation/Login existiert weiterhin (`BrandLoginTest`, `BrandQueueResetTest`, `BrandScopingTest`, `OrgIsolationTest`, `AccessControlServiceTest` — alle grün). E2E-Brand-Isolation-Tests sind weitgehend redundant → Löschen ist vertretbar.

### 🟡 Kleine Lücken (optional, nicht blockierend)

- [ ] **`ShootingCalculatorModal.tsx:17-20`** — `calcMode` nutzt noch `'rp'`/`'srp'` als Tarif-Labels ("Standard"/"Flex"). Funktional OK (keine Brand-Logik), aber Comment L17 ("Both available on every brand") ist stale. Umbenennung optional (`'srp'`→`'flex'`).
- [ ] **`VolumeLicensingStrategy.php:78,89,90`** — Invoice-Tier-Labels noch `'srp'`/`'SRP Lizenz'` (optional → `'volume'`/`'Volume Lizenz'`).
- [ ] **Commit auf `main`** (nicht Feature-Branch) — vor Push ggf. auf Branch legen.

### Strategie-Historie (dokumentiert zur Nachvollziehbarkeit)

1. **Ursprünglich:** "SRP-Stilllegung = auf RP kollabieren" → verworfen (anti-Multi-Tenant).
2. **Geplanter Pivot (14.07.):** "brands-Tabelle erweitern, `Brand::SRP` erhalten, BrandConfig-getrieben" → **nicht umgesetzt**.
3. **Tatsächlich umgesetzt (`1831116`):** `config/brands.php` (statisch), `Brand::SRP` entfernt, `brands`-Tabelle gedroppt. Neue Brand = Config-File-Eintrag + optionaler Enum-Case (Code-Änderung nötig, keine reine DB-Zeile).

### Folgearbeiten (separat)

- [ ] **Admin-UI zum Anlegen neuer Brands** (CRUD auf `config/brands.php` — erfordert Config-Write-Layer).
- [ ] **Per-Gallery Licensing Override** (separater PR, Mixed-Cart-Problem).
- [ ] **Theme-Override pro Brand** (falls PDF/daisyUI-Farben später doch dynamisch).
- [ ] **`features/`-Dokumentation** der `config/brands.php`-Architektur (neuer SOLL-Zustand).
- [ ] **Vor Push:** Commit ggf. auf Feature-Branch legen, E2E-Smoke grün.

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
