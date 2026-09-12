# Task Board — Portal Reisinger Pictures

> Stand: 2026-09-12. **Nur offene TODOs + erledigte Referenz-Blöcke.** Architekturentscheidungen in `features/`.
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## 🟢 CODE REVIEW (2026-09-12) — Full-Main-Audit (9 Subareas) — FIXED & VERIFIED

> Methodik: 9 read-only Subagenten über Backend (Auth/Security, Checkout/Payments, Controllers/Requests, Modelle/Data, AI/Mail/Jobs), Frontend (Logic, UI), Infra/CI, Lua/Tests. Fixes durch **separate** Implementer-Subagenten, nie der Reviewer.
> **Status (2026-09-12):** Alle P0- und die meisten P1-Findings umgesetzt + getestet. **Verifikation:** Backend `php artisan test` **1392 passed / 0 failed (3452 Assertions)**; Frontend `pnpm test:run` **628 passed**, `pnpm lint` 0, `pnpm build` grün.
> **Entscheidungen (User):** Brand-Isolation **strikt** (nur `brand=null` = cross-brand, z. B. erster/Super-Admin; brand-gebundene Admins isoliert); **100%-Coupon → freie Orders erlaubt** (kein Stripe-Call bei 0, Status `paid`, Download frei); **Teil-Refund behält Zugriff** (nur Voll-Refund entzieht); **kein VAT** (Kleinunternehmer/§6 UStG bzw. Reverse-Charge → aktuelles Verhalten korrekt); `.env.production` **bleibt machine-local/untracked** (`APP_DEBUG=false` gesetzt); **keine Playwright-Trace-/Report-Uploads auf PRs**; **Lua-Passwort verschlüsselt** via `LrPasswords` (OS-Keychain, Klartext migriert + entfernt); **Contract-Signer-Magic-Link = by design** (Signer haben kein Konto).
> **Offen (Rest):** P2-Tests/Doku (E2E-Tags/localStorage/Kanban-Flakiness), SHA-Pinning von Actions/Images, `Photo::$fillable 'id'` (bewusst **nicht** gefixt: `ImageController` schreibt Datei unter vorab generierter ID → Kopplung), `PricingService::calculateItemPriceCents` (bewusst behalten, öffentliche Preview-API + 20 Tests), gleich-brand Group-Ownership (Produktentscheidung), Frontend-Low-Hygiene (F10–F12 teils).
> **Regeln:** Jeder Fix braucht einen Regressionstest (DoD, Bugfix = mind. 1 Test). Backend-Fix gilt nur mit grünem `php artisan test`; Frontend mit `pnpm test:run` + `lint:fix` + `build`.
> Priorität: **P0** = Security/Geld (kritisch/hoch), **P1** = funktionale Bugs, **P2** = Härtung/Hygiene.

### P0-A — Brand-Isolation (Kernursache, Backend) — ✅ FIXED (1350→1392 Tests grün)

- [ ] **P0-A1 (CRITICAL)** `AuthorizationService::canPhotographerAccessGallery()` / `canManageGallery()` brand-scopen — `backend/app/Services/AuthorizationService.php:192-249`. Ohne das umgehen brand-gebundene Admins/Photographen via `Gallery::find()` jede Brand-Isolation (Gallery-Update/Delete, Photo-Delete, Invite, Upload). Konsequenz auf `GalleryPolicy`/`PhotoPolicy`/Controller prüfen.
- [ ] **P0-A2 (CRITICAL)** `GalleryController::updateGroup()`/`deleteGroup()` autorisieren + branden — `backend/app/Http/Controllers/GalleryController.php:63-80`, `GroupRequest.php:9-12` (`authorize()` = `true`); `parent_id`/`org_id` brand-validieren. Jeder Photograph kann fremde Gruppen umhängen/löschen.
- [ ] **P0-A3 (HIGH)** `GalleryController::showGroup()` brand-filtern — `:151-177` (kein Brand-Filter, Admin-Permission-Intersection übersprungen).
- [ ] **P0-A4 (HIGH)** `GalleryTreeService::getAdminTree()` brand-/permission-scopen + brand-spezifischer Cache-Key — `backend/app/Services/GalleryTreeService.php:15-44` (globales `gallery_tree_admin`, `$unrestrictedGroups` ohne Brand).
- [ ] **P0-A5 (HIGH)** `UserController::update()`/`destroy()` brand-isolieren; Rollen-Eskalation `org_admin` → `admin` über `role_ids` schließen — `backend/app/Http/Controllers/UserController.php:122-200`, `UpdateUserRequest.php:17-29`.
- [ ] **P0-A6 (HIGH)** `MailController::sendCustom()` mit `Gate::manage` absichern — `backend/app/Http/Controllers/MailController.php:19-54` (jeder Photograph kann beliebige Mails an fremde Galerien senden).
- [ ] **P0-A7 (HIGH)** `OrgController` Schreib-Endpunkte (update/destroy/syncUsers/syncGroups/generateCollectiveInvoice) brand-guarden; `group_ids`/`user_id` validieren — `backend/app/Http/Controllers/OrgController.php:87-243`.
- [ ] **P0-A8 (HIGH)** `StatsController::logs()`/`index()` brand-scopen — `backend/app/Http/Controllers/StatsController.php:29-51`, `StatsCalculationService.php:37-77`.
- [ ] **P0-A9 (MEDIUM)** `OrderController::indexAdmin()`/`updateStatus()` brand-scopen — `backend/app/Http/Controllers/OrderController.php:21-33`.
- [ ] **P0-A10 (MEDIUM)** `SettingsController::getLicenseTerms()` Gallery-Lookup brand-scopen — `:184-189`.
- [ ] **P0-A11 (MEDIUM)** `GalleryRequest`/`StoreGalleryRequest`/`GroupRequest`: `gallery_group_id`/`org_ids`/`parent_id` brand-konsistent validieren — `GalleryRequest.php:15,24-25`, `StoreGalleryRequest.php:18,27-28`, `GroupRequest.php:19,25`.
- [ ] **P0-A12 (MEDIUM)** `GalleryController` Sync-Access-Endpunkte: Ziel-User brand-scopen (IDOR-Pivot) — `:179-226`, `SyncGalleryAccessRequest.php:17`.
- [ ] **P0-A13 (MEDIUM)** `FileDeliveryController`: Original-Leak bei `is_public` + `restricted_photographers` schließen — `:35,54-72`.
- [ ] **P0-A14 (MEDIUM)** `AuthController`: Reset-/Aktivierungs-Token-TTL durchsetzen, Login-Input validieren, Admin-Enumeration vermeiden — `:28-31,114-128`.
- [ ] **P0-A15 (LOW)** `NotificationController` Guest-`user_id = null`-Pivot — `:43-57`; `PhotoDownloadController` Tier-Fallback `?? 3` — `:164-166,197`; `PurchaseService` Cache-Invalidierung manueller Statuswechsel — `:20-53`; `ContractController` Brand-Scope-Konsistenz; `GalleryFrontendController::rate()` Kommentar-`max`; `StoreBrandSettingsRequest` `from_name`-Regel; Watermark-SVG inline ohne CSP; `SitemapController` `is_hidden`; Test-Routen nur `local/testing`.

### P0-B — Checkout/Payments (Geld) — ✅ FIXED (B16: VAT = no VAT, dokumentiert)

- [ ] **P0-B1 (CRITICAL)** `pending_payment`-Orders dürfen keinen Download gewähren — `PurchaseService.php:28-33` (`hasPurchasedPhoto`/`downloadOrderZip` behandeln alles außer disputed/refunded/cancelled als bezahlt). Test: `pending_payment` → 403 auf `/photos/{id}/download` + `/orders/{id}/download-zip`.
- [ ] **P0-B2 (HIGH)** `PayoutController::calculate()` zerstört `approved`/`paid`-Statements — `:41-42` (Delete vor den Guards). Delete erst nach/„nur wenn kein Locked".
- [ ] **P0-B3 (HIGH)** Stripe-Fee wird pro Line-Item abgezogen statt einmal pro Order — `PayoutCalculationService.php:161-165` (Netto 10× zu niedrig, negativ möglich).
- [ ] **P0-B4 (HIGH)** `QuoteController` `custom_price` `min:1` + Zero-Guard; Negativ/Null-Quote blocken — `:50`, `CheckoutService.php:113`.
- [ ] **P0-B5 (HIGH)** `CouponAdminController`: Gallery-/Group-Ownership prüfen (Photograph kann Coupons für fremde Galerien erstellen) — `:216-237,285-312,318-329`.
- [ ] **P0-B6 (MEDIUM)** `QuoteController::sendQuote()` Ownership/Brand + Status-Guard vor `cancelled` — `:27-33`.
- [ ] **P0-B7 (MEDIUM)** `ContractJoinController`: fremdes `personal_token` nicht herausgeben (E-Mail-Bindung/Proof) — `:74-126`.
- [ ] **P0-B8 (MEDIUM)** Webhook-Invoice-Mail-Dedupe atomar (Race) — `WebhookController.php:93-96`.
- [ ] **P0-B9 (MEDIUM)** `CouponService::lockAndRevalidateCoupon()` re-validiert Ablauf/Aktiv/Scope nicht — `:154-160`; Discount-Divergenz `CheckoutService.php:107,122-126`.
- [ ] **P0-B10 (MEDIUM)** `InvoiceSequence` First-Insert-Race → 500 — `InvoiceSequence.php:26-29`.
- [ ] **P0-B11 (MEDIUM)** `InvoiceController` manuelle Rechnungsnummer: Eindeutigkeit/Format — `:28-39`.
- [ ] **P0-B12 (MEDIUM)** Teil-Refund als Voll-Refund behandelt (Zugriff entzogen) — `WebhookController.php:113-124` (Intention verifizieren).
- [ ] **P0-B13 (MEDIUM)** Cross-Brand License-Modifier → 500 statt 4xx — `ScopeLicensingStrategy.php:94-96`.
- [ ] **P0-B14 (MEDIUM)** 100%-Coupon unbenutzbar (Total 0 → 400) — `CheckoutService.php:113` (gewollt?).
- [ ] **P0-B15 (MEDIUM)** `CouponUpdateRequest` undefinierte `$brandValue` → 500 — `:87,99`.
- [ ] **P0-B16 (VERIFY)** VAT: `tax_rate => null`, `total_gross = total_net` — `CheckoutService.php:419` (Kleinunternehmer/Reverse-Charge fachlich prüfen).

### P1 — Frontend — ✅ High-Findings (F1–F9) gefixt; Low-Hygiene (F10–F12) teils offen

- [ ] **P1-F1 (HIGH)** Coupon wird nie an Checkout übergeben (zwei unabhängige `useCoupon`-Instanzen) — `ui/client/ClientCartView.tsx:50,161`, `ui/client/components/CouponInput.tsx:19`. Coupon-State zentralisieren (Context) oder Callback hochreichen. Test: Vitest/E2E Checkout mit Coupon.
- [ ] **P1-F2 (HIGH)** `GalleryGroupModal` „Zugeordnete Organisation" ist No-op (`org_id` wird nie übergeben) — `ui/components/GalleryGroupModal.tsx:167`, `logic/useGalleries.ts:82,87`.
- [ ] **P1-F3 (HIGH)** `api.ts:85` `/api/auth/me` 401 ohne Refresh → Logout nach JWT-TTL (verifizieren ob gewollt) — `logic/api.ts:85`.
- [ ] **P1-F4 (HIGH)** `useGallery::ratePhoto` verschluckt alle Fehler ≠ 401, optimistisches Rating bleibt — `logic/useGallery.ts:67-98`; 401-Redirect auf nicht-existentes `/login` — `:89-90`.
- [ ] **P1-F5 (MEDIUM)** `GalleryModal`: erzwungene Parent-Visibility wird nicht in RHF geschrieben (Privacy-Leak möglich) — `ui/components/GalleryModal.tsx:194` (Backend-Override verifizieren).
- [ ] **P1-F6 (MEDIUM)** `ContractSignView` Gesamtsumme ignoriert Prozent-Rabatte — `:182` (Snapshot-Semantik verifizieren).
- [ ] **P1-F7 (MEDIUM)** Module-scope Lingui `t` (STRICT, Prod-Blank-Page-Risiko) in zahlreichen Schema-/Const-Dateien (u. a. `management/components/LicenseSettingsCard.tsx:10-11`, `ResetPassword.tsx:12`, `ProjectModal.tsx`, `TextSnippetModal.tsx`, `ProductModal.tsx`, `CustomerModal.tsx`, `CreateUserModal.tsx`, `ProfileSettingsCard.tsx`, `BillingDetailsCard.tsx`, `photographer/components/PhotoJobModal.tsx`, `PhotographerProductionBoard.tsx`) → Schema-Factory. `check-i18n.mjs` um Regel erweitern.
- [ ] **P1-F8 (MEDIUM)** `ClientCartView`: Billing-Form wird bei jeder `user`-Revalidierung zurückgesetzt (kein `isDirty`-Guard) — `:125-136`.
- [ ] **P1-F9 (MEDIUM)** `VolumePresetSettingsCard`: Dezimalwerte nicht eintippbar (`toFixed(2)` + sofortiges Parsen) — `:109,133,141`.
- [ ] **P1-F10 (LOW)** `useSettings::updateWatermark` ignoriert `res.ok` (Erfolgs-Toast trotz Fehler) — `:20-28`; `useAuth` Logout leert SWR-Cache nicht; `useCoupon` ohne Request-Sequencing; `useInvoiceDraft` impure `setItems`-Updater (`markDirty` in Updater); `useAuth::register` `json()` vor `ok`; `useGallery::toggleOptIn` ignoriert Status; `useSearch`/`useLocations` `key:null` + `keepPreviousData`; `handleApiError` roher Server-Text; `useContractHeartbeat` `.catch(()=>{})`; `App.tsx` GlobalErrorCallback ohne Cleanup; `brandRegistry` Host-Fallback/`matchMedia` ohne Teardown; `useStats` Tier-Key; `useAuth::login` generische Fehlermeldung; rohe `fetch`-Calls umgehen Refresh-Pipeline; `safeJsonParse` dead.
- [ ] **P1-F11 (LOW)** `ManagementPayoutsView` `parseInt` → `NaN`; Invoice-Listen Index-Keys (Reorder); `target="_blank"` ohne `rel="noopener noreferrer"`; Quote-Token-Rundungsdrift; `UIProvider` `confirm()`-Dangling; Modals ohne Focus-Trap; native `window.confirm`.
- [ ] **P1-F12 (LOW)** Field-Label-Policy: fehlende `required`-Attribute (`SidebarLoginForm`, `CreateUserModal`, `CustomerModal`, `ProfileSettingsCard`); `(optional)`-Text (`ManagementOrdersView.tsx:151`).

### P1 — AI / Mail / Jobs / Console — ✅ FIXED

- [ ] **P1-A1 (HIGH)** `AIController::generateMetadataText()` ohne Authz/Role (Kosten-Abuse) — `:57-79`.
- [ ] **P1-A2 (MEDIUM)** Anthropic-Provider sendet OpenAI-Image-Format → 400 — `AI/Providers/AnthropicProvider.php:21-26`, `AIService.php:47`.
- [ ] **P1-A3 (MEDIUM)** Delete-Jobs verschlucken Fehler (`'throw'=>false`, Rückgabewerte ungeprüft) — `config/filesystems.php:33-37`, `Jobs/DeletePhotoFilesJob.php:52`, `DeleteGalleryFolderJob.php:30`.
- [ ] **P1-A4 (MEDIUM)** `ProcessCollectiveInvoices` ignoriert `error` (stiller Ausfall) — `Console/Commands/ProcessCollectiveInvoices.php:34-40`.
- [ ] **P1-A5 (MEDIUM)** `import-locations` läuft bei jedem Boot, über HTTP, mit `truncate()` — `deployment/docker-compose.yml:153`, `Console/Commands/ImportLocations.php:32,68,87,128`.
- [ ] **P1-A6 (MEDIUM)** AI-Provider-Fehlerbody voll geloggt (Prompt/PII) — `Services/AIService.php:87`.
- [ ] **P1-A7 (LOW)** AI-Connection-Exception nicht gefangen → 500; `imagecreatefromstring` Speicher; Session-Prefix nicht saniert; `QUEUE_CONNECTION=sync`-Default; Mail-Worker-Timeout vs `retry_after`; Scheduler ohne `withoutOverlapping`; `CleanupGalleries` Dateien vor DB; Log-/Mail-Defaults; Prompt-Injection; Duplicate-Mail bei Retry.

### P1 — Lua-Plugin — ✅ FIXED (Passwort via LrPasswords/OS-Keychain)

- [ ] **P1-L1 (HIGH)** Portal-Passwort im Klartext in `LrPrefs` + vorausgefüllt — `ManagerCore.lua:87,49`, `PluginInfoProvider.lua:35`.
- [ ] **P1-L2 (MEDIUM)** `Api.login` hängt an totem `access_token`-Branch + `Set-Cookie`-Parsing (LrHttp-Verhalten verifizieren) — `Api.lua:68-82`.
- [ ] **P1-L3 (MEDIUM)** Kein JWT-Refresh/401-Handling für lange Sessions — `ManagerCore.lua:34`, `Api.lua:31-48`; nicht-idempotente POSTs werden bei 5xx wiederholt.
- [ ] **P1-L4 (MEDIUM)** `RatingStatusDialog` blockiert Lightroom durch synchrones HTTP außerhalb `LrAsyncTask` — `:26-27,39`.
- [ ] **P1-L5 (MEDIUM)** `/auth/me`-Transientfehler = permanente Verweigerung; `reloadTree` verschluckt Fehler; kein HTTP-Timeout — `ManagerCore.lua:34-40,99-102`, `Api.lua:33-38,98,103`.
- [ ] **P1-L6 (LOW)** Doppelter `X-HTTP-Method-Override`; `uploadMultipart`-Fehlerkontrakt falsch benannt; `convertToDelivery`-Status ignoriert; Modal im Write-Access; `LrProgressScope` nil.

### P1 — Infra / CI / Deploy — ✅ FIXED (SHA-Pinning der Actions/Images offen)

- [ ] **P1-I1 (HIGH)** `.env.production` liegt mit Live-Secrets (Stripe live, whsec, SMTP, Make, AI-Key, APP_KEY, JWT_SECRET, DB) unverschlüsselt auf Platte (nicht getrackt, aber Risiko) → Secrets rotieren/Secret-Manager; `APP_DEBUG=true` in `.env.production:5` + `deployment/docker-compose.yml:80` + Default `true` in `config/app.php:42` auf `false`.
- [ ] **P1-I2 (MEDIUM)** Öffentliches Repo: Playwright-Artefakte (Traces) können httpOnly-JWT-Cookies leaken — `.github/workflows/ci.yml:427-441`, `frontend/playwright.config.ts:18`.
- [ ] **P1-I3 (MEDIUM)** `automerge.yml`: `${{ steps.metadata.outputs.* }}` direkt in `actions/github-script` (Injection) + `allowed-conclusions: success,skipped` ohne Branch-Protection — `:38,41,73`.
- [ ] **P1-I4 (MEDIUM)** Deploy-Secret-Gate fail-open wenn `APP_ENV != production` — `deployment/docker-compose.yml:138-145`.
- [ ] **P1-I5 (MEDIUM)** Container laufen als root (inkl. Queue-Worker auf Bind-Mounts) — `deployment/Dockerfile:26-27`, `docker-compose.yml:55-73,136-158`.
- [ ] **P1-I6 (MEDIUM)** CI ohne minimales `permissions:`-Block; Actions/Images nur per Tag gepinnt (Supply-Chain) — `.github/workflows/*.yml`, `docker-compose.yml:49,56`, `Dockerfile.e2e:18,30`.
- [ ] **P1-I7 (MEDIUM)** `rclone-backend-filter.txt` schließt `.env.production`/`.env.ci` nicht aus (destruktiver `sync`) — `:1-27`, `sync.sh:8`.
- [ ] **P1-I8 (LOW)** CI-Debug-Step fingerprintet Key-Material; getracktes `.env.encrypted`; Node-Runtime ohne Checksum; `ACCOUNTING_EMAIL`-Env-Drift; Deployment-Doku widerspricht Code (`features/infrastructure/01-deployment.md:49-55`).

### P2 — Tests / Doku — ⏳ OFFEN

- [ ] **P2-T1 (MEDIUM)** E2E localStorage-Injection (STRICT-Verstoß) — `frontend/tests/e2e/client/cart-persistence.spec.ts:31-56`.
- [ ] **P2-T2 (MEDIUM)** Kanban-E2E: `waitForTimeout` + Pixel-Drag-Retries = flaky — `tests/e2e/helpers/KanbanHelper.ts:135,143,196,231`.
- [ ] **P2-T3 (VERIFY)** E2E-Timeout-Policy im Config nicht abbildbar — `frontend/playwright.config.ts:11`.
- [ ] **P2-T4 (LOW)** Keine Lua-Tests; `useAuth.test` mockt SWR komplett; `ManagementGalleryView.test` stubbt ~12 Kinder; `StorageLifecycleTest` `sleep(1)`.
- [ ] **P2-T5** Doku-Drift Deployment (C1–C3b-Fallbacks entfernt, Doku behauptet sie noch) — `features/infrastructure/01-deployment.md`.

### P1 — Modelle / Services / Data-Integrity — ✅ überwiegend FIXED (M3/M12 teilw.)

> **Wichtiger Kontext:** `Brand`-Enum enthält aktuell nur `rp` (SRP in V025/V031 entfernt) → viele Brand-Isolation-Lücken (P0-A*) sind **latent**, nicht live ausnutzbar. Sie werden dennoch gefixt, weil ein zweiter Brand sie sofort scharf macht.

- [ ] **P1-M1 (HIGH)** `GalleryService::updateGallery()` löscht Org-Zuordnungen bei Teil-Update — `:175` (`orgs()->sync($data['org_ids'] ?? [])` bei `sometimes`). Trigger: PATCH ohne `org_ids` (z. B. `is_live`) → `sync([])`. Test: PATCH ohne org_ids behält Orgs.
- [ ] **P1-M2 (HIGH, latent)** `Setting` nutzt Single-Column-PK über Composite-PK `(key, brand)` → `updateOrCreate` einer Brand updated alle Brands — `Models/Setting.php:16`, `SettingResolver.php:49`, `BrandSettingsService.php:79`. Test: zwei Brands, Set nur für eine.
- [ ] **P1-M3 (HIGH, latent)** `GalleryTreeService` brand-scope + brand-spezifischer Cache-Key (siehe P0-A4).
- [ ] **P1-M4 (MEDIUM)** `slug: null` im Gallery-Update → TypeError/500 — `GalleryService.php:149-151`. Test: PATCH `slug: null` → 422/200 statt 500.
- [ ] **P1-M5 (MEDIUM)** Volume-Pricing falsch bei nicht-monotonen/doppelten Tier-Preisen; keine Monotonie-Validierung — `VolumeLicensingStrategy.php:197-214`, `VolumePresetController.php:45-50`.
- [ ] **P1-M6 (MEDIUM)** Stats global statt pro Brand — `StatsCalculationService.php:43-46,52-57` (siehe P0-A8).
- [ ] **P1-M7 (MEDIUM)** `InvoiceSequence::firstOrCreate` Race (siehe P0-B10).
- [ ] **P1-M8 (MEDIUM)** N+1/unbounded in Rating-Endpunkten — `RatingService.php:24-30,67-75`.
- [ ] **P1-M9 (MEDIUM)** Admin-Tree-Build mit Relation-N+1 (`effective_*`, `full_path`) — `GalleryTreeService.php:17-25`.
- [ ] **P1-M10 (MEDIUM)** `org_ids`-Accessor liefert immer `[]` (kein Eager-Load `orgs`) — `Models/Gallery.php:168-174,70`.
- [ ] **P1-M11 (LOW-MED)** `ftp_slug`-Generierung nicht race-safe — `Models/User.php:47-58`; `Photo` erlaubt `id`-Mass-Assignment — `Models/Photo.php:28`.
- [ ] **P1-M12 (LOW)** `CouponFactory` `int`-Typen für UUID-Scopes — `CouponFactory.php:72,97`; Factories ohne Brand (Scope unsichtbar) — `ProductFactory/GalleryFactory/GalleryGroupFactory/SettingFactory`.
- [ ] **P1-M13 (LOW)** `ContractTemplateService::createInstance` ohne Transaction — `:19-41`; `ImageProcessor` Zero-Height-Thumbnail — `:20`; `VolumePreset::forBrand` ignoriert `is_default` — `:42-46`; `PricingService::calculateItemPriceCents` dead code — `:27-49`.
- [ ] **P1-M14 (LOW)** Status-Model-Events werfen `InvalidArgumentException` bei Legacy-Wert — `Order.php:47-55`, `Project.php:55-68`, `PhotoJob.php:52-60`; `ContractCloseService::close` nicht idempotent — `:17-90`; rekursive/unbounded Eager-Loads — `GalleryGroup.php:204-207`, `GalleryTreeService.php:144-152`.
- [ ] **P1-M15 (LOW, latent)** Gallery an brand-fremde Gruppe (siehe P0-A11); `effective_licensing_mode` liest Request-Brand statt Gallery-Brand — `Models/Gallery.php:72-81`; `AgeHelper` Carbon-Sign/Floor — `:16-18`.
- [ ] **P1-M16 (LOW)** Fehlende Natural-Unique-Constraints (`payout_pools`, `photographer_statements`) + `full_zip` `photo_count`-Default 1 — V001/V009/V011 (Writer prüfen).

### ✅ Verifikation / Baseline (2026-09-12)

- [ ] **Baseline:** `php artisan test` einmalig full (separater Subagent) nach der ersten Fix-Welle; Frontend `pnpm test:run` + `pnpm lint` + `pnpm build`.

---

## ✅ ERLEDIGT (2026-09-09) — OpenCode Go Compliance (User-Agent + Session-Header)

> Mail 2026-09-07 (OpenCode Go): 1) kein missbräuchlicher Traffic, 2) proper User-Agent (kein generischer), 3) `x-opencode-session`-Header für Prompt-Caching.
> Befund: 3) ✅ erfüllt (`HasSessionHeader`, default `x-opencode-session`, Prefix `portal-`, Tests grün), 1) ✅ kein Retry/Polling (ein Request pro `callAI()`, timeout 120), 2) ❌ fehlt (kein `User-Agent` in `buildHeaders`, fällt auf Guzzle-Default zurück).
> Vorgabe User: User-Agent hart auf `reisinger.pictures Portal` setzen. Wenn nur das → gleich commit+push+CI grün, sonst manuelle Bestätigung.

- [x] Backend: `User-Agent: reisinger.pictures Portal` hart in allen AI-Providern senden (zentral, nicht konfigurierbar) → PHPUnit Regression (OpenAI/Anthropic/LMStudio `buildHeaders` enthält Header, Feature `Http::assertSent` prüft Header)
- [x] Doku: SOLL-Zustand in `features/ai/01-ai-service-architecture.md` (§5.7 / §6) + `.env.example`-Kommentar prüfen (kein neuer Env-Key)
- [x] Verifikation (separater Subagent, nie Implementer): `php artisan test` grün, Diff-Review, dann commit+push+CI grün
- Verifiziert 2026-09-09: volle Suite 1213 passed / 0 failed (3012 Assertions); `HasUserAgent`-Concern, 3 UA-Regressionstests; Urteil READY_TO_COMMIT.

---

---

## ✅ Erledigt (2026-08-19) — F3 + P1 + A1 Komplett

Alle drei Pakete implementiert, verifiziert und committed (22 Commits, `main` ahead of `origin/main`):

| Paket | Umfang | Tests |
|-------|--------|-------|
| **F3** Brand Settings Admin-UI | Backend (Service/Controller/Routes) + Frontend (Hook/Card/E2E) + Doku | 24 PHP, 4 Vitest, E2E spec |
| **P1** Coupon `photo_package` | V030 Migration, Service, Frontend (Form/Listen), E2E | 72 PHP, 4 Vitest, E2E spec (4 Tests) |
| **A1** Authorization Refactoring (Steps 1–7) | Model-Delegation, Gates, Middleware, Policies, Controllers (6a–6f), PurchaseService | 1192 PHP, Zero `$user->is_*` in Controllers/Policies |

**Letzter Stand:** 1192/0 PHPUnit, 593/0 Vitest, lint+build 0.

---

## ✅ Erledigt (2026-08-18) — CI/Test-Image + Tooling

- **portal-e2e Docker-Image:** CI-Beschleunigung (−33% Critical Path), Stripe-Idempotency-Race gefixt, Shard-Split 3→5. Doku: `features/infrastructure/28-ci-test-image.md`.
- **CodeGraph Pre-Commit-Hook:** `.githooks/pre-commit` → `codegraph sync -q`, fails open. Doku: `AGENTS.md` §11.
- **Zentrales Skills-Repo:** `agents-skills` (GitHub), Skills global registriert. Doku: `AGENTS.md` §12.

## ✅ Erledigt (2026-08-18) — WYSIWYG + PDF + Responsive UI

- Tasks A–L: WYSIWYG-Resize, PDF-Entduplizierung, Kalkulation-oben, Typografie (orphans/widows), Baustein-Select entfernt, Item-/Discount-Responsive-Layout, Löschen rechtsbündig, Rabatt-Gesamt-Spalte.
- Kanban PDF-Drop E2E-Test.

---

## 🟡 OFFEN (Future) — pricing_strategy als Brand-Setting

- Langfristig: `pricing_strategy` je Brand in Admin-UI editierbar (DB-Overlay, Choke-Point `BrandRegistry::buildFromArray()`). Doku: `features/infrastructure/17-pricing-strategy-pattern.md` §7.

## 🟡 OFFEN (manuell) — Prod-Infra

- **Portainer Stack-Redeploy** für `portal-base:8.5` (User-Notify erledigt, Deploy pending).

---

## ✅ ERLEDIGT (2026-08-31, magenta) — Prod-Bildlieferung: Header-Mismatch (X-Sendfile vs X-Accel-Redirect)

- **Fix:** `.env.production` → `PROXY_DELIVERY_HEADER=X-Accel-Redirect` + `PHOTO_STORAGE_PATH=/var/www/photos`; `deployment/docker-compose.yml` → Pass-through `PHOTO_STORAGE_PATH=${PHOTO_STORAGE_PATH}` ergänzt; Backend-Container neu deployt.
- **Verifiziert:** alle `/api/media/*`-Größen (250/400/800/1200/2000) + Original-Branch liefern echte WebP/JPEG-Bytes (10–780 KB), kein `x-sendfile`/`x-accel-redirect`-Leak mehr; `/context`, `license-terms`, alle SPA-Chunks 200.
- **Restbefund:** „Fehler persistierte“ war Browser-Cache der leeren `immutable`-Antworten (max-age 1 Jahr) + alte Bundle-Stände — nach Cache-Leeren + Voll-Refresh behoben.
- **Hinweis:** `deployment/docker-compose.yml`-Änderung (1 Zeile, git-getrackt) liegt noch als `M` im Working Tree — Commit+Pull steht aus.

---

## 🟠 OFFEN (2026-08-31) — Rücktrittsrecht-Compliance für Foto-Downloads

> Ziel: Rücktrittsrecht erlischt rechtskonform (nur digitale Produkte, kein physischer Mix → §13a Mischkorb n/a).
> Plan: `~/.opencode/plan/withdrawal-rights-compliance.md`. Hinweis: Rechtstext-Wording vor Go-live juristisch absegnen lassen.

**WI-A Backend — Consent-Erzwingung + Protokollierung**
- [x] V032-Migration: `orders.withdrawal_waived` (bool, default false) + `orders.withdrawal_consent_at` (timestamp) → Persistenz-PHPUnit ✅ (`WithdrawalConsentTest`, 8 passed, 40 assertions).
- [x] `Order`-Model: fillable + casts.
- [x] `CheckoutService::processCheckout`: 422 wenn `!isQuoteRequest && !withdrawal_waived` → Regression-PHPUnit ✅ (false/missing → 422; Quote ohne Bedingung → OK; quote_token-Flow abgedeckt).
- [x] `createOrder` + `createInvoiceSnapshot`: Consent + Zeitstempel persistieren; Nachweis in `customer_details.withdrawal_consent` (immutables Evidence Package).
- [x] FAGG-Zitierung korrigiert: § 18 Abs. 1 **Z 11** (CheckoutService-Konstante + InvoiceMail); fix-Subagent verifiziert, kein „Z 10“ mehr im Backend.

**WI-B Backend — Widerruf-Absatz in Kaufmail + Rechnungs-PDF**
- [x] `InvoiceMail` customBody: Absatz „Zustimmung Sofort-Download + Erlöschen Rücktrittsrecht (inkl. Zeitstempel)“ → PHPUnit Mail-Render enthält Absatz ✅.
- [x] `pdf/invoice.blade.php`: Widerruf-Abschnitt (nur wenn Consent vorliegt) → PHPUnit PDF-Output enthält Abschnitt ✅.

**WI-C Frontend — Checkout-Text verfeinern („sofortiger Download“ explizit) + Schema-Factory**
- [x] Checkbox-Text in `ClientCartView.tsx` anpassen → **Test-TODO:** Vitest auf neuen Text (✅ 597 Vitest-Tests grün, `lint:fix` + `build` fehlerfrei, Prod-Probe ohne Lingui-pageerror).
- [x] `checkoutSchema` in Factory-Funktion umbauen (Lingui module-scope-Regel, frontend/AGENTS.md REG 2026-08-12).

**WI-D Frontend — Rechtliche Seiten AGB + Widerrufsbelehrung**
- [x] `LicenseTerms.tsx` (/license-terms) inkl. § 18 Abs. 1 Z 11 FAGG-Klausel → **Test-TODO:** Vitest (✅) + E2E (`tests/e2e/guest/legal-pages.spec.ts`, `@feature:legal` — E2E-Lauf steht in Verifikationsphase, Stack nicht gestartet).
- [x] `Widerrufsbelehrung.tsx` (/widerruf) mit Erlöschen-Absatz → **Test-TODO:** Vitest (✅) + E2E (s.o.).
- [x] Routen in `App.tsx`, Verlinkungen (Impressum/Footer), toter `/license-terms`-Link wird funktionsfähig.
  - Hinweis: Formulierung „Rücktritts- bzw. Widerrufsrecht“ (erfüllt Unit-Test-Regex `/widerrufsrecht/i` + Rechtstext). Vor Go-live juristisch absegnen.

**WI-E Docs**
- [x] `features/ecommerce/06-legal-evidence-and-disputes.md`: Consent als Teil des Evidence Package; „keine physischen Produkte“-Entscheidung festhalten (Abschnitt 3 + Related bereinigt).

**Verifikation (Subagent, nie Implementierer)** → ✅ abgeschlossen (31.08.2026): Backend 1200/2989, Frontend 597, lint+build 0, `@smoke` 58 passed, `@feature:legal` 6 passed (nach Fix der Test-Deklinationsform „sofortiger Download“). Diff-Review PASS, Gesamturteil READY_TO_COMMIT.
- [x] WI-A, WI-B, WI-C, WI-D, WI-E komplett implementiert und verifiziert.
- [x] Hinweis: Rechtstext-Wording (Checkbox, Mail/PDF, AGB, Widerrufsbelehrung) vor Go-live juristisch absegnen lassen.

---

## 📋 Archivierte Backlog-Pläne (2026-08-04)

> Nur Referenz. Umsetzung abgeschlossen oder obsolet.

- **A1** User-God-Entity → ✅ erledigt (Steps 1–7, siehe oben)
- **F3** Brand Settings UI → ✅ erledigt (siehe oben)
- **P1** Coupon photo_package → ✅ erledigt (siehe oben)
- **Stack-Konsolidierung** → ❌ OBSOLET (SQLite-Richtung)

---

## 🚫 Blockiert (Dependency-Migration 2026-08-23)

- **typescript 6→7:** Risiko durch TS7, erst nach Framework-Support. TS 7.0 ist zu frisch (kein Support durch Vite/Rolldown-Babel-Pipeline, ESLint-Typescript-Stack, React-Compiler-Preset). `frontend/package.json` bleibt bei `^6.0.3`. Nachzuziehen, sobald das Tooling TS7 deklariert.
  - Verifiziert 2026-08-25: weiterhin offen (`frontend/package.json`: `"typescript": "^6.0.3"`).
