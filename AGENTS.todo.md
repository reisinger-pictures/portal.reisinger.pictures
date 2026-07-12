# Task Board — Portal Reisinger Pictures

> Stand: 2026-07-09. Contract Templates Feature in Umsetzung.

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

#### Backend PHPUnit (Feature)

**ContractControllerTest (erweitern):**
- [ ] `test_can_create_template` — POST mit type=template, expires_at
- [ ] `test_can_open_template_with_valid_expiry` — Template draft → active mit expires_at in Zukunft
- [ ] `test_cannot_open_template_with_past_expiry` — Expiry in Vergangenheit → 422
- [ ] `test_template_has_instances_relation` — GET /{id}/instances listet Instanzen
- [ ] `test_index_filters_by_type` — ?type=template filtert
- [ ] `test_can_close_instance` — Instanz schließen funktioniert

**ContractJoinTest (erweitern):**
- [ ] `test_template_join_creates_instance_and_signer` — POST /join/{templateToken} → erstellt neuen Contract (Instanz) + Signer
- [ ] `test_template_instances_have_correct_template_id` — Instanz hat template_id = Template-ID
- [ ] `test_template_expired_returns_410` — Join-Link mit abgelaufenem expires_at → 410
- [ ] `test_template_join_copies_items_and_terms` — Instanz hat kopierte items/discounts/terms_html
- [ ] `test_template_sign_auto_closes_instance` — Nach Signatur: Instanz status = 'closed'
- [ ] `test_template_sign_triggers_close_service` — Invoice wird erstellt wenn items > 0

**New: ContractTemplateTest (optional, oder in bestehende integrieren)**

#### Backend Unit

- [ ] `test_contract_model_has_type_cast` — type ist im $casts
- [ ] `test_contract_template_relation` — template() und instances() Relationen existieren

#### Frontend Vitest

- [ ] `useContractManagement.test.ts`: Neue Felder in Typen, fetchInstances Funktion
- [ ] `ManagementContractView.test.tsx`: Type-Selector, Expiry-Picker, Instance-List (neue Tests)

#### E2E (Playwright)

- [ ] `@feature:admin:contracts:template` Template erstellen, öffnen, Client signiert via API → Instanz erstellt, auto-closed
- [ ] `@feature:admin:contracts:template` Abgelaufener Template-Link → 410
- [ ] `@feature:admin:contracts:template` Mehrere Clients signieren dasselbe Template → mehrere Instanzen

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

### Agent Tasks

#### Agent 1: Migration + Model + Factory
Files: V026 migration, Contract.php, ContractFactory.php
- Create `V026__contract_templates.php` migration
- Add `type`, `template_id`, `expires_at` columns
- Update Contract model: casts, `template()`, `instances()`, `isTemplate()` scope
- Update ContractFactory: add `template()` state, set `type` default to 'contract'

#### Agent 2: Backend Controllers + Requests + Services
Files: StoreContractRequest, UpdateContractRequest, ContractController, ContractJoinController, routes/api.php
- Add `type`, `expires_at` validation to requests
- ContractController: type in store, expiry validation in open, instances() endpoint, filter by type in index
- ContractJoinController: join() for templates → create instance + signer
- ContractJoinController: sign() → auto-close for instances (type=contract with template_id)
- Add routes

#### Agent 3: Frontend
Files: useContractManagement.ts, ManagementContractView.tsx
- Update Contract interface with type, template_id, expires_at
- Add Instance interface + fetchInstances function
- UI: type selector radio, expiry date picker, instance list table in template detail
- Update useContractJoin.ts if needed

#### Agent 4: Backend Tests
Files: ContractControllerTest, ContractJoinTest, ContractCloseTest
- Add template-specific tests
- Update existing tests for new fields (backward compatible)
- Test auto-close on sign for instances
- Test expiry validation

#### Agent 5: Frontend Tests + E2E
Files: useContractJoin.test.ts, useContractHeartbeat.test.ts, ContractSignView.test.tsx, contracts.spec.ts
- Update vitest mocks for new response shapes
- Add E2E test: template create → open → client sign → instance auto-closed
- Add E2E test: expired template link → 410
- Add E2E test: multi-client → multi-instance

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
**Offen:** H5 (CI), alle Mediums (M1–M9) und Lows (L1–L5) — User entscheidet später.
**Backend-Tests/E2E ausstehend:** PHPUnit + Playwright-Smoke konnten lokal nicht validiert werden (keine MariaDB / kein Backend-Laufzeit auf Port 4321 in dieser Umgebung). Code folgt etablierten Patterns; Validierung muss in einer Umgebung mit DB + Backend erfolgen.
