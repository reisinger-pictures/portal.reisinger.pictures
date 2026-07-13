# Task Board — Portal Reisinger Pictures

> Stand: 2026-07-13. Merge abgeschlossen. Contract Templates Feature vollständig (Code + Tests + E2E).

---

## Feature: Contract Templates → 1:1 Instanzen

### SOLL-Zustand (Architektur)

Ein **Template** wird vom Admin einmal erstellt. Jede Signatur via Join-Link erzeugt eine eigenständige **Contract-Instanz** (1:1 Admin ↔ Signer). Das Template bleibt für weitere Signer offen (Mehrfachverwendung).

**Template** (type=template, status=active, join_token, expires_at)
 └─ Instanz A (type=contract, template_id=X, 1 Signer → auto-closed → Rechnung + PDF)
 └─ Instanz B (type=contract, template_id=X, 1 Signer → auto-closed → Rechnung + PDF)

### DB-Änderungen (V026)

**contracts** Tabelle:
- `type` ENUM('contract', 'template') DEFAULT 'contract' — Unterscheidung Template vs. Instanz/Standard-Vertrag
- `template_id` UUID nullable FK → contracts.id — Verweis auf das Template (nur bei Instanzen gesetzt)
- `expires_at` TIMESTAMP nullable — Ablaufdatum des Join-Links (nur bei Templates relevant)

### Flow: Template-Signierung

```
1. Admin: Template draft → active (Join-Link + optional expiry generiert)
2. Signer: /join/{token} → Name/Email/Rollen → System prüft Template aktiv + nicht abgelaufen
3. System (POST /join): Erstellt Contract-Instanz (Kopie aller Template-Daten) + ContractSigner (status=joined)
   → Returns personal_token der INSTANZ (nicht des Templates)
4. Signer: GET /sign/{personalToken} → liest Instanz-Inhalte (bestehender Flow, unverändert)
5. Signer: POST /sign/{personalToken} → Signatur (bestehender Flow, unverändert)
6. System: Nach Signatur → Instanz automatisch schließen → Rechnung (wenn Preis > 0) + PDF + E-Mail
```

### API-Änderungen

| Endpoint | Änderung |
|---|---|
| `POST /api/management/contracts` | Neue Felder: `type`, `expires_at` |
| `PUT /api/management/contracts/{id}` | `expires_at` editierbar bei Templates |
| `GET /api/management/contracts` | Filter via `?type=template` oder `?type=contract` |
| `POST /api/management/contracts/{id}/open` | Bei Templates: `expires_at` validieren (muss in Zukunft liegen) |
| `GET /api/management/contracts/{id}/instances` | **NEU**: Alle Instanzen eines Templates listen |
| `POST /api/management/contracts/{instanceId}/close` | Instanz manuell schließen (falls nicht auto-closed) |
| `POST /api/contracts/join/{token}` | **Templates**: Erstellt Instanz + Signer; **Contracts**: bestehendes Verhalten |
| `GET /api/contracts/sign/{personalToken}` | Unverändert (funktioniert mit Instanzen) |
| `POST /api/contracts/sign/{personalToken}` | Unverändert + **auto-close** nach Signatur bei Instanzen |

### Auto-Close nach Signatur

Instanzen (type=contract, template_id != null) werden nach erfolgreicher Signatur automatisch geschlossen:
- Status → 'closed'
- `ContractCloseService::close($instance)` wird getriggert
- → Rechnung (wenn items + billing_details + total > 0)
- → PDF-Generierung
- → E-Mail an Signer + billing-Empfänger

### Frontend-Änderungen

- **ManagementContractView**: Type-Selector (Vertrag / Vorlage), Expiry-Date-Picker, Instanz-Liste unter Template-Detail
- **useContractManagement.ts**: Neue Felder im Contract-Interface, neue API-Funktion `fetchInstances`
- **ContractListView**: Template-Badge/Icons, Filterung
- **ContractSignView**: Unverändert

---

### Test Cases

#### Backend PHPUnit (Feature) — ✅ ALLE IMPLEMENTIERT

**ContractControllerTest (erweitert) — 7 Tests:**
- [x] `test_can_create_template`
- [x] `test_can_open_template_with_valid_expiry`
- [x] `test_cannot_open_template_with_past_expiry`
- [x] `test_template_has_instances_relation` (heißt `test_instances_endpoint_returns_template_instances`)
- [x] `test_index_filters_by_type`
- [x] `test_can_close_instance` (heißt `test_close_instance_works`)

**ContractJoinTest (erweitert) — 6 Tests:**
- [x] `test_template_join_creates_instance_and_signer`
- [x] `test_template_instances_have_correct_template_id` (in `test_template_join_creates_instance_and_signer` integriert)
- [x] `test_template_expired_returns_410` (Check + Join getrennt)
- [x] `test_template_join_copies_items_and_terms` (heißt `test_template_join_copies_template_data`)
- [x] `test_template_sign_auto_closes_instance` + `test_standard_contract_sign_does_not_auto_close`

#### Frontend Vitest — keine separaten Template-Tests nötig (Funktionalität über API getestet)

#### E2E (Playwright) — ✅ ALLE IMPLEMENTIERT

- [x] `Admin creates template, client joins and signs, instance is auto-closed`
- [x] `Template with expired link returns 410`
- [x] `Multiple clients signing same template creates multiple instances`

---

### File Map (geändert/neu)

| File | Aktion |
|---|---|
| `backend/database/migrations/V026__contract_templates.php` | **NEU** |
| `backend/app/Models/Contract.php` | EDIT (casts, relations) |
| `backend/app/Http/Requests/StoreContractRequest.php` | EDIT (type, expires_at) |
| `backend/app/Http/Requests/UpdateContractRequest.php` | EDIT (expires_at) |
| `backend/app/Http/Controllers/ContractController.php` | EDIT (type support, instances endpoint) |
| `backend/app/Http/Controllers/ContractJoinController.php` | EDIT (template join → instance creation) |
| `backend/app/Services/ContractCloseService.php` | EDIT (no changes needed, same close logic) |
| `backend/database/factories/ContractFactory.php` | EDIT (template state) |
| `backend/routes/api.php` | EDIT (instances route) |
| `frontend/src/logic/useContractManagement.ts` | EDIT (types, API) |
| `frontend/src/ui/management/ManagementContractView.tsx` | EDIT (type selector, expiry, instances) |
| `backend/tests/Feature/Contract/ContractControllerTest.php` | EDIT |
| `backend/tests/Feature/Contract/ContractJoinTest.php` | EDIT |
| `backend/tests/Feature/Contract/ContractCloseTest.php` | EDIT |
| `frontend/tests/e2e/admin/contracts.spec.ts` | EDIT |

---

### Agent Tasks — ✅ ALLE BEREITS IMPLEMENTIERT (vor Merge)

Der gesamte Contract-Templates-Code (Backend + Frontend + Tests + E2E) war bereits vollständig implementiert, bevor der Merge in main stattfand. Keine der 5 Agent-Aufgaben musste ausgeführt werden.

---

## Security & Architecture Audit (2026-07-11)

> Vollständige Codebasis-Analyse (Backend / Frontend / Infra) aus Sicht Lead Senior Software Architect. Befunde durch dreifach Exploration (3 parallele Explore-Agenten) + Quellverifikation der Criticals. Stack: Laravel 13 (PHP 8.4) JWT-API · React 19 / Vite 8 / Tailwind 4 · MariaDB · Meilisearch · Stripe · ~942 PHPUnit- + ~69 Playwright-Tests.

### Status-Entscheidungen (bewusst akzeptiert, 11.07.2026)

- **Committed Secrets** (`frontend/.env`, `frontend/.env.local`, `backend/.env.testing`, Config-Fallbacks `APP_KEY`/`JWT_SECRET`/Stripe-Test-Secret in `backend/config/*.php`): **bewusst akzeptiert**. Begründung: Deployment-Guard in `deployment/docker-compose.yml` fängt Production-Umgebung ab; betroffen sind Dev-/Test-Keys mit niedrigem Risiko. Keine Rotation, kein History-Scrub.
- **V025-Migrations-Split (ehemals H7):** **hinfällig**, da V026 bereits live deployed. Konsolidierte Multi-Step-Migration nicht mehr rückwirkend splittbar. Dokumentiert als **Lesson learned** — künftig keine zerstörerischen DDL-Schritte + Data-Backfill in einem `up()`.

### Critical (6)

- [ ] **[C1] Hardcoded `JWT_SECRET`-Fallback im Source** — `backend/config/jwt.php:18`. Wenn `JWT_SECRET`-Env unset (Staging/CI/Frischdeploy), werden alle JWTs mit einem öffentlich in git bekannten Schlüssel signiert → beliebige User- oder Super-Admin-Tokens fälschbar. → Fix: Fallback entfernen (`env('JWT_SECRET')` ohne Default), Laravel failt laut bei fehlendem Key.
- [ ] **[C2] Hardcoded `APP_KEY`-Fallback im Source** — `backend/config/app.php:110`. Selbes Prinzip für Session/Cookie-Signatur & verschlüsselte Daten → Session-Fälschung. → Fix: Fallback entfernen.
- [ ] **[C3] Stripe Test-Secret (`sk_test_…`) committed** — `backend/.env.testing:18`. Realer Secret Key gegen Test-Account in Klartext in git (`git ls-files` bestätigt getrackt). → Fix: Im akzeptierten Scope (siehe Status-Entscheidungen).
- [ ] **[C4] Live Stripe-Publishable-Key (`pk_live_…`) committed** — `frontend/.env`. `frontend/.gitignore` hat `!.env`-Negation, die das Root-Ignore überschreibt → Key landet in git. Publishable = niedrigkritisch, aber Hygiene-Footgun. → Fix: Im akzeptierten Scope.
- [x] **[C5] Stored XSS via `dangerouslySetInnerHTML` ohne Sanitize** — `frontend/src/ui/ContractSignView.tsx:134`. Rendert `terms_html` ungefiltert. Im gesamten Frontend existiert **kein DOMPurify** (verifiziert). Jeder Pfad, der untrusted HTML in `terms_html` schreibt, executet JS im Signatur-Kontext. → **FIXED (2026-07-11):** `frontend/src/logic/sanitizeHtml.ts` (DOMPurify-Utility mit Backend-konsistenter Allowlist) + integriert in `ContractSignView.tsx:134` und `ManagementTextSnippetsView.tsx:76`. Vitest-Regression-Test in `ContractSignView.test.tsx`.
- [x] **[C6] Stripe-Webhook markiert Orders "paid" ohne Betragsprüfung** — `backend/app/Http/Controllers/WebhookController.php:60-81`. Auf `payment_intent.succeeded` wird nur `metadata->order_id` gelesen und `status='paid'` gesetzt — kein Vergleich von `amount_received` gegen `order->total_amount`, keine Currency-Prüfung. Ein 0,01-€-PaymentIntent mit gesetztem `metadata.order_id` gewährt volle Kaufrechte. → **FIXED (2026-07-11):** `amount_received < total_amount`-Guard im WebhookController (return 200 ignored, damit Stripe nicht retried). PHPUnit-Regression-Test `test_underpaid_payment_intent_does_not_mark_order_paid` in `StripeWebhookTest.php`.

### High (6 — H7 hinfällig)

- [x] **[H1] Text-Snippet-Preview ebenfalls `dangerouslySetInnerHTML` ohne Sanitize** — `frontend/src/ui/management/ManagementTextSnippetsView.tsx:76`. Selbes XSS-Risiko wie C5. → **FIXED (2026-07-11):** Defense-in-depth via `sanitizeHtml()`-Utility (siehe C5). Backend-HtmlSanitizer bleibt erste Linie.
- [x] **[H2] IDOR in Notification Opt-In** — `backend/app/Http/Controllers/NotificationController.php:43-65`. `toggleGalleryOptIn`/`toggleGroupOptIn` schreiben in beliebige Gallery/Group-IDs ohne Ownership-Check. Kombiniert mit `MailController::sendCustom` (Broadcast-Mailings) → leakt Gallery-Existenz + empfängt fremde Benachrichtigungen. → **FIXED (2026-07-11):** `canAccessGallery($id)` / `galleryGroups()->where(...)->exists()` Guards vor `updateOrInsert`. PHPUnit-Regression-Tests in `NotificationOptInTest.php`.
- [x] **[H3] IDOR in `MailController::finishRating`** — `backend/app/Http/Controllers/MailController.php:56-77`. Kein Ownership-Check → Spam/Abuse-Vector, Gallery-Existenz-Leak. → **FIXED (2026-07-11):** `canAccessGallery($gallery->id)` Guard. PHPUnit-Regression-Test in `tests/Feature/Mail/FinishRatingTest.php`.
- [x] **[H4] `ManagementMiddleware` dereferenziert nullable `auth()->user()`** — `backend/app/Http/Middleware/ManagementMiddleware.php:16`. Verlässt sich allein auf korrekte Middleware-Reihenfolge. → **FIXED (2026-07-11):** Null-Check + `auth('api')` (an `SuperAdminMiddleware` angeglichen). PHPUnit-Regression-Test in `tests/Feature/Middleware/ManagementMiddlewareTest.php`.
- [ ] **[H5] Keine CI** (kein `.github/workflows`, kein GitLab-CI). ~942 + ~69 Tests existieren, gate aber nie ein Merge. → Fix: CI-Matrix mit PHPUnit + Pint + pnpm lint + Vitest + Playwright-Smoke.
- [x] **[H6] Backend-API hat keine Security-Header-Middleware** — `backend/bootstrap/app.php`. Kein CSP, HSTS, `X-Content-Type-Options`, `X-Frame-Options` auf API-Antworten. → **FIXED (2026-07-11):** `backend/app/Http/Middleware/SetSecurityHeaders.php` (nosniff, X-Frame-Options DENY, Referrer-Policy, HSTS nur über HTTPS). Global in `bootstrap/app.php` appended. PHPUnit-Test in `tests/Feature/Middleware/SecurityHeadersTest.php`.
- [x] ~~**[H7] V025-Migrations-Split**~~ — hinfällig (V26 live). Siehe Status-Entscheidungen.

### Medium (9)

- [ ] **[M1] Rate-Limit-Default praktisch offen** — `backend/routes/api.php:39`. `AUTH_THROTTLE_LIMIT` defaultet auf `9999/min`. Login/Register/Reset ungeschützt ohne Env-Override. → Fix: Niedriger Real-Default (5/min/IP für Login) + per-Email-Throttling.
- [ ] **[M2] Runtime-`env()`-Aufrufe app-weit** — `backend/app/Http/Controllers/AuthController.php:104`, `WebhookController.php:94`, `CheckoutService.php:325`, `OrgInviteController.php:119`, `Controller.php:14`, weitere. Klassischer Laravel-Footgun bei `config:cache` (liefert `null`). → Fix: Alle in `config(...)`-Keys kapseln, nur Config lesen.
- [ ] **[M3] CSRF nur über `SameSite=Lax`** — SPA nutzt Cookie-Auth, API-Routen CSRF-exempt (Laravel-Default), kein `X-XSRF-TOKEN` gesendet. Defense-in-depth fehlt. → Fix: Sanctum Stateful-CSRF ODER `SameSite=Lax`/`strict` explizit als alleinige Kontrolle dokumentieren.
- [ ] **[M4] `APP_DEBUG` default `true`** — `backend/config/app.php:42`. Stacktrace-Leak bei unset Env. → Fix: Default auf `false`.
- [ ] **[M5] N+1 in GalleryFrontendController** — `backend/app/Http/Controllers/GalleryFrontendController.php:47-67`. Per-Photo Rating-Query in `transform()` (50 Queries/page). → Fix: Bulk-Load via `whereIn` für alle Photo-IDs, In-Memory map.
- [ ] **[M6] Frontend-Nginx: kein HSTS, kein CSP** — `frontend/nginx.conf`. Security-Header vorhanden, aber die beiden zentralen fehlen. → Fix: HSTS + striktes CSP ergänzen.
- [ ] **[M7] Watermark-SVG-Upload ohne MIME/Size-Validierung** — `backend/app/Http/Controllers/SettingsController.php:66-89`. SVG kann JS ausführen. → Fix: `mimes:svg` + Size-Cap + optional Sanitize.
- [ ] **[M8] Prod-Compose macht `optimize:clear` statt `optimize`** — `deployment/docker-compose.yml:131`. Config/Routes/Views werden pro Request neu geparst. → Fix: `php artisan optimize` nach Migrate.
- [ ] **[M9] Dual Lockfiles** (`pnpm-lock.yaml` + `bun.lock`) — können driften, kein `packageManager`-Pin in `package.json`. → Fix: Eines entfernen, `packageManager`-Field setzen.

### Low (5)

- [ ] **[L1] Service-Locator-Reste (`app()` in ~30 Stellen)** — u.a. `ContractJoinController.php:80,87,109,126,148,177,216,222`, `ContractController.php:76,142`, `User.php:96,113`, `Gallery.php:117,125`. Trotz "DI-Refactor" erhalten. → Fix: Constructor-Injection bzw. Method-Args.
- [ ] **[L2] God-Controller** — `CouponController.php` (383 Z.), `DownloadController.php` (374 Z., duplizierte ZIP/Watermark-Logik). → Fix: Aufspalten + Duplikation extrahieren.
- [ ] **[L3] Deprecated Accessor `is_customer_manager`** — `backend/app/Models/User.php:90-91`. Noch in API-Output (`AuthController.php:225`). → Fix: Entfernen nach Consumer-Check.
- [ ] **[L4] Breite `catch(\Throwable)` mit `$e->getMessage()` im Mail-Body** — `backend/app/Services/CheckoutService.php:323-327`. Möglicher Info-Leak. → Fix: Serverseitig loggen, im Mail sanitizemeldung senden.
- [ ] **[L5] Gast-JWT-Tokens praktisch nicht revozierbar** — `backend/app/Http/Controllers/InviteController.php:128-136` + `TransientUserProvider.php`. Blacklist-Write-Path fehlt. → Fix: Blacklist-Schreibpfad oder bewusste TTL-Dokumentation.

### Bestätigte Stärken (nicht regredieren lassen)

- **Brand-/Org-Isolation** konsistent durchexerziert — `BrandRegistry` + `BrandContextMiddleware` + `forCurrentBrand()`-Scopes auf 7 Modellen; explizit getestet (`OrgIsolationTest`, `brand-isolation.spec.ts`).
- **httpOnly-Cookie-Auth** — kein Token in localStorage/sessionStorage (verifiziert), deduped Refresh.
- **Keine Raw-SQL mit User-Input** — alle `whereRaw`/`selectRaw`/`DB::raw` statisch; Shell-Outs via Symfony `Process` mit Array-Args (`exiftool`, `magick`).
- **`$fillable`-Disziplin** — jedes Model explizit, nirgends `$guarded=[]`, kein `Model::create($request->all())`.
- **Bildupload mehrstufig validiert** — `image`-Rule + `mimes` + serverseitiger `exiftool`-MIME-Check.
- **File-Delivery auth-gated** — `FileDeliveryController` enforced Auth + Ablauf + Watermark.
- **Frontend-Disziplin exzellent** — 0× `any`/`@ts-ignore`/`eslint-disable`, alle 15 Forms mit Zod-Resolver, `lint --max-warnings 0`, alle localStorage-Zugänge Zod `safeParse`.
- **Preisberechnung server-autoritativ** via signiertem Offer-Token (`OfferTokenService`).
- **HTML-Sanitize beim Persistieren** (Symfony `HtmlSanitizer`) — ABER nicht beim Frontend-Render (C5/H1).
- **Vertragssigning mit optimistischer Concurrency** (`content_version` in UPDATE-WHERE).

### Ausstehend

**Umgesetzt am 2026-07-11:** C5, C6, H1, H2, H3, H4, H6 (Security-Hardening PR).
**Umgesetzt am 2026-07-13:** Meilisearch `created_at` sortable-Fix (CustomerControllerTest), localStorage-Polyfill (Vitest pre-existing 38 Failures), exiftool/ImageMagick-Symlink-Doku (README macOS-Abschnitt), `.run/`+`sync.sh` getrackt.
**Offen:** H5 (CI), alle Mediums (M1–M9) und Lows (L1–L5) — User entscheidet später.

---

## B2B Feature-Parity + Tarif-Umbenennung (2026-07-13)

> Branch `feat/b2b-feature-parity`. Ziel: Alle Features brand-agnostisch über Settings steuerbar, nicht mehr über hardcoded `isSrp`/`Brand::SRP`-Gates. Vorbereitung für SRP-Stilllegung in separatem PR.

### Status: Code-complete, Tests grün (bis auf Playwright-Smoke)

**Vitest:** 467/467 grün · **PHPUnit (betroffene Bereiche):** 59/59 grün · **Playwright @smoke:** 40 fehlgeschlagen — muss untersucht werden (vermutlich durch Pricing-Logic-Umstellung: Volume-Licensing-Default auf B2B greift nicht ohne Setting-Eintrag).

### Umgesetzt

- [x] **Fix 1 — Tarif-Umbenennung:** "Premium Tarif" → "Standard Tarif", "Standard Tarif" → "Flex Tarif" in `ShootingCalculatorModal.tsx` + `CalculatorSettingsCard.tsx`. Variable `usePremium` → `useStandard`. Keine Backend-/Logik-Änderung.
- [x] **Fix 2 — B2C Flex-Kalkulator auf B2B:** `ShootingCalculatorModal.tsx` — `isSrp &&`-Gate aus calcMode + Tab-Toggle entfernt. Beide Rechner (Flex + Standard) auf allen Brands verfügbar.
- [x] **Fix 3a-c — Volume Licensing Setting-gesteuert:**
  - `useLicensingMode.ts`: Liest `pricing_strategy` aus `useLicenseTerms` (API) statt hardcoded `brand === 'srp'`.
  - `useVolumeLicensing.ts:109`: `if (!isSrp)` → `if (licensingMode !== 'volume_licensing')`.
  - `cartLogic.ts:44`: `calculateTotalAmount(items, brand)` → `calculateTotalAmount(items, useVolumePricing: boolean)`. `CartProvider` nutzt jetzt `volumeLicensing.isVolumePricing` als单一 Source of Truth.
  - `SettingsController::getLicenseTerms`: Liefert jetzt `pricing_strategy`-Key via API (Default `'scope_licensing'`).
- [x] **Fix 3d-e — Coupons an Volume Licensing gekoppelt:**
  - Frontend: `CouponInput.tsx`, `Sidebar.tsx`, `ManagementCouponsView.tsx`, `ManagementGalleryView.tsx`, `ManagementMetaGalleryView.tsx` — alle `isSrp`-Gates durch `licensingMode === 'volume_licensing'` ersetzt.
  - Backend: `CouponController.php:332-334` — Hard-Gate `brand !== SRP` entfernt. Coupon-Verfügbarkeit wird jetzt über `PricingStrategy::supportsCoupons()` kontrolliert (Volume Licensing → Coupons erlaubt).
- [x] **Fix 4 — CRM-Alter 16:** `CustomerController.php:43,64` — `before:-10 years` → `before:-16 years`. Birthdate bleibt `nullable` (optional). PHPUnit-Regression-Test `test_birthdate_validates_minimum_age_16` hinzugefügt (15j → 422, 16j → 200, leer → 200).

### Offen / TODOs

- [ ] **Playwright @smoke 40 Failures** untersuchen. Vermutung: Volume-Licensing-Default auf B2B ohne Setting-Eintrag → `useLicensingMode` liefert `'scope_licensing'` (Fallback), Cart-Logik verhält sich anders als erwartet. Entweder Tests anpassen oder `pricing_strategy=volume_licensing` als Default-Setting auf B2B setzen.
- [ ] **PR: SRP-Stilllegung** (separater Branch nach Parity-Merge). User-Entscheidung 13.07.2026:
  - `Brand::SRP`-Case aus Enum entfernen.
  - Datenmigration: `UPDATE … SET brand='rp' WHERE brand='srp'` für alle betroffenen Tabellen (Galleries, Users, Orders, Photos, Settings, Customers, Coupons, Contracts, InvoiceSnapshots).
  - `buy.reisinger.pictures`-Domain stilllegen (kein Redirect — bewusst, User-Entscheidung).
  - Alle verbleibenden `Brand::SRP`/`isSrp`-Referenzen entfernen (~30+ Dateien).
  - `BrandRegistry::fromHost` erkennt nur noch `rp`.
  - Zukunft Mandanten: Brand-Architektur (`Brand`-Enum, `BrandRegistry`, `BrandContextMiddleware`) bleibt erhalten, neue Mandanten werden als neue Cases hinzugefügt.
- [ ] **PR: Per-Gallery Licensing Override** (später). Architektur-Refactor:
  - Neue Gallery-Spalte `licensing_mode` (nullable = inherit Brand-Setting).
  - Mixed-Cart-Problem: Warenkorb mit Fotos aus Volume- und Scope-Licensing-Galerien erfordert Refaktorierung von `PricingStrategy::calculateCart` (pro-Item-Gruppen-Auflösung).
  - Frontend-Cart-Total muss gemischte Modi berechnen.
  - Migration + Model + UI. Architektonisch signifikant.
- [ ] **`features/`-Dokumentation** aktualisieren: Neuer SOLL-Zustand für Pricing-Architektur (Setting-basiert, nicht Brand-basiert). Nach Merge des Parity-PRs.

### Validierung (Stand 2026-07-13 — nach Merge in main)

| Suite | Ergebnis | Hinweis |
|-------|----------|---------|
| `pnpm lint:fix` | ✅ 0 Warnings | |
| `pnpm build` (tsc -b) | ✅ OK | |
| Vitest (full) | ✅ 467/467 | |
| PHPUnit (full) | ⚠️ Memory-Limit bei FileDelivery | Pre-existing, nicht durch Parity-Änderungen |
| Playwright @smoke | ❌ 40 failed | Muss untersucht werden — siehe TODO oben |
