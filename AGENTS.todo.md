# Task Board — Portal Reisinger Pictures

> Stand: 2026-09-19. **Nur offene TODOs + erledigte Referenz-Blöcke.** Architekturentscheidungen in `features/`.
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## ✅ ERLEDIGT (2026-09-18/19) — Model-Registrierung (Magic-Link) + Profile-Iteration

> Plan: `~/.opencode/plan/model-registrierung.md` · SOLL: `features/crm/05-model-registration.md` + `features/crm/06-model-profile-iteration.md`.
> Admin lädt eine Managerperson per kopierbarem Magic-Link ein (Mail optional); diese registriert login-frei einen **Act** mit 1..n Personen (je Person = CRM-Customer + ModelProfile). Altersnachweis **immer Pflicht**. Fragenkatalog **Code-first + Answers-Snapshot**.
> **Status:** deployed (Commit `3db7437`, push + Redeploy done). Backend **1577** PHPUnit, Frontend **706** Vitest, Lint/Build grün, **12** Feature-E2E.
> **Nachtrag 2026-09-19 (uncommitted):** Profil-Update-Mail + Contact-Sheet-Export (Backend+Frontend) + E2E-Ausbau. Backend **1587** PHPUnit, Frontend **722** Vitest, E2E-Grep (`model-registration|model-access|model-export`) **22** passed — alles READY-verifiziert.
> **Nachtrag 2 (2026-09-19):** N1 (Manager-Transfer + Nachfolge + V035) + N2 (Inaktive nur Super-Admin, 403 fail-closed). Backend **1596**, Vitest **726**, E2E-Grep **28/28** — READY-verifiziert.

**Backend (PHPUnit) — implementiert & verifiziert**
- [x] V033-Migration (`customers.user_id`/`is_model`, `model_profiles`, `acts`, `act_members`, `model_registration_invites`) + V034 (Fotos/Tokens/Lifecycle)
- [x] Modelle + Relations + DB-Projektion (DB-Filter-Suche, kein Scout)
- [x] `ModelQuestionnaire` (Single-Katalog v1; Sektionen; Kategorien; Schwellen-/Score-/Ordinal-Helper)
- [x] Controller: Admin-Invite (Magic-Link primary, Mail optional), öffentlich check/submit (multipart, Einmal-Token atomar), Admin-Liste/Revoke, Model-Suche, Age-Proof-Download (auth-gated)
- [x] Mailables: Invite- + Success-Mail an `invited_by` (brand-aware, harte Fakten + Deeplinks)
- [x] Encrypted Storage (AES-256-GCM, Paket, eigener `FILE_ENCRYPTION_KEY`) + EXIF-Strip + Auth-Gate + Audit
- [x] Lifecycle 13+2 (Reminder T+12/13/14, Confirm-Reset, Expiry-Hard-Delete) + DSGVO-Löschung (Super-Admin, DRY-Eraser)
- [x] Profil-Zugang: Profil-Magic-Link 24h + Revoke, öffentlich lesen/aktualisieren, „Meine Profile", Foto-Management, Altersnachweis-Re-Upload
- [x] Suche: Kategorie-/Bereitschafts-Multi-Chips, Schwellen (Minimum-Semantik), Match-Score-Default-Sort, Alter von/bis als Zahlenfelder
- [x] Free-Review-Fixes B1–B4 + F1/F2 (deferred Mails, Lifecycle-Null-Anker, atomarer Owner-Update, eingeschränkter Constraint-Drop, Age-Proof-Upload) — READY-verifiziert

**Frontend (Vitest + Playwright) — implementiert & verifiziert**
- [x] Öffentliche Route `/model-registrierung/:token` (Gast-Layout, RHF+Zod, Personenblöcke, Slider, Foto-DnD, Uploads)
- [x] Admin: **ein** Menüpunkt „Models"; Einladung als Dialog auf der Models-Seite (`/admin-model-invites` → Redirect)
- [x] Models-Suche: Karten-Layout (breit, kein innerer Scroll), Matrix (Zeilen sortiert + gematchte Kategorie oben), Detail-Dialog read-only, `?model=`-Deeplink, URL-synchronisierte Filter, Status-/Lifecycle-Filter, Delete-Button (Super-Admin)
- [x] Öffentliches Profil `/model-profil/:token` (read-only Aufbau, Foto-Clear, Confirm) + „Meine Profile"
- [x] Vitest 706 / Lint 0 / Build grün; `@feature:model-registration` + `@feature:model-access` 12 passed — READY-verifiziert
- [x] UI-Review Screenshot-Loop: Harness + Captures (desktop/mobile, filled/empty), Findings F1–F4 gefixt, APPROVED

**Offen (User-Entscheidungen / Nachträge)**
- [ ] **N1 — Manager-Act-Kaskade (entschieden + umgesetzt 2026-09-19).** Manager-Transfer im Self-Service (`POST /api/model-profil/{token}/transfer-manager`, nur Manager darf abgeben) + Auto-Nachfolge (bei Manager-Löschung wird das erste Restmitglied Manager, `ModelProfileEraser::reassignManagedActs`) + V035 (`manager_customer_id` nullable + `nullOnDelete`). READY-verifiziert (1596 PHPUnit, 726 Vitest, 28 E2E).
- [x] **N2 — Sichtbarkeit inaktiver Modelle: nur Super-Admin** (User-Entscheidung 2026-09-19). Backend: `ModelManagementController::index` liefert `lifecycle_status=inactive|all` für Nicht-Super-Admins **403 (fail-closed, konsistent mit dem DSGVO-Destroy-Gate)**; Default ohne Param bleibt für alle `active`, unbekannte Werte fallen auf `active` zurück. Frontend: Status-Optionen „Inaktiv"/„Alle" nur für Super-Admins (`lifecycleFilterValues`); ein deep-gelinkter/tampered 403 wird mit Toast + Reset auf „Aktiv" abgefangen. Tests: PHPUnit `ModelProfileLifecycleExpiryTest` (Super-Admin 200 / Admin 403 / Default active / ungültig→active), Vitest `useModelRegistration.test.ts`, E2E `crm/model-lifecycle-filter.spec.ts`. Verifikation: PHPUnit **1589 passed**, Vitest **724 passed**, Lint/Build grün, E2E-Grep `model-registration|model-access` **24 passed** (`--workers=1`). Kein Commit/Push.
- [x] E2E-Ausbau Runde 2 (Slider/Schwelle, Deeplink, Foto-Management/Primary-Clear, Delete-Cancel) — erledigt 2026-09-19 (Details unten)
- [ ] Age-Proof-Positivfall (Re-Upload **ohne** vorhandenen Proof) — durch die UI nicht erzeugbar, siehe Analyse unten (produktionsseitig auf `age_proof_path`-NULL beschränkt)
- [x] Profil-Update-Mail an Einladenden (`ModelProfileUpdatedMail`, Inviter via Act→Invite, stiller Skip ohne Invite, Multi-Act-Auflösung) — umgesetzt + READY-verifiziert 2026-09-19
- [x] PDF-Export „Contact Sheet" intern/extern (Phase 1+2, extern mit Wasserzeichen) — umgesetzt + READY-verifiziert 2026-09-19 (SOLL: `features/crm/07-model-contact-sheet-export.md`)
- [ ] Client-seitiges Sanity-Limit der Personenzahl (Plan §Offene Punkte) — verifizieren
- [ ] Lokale E2E-Flakiness `database is locked` (SQLite `busy_timeout=null`) → Workaround `--workers=1`; Fix wäre `busy_timeout`/WAL (Backend)
- Hinweis (Setup): lokale `backend/.env` braucht `MODEL_REGISTRATION_THROTTLE_LIMIT=1000` (Parität zu `.env.ci`), sonst 429-Flakes im E2E-Grep-Lauf. `.env` ist gitignored.

**Future (nur TODO, nicht umsetzen)**
- [ ] Contact Sheet Phase 3 (Bulk/Ergebnisliste) + Phase 4 (signierter Extern-Link) — Plan: `~/.opencode/plan/pdf-contact-sheet-export.md`
- [ ] Kategorien-Admin-UI; Personen-Bestätigungslink; Löschkonzept für Altersnachweise (Aufbewahrungsfrist)

**Model-Zugang (User-Anforderung 2026-09-19) — umgesetzt**
- [x] `model_access_tokens` (24h-TTL, `last_used_at`, `revoked_at`); Admin create/revoke (brand-scoped); öffentlich GET/POST `/api/model-profil/{token}` + `/confirm`; `GET /api/me/models` — PHPUnit
- [x] Frontend Admin „Zugangslink kopieren" im Detail; öffentlich `/model-profil/:token`; „Meine Profile" — Vitest + Playwright `@feature:model-access`
- [x] Doku `features/crm/05-model-registration.md`; Screenshot-Capture + Review

---

## ✅ ERLEDIGT (2026-09-19) — E2E-Ausbau Runde 2 (Model-Registrierung/-Zugang)

> Auftrag: Lücken der Runde-2-Features mit **echten Nutzer-Interaktionen** schließen (semantische, gescopte Locators, Tags, kein `page.goto`-SPA-Missbrauch, keine localStorage-Injektion).
> **Verifikation:** `pnpm vitest run` **706 passed**, `pnpm lint:fix` 0, `pnpm build` grün; `npx playwright test --grep "@feature:model-registration|@feature:model-access" --workers=1` **20 passed** (Desktop + Mobile). Kein Commit/Push, keine Backend-Änderung.

**Neue/geänderte Tests**
- [x] `frontend/tests/e2e/crm/model-filters.spec.ts` (**neu**): Bereitschafts-Kategorie-Chip → `willingness_<kat>=<level>`; Stufe wechseln (Slider) → Param ändert sich; Stufe abwählen → Param weg, Kategorie bleibt; Reload → Filter bleibt; `?model=<id>`-Deeplink → Detail-Dialog offen, Schließen entfernt Param.
- [x] `frontend/tests/e2e/crm/model-access.spec.ts`: **Owner-Foto-Management** — einziges öffentliches Hauptbild auf `internal` stellen (Primary-Clear statt 422), Reload-Persistenz, danach zweites Foto öffentlich + als Hauptbild wählen; Negativfall „Feld verborgen bei vorhandenem Proof" bleibt.
- [x] `frontend/tests/e2e/crm/model-delete.spec.ts`: **Löschen-Cancel** — Danger-Zone-Löschung abbrechen → Profil bleibt (Reload-geprüft).
- [x] `frontend/tests/e2e/helpers/E2ESessionHelper.ts`: `createRegisteredModel({ photoCount })` (erstes Foto public+primary, Rest internal) + `createModelAccessLink(customerId)`; Helper-API = sanktioniertes Test-Setup (kein DB-/localStorage-Hack).
- [x] `frontend/tests/e2e/crm/model-registration.spec.ts`: **Regression-Fix (pre-existing, Zero-Pre-existing-Failures-Policy)** — `main table` war mehrdeutig (3 Treffer: 2× Model-Karten-Matrix + Einladungstabelle) → auf `model-invite-dialog` gescopt.

**Gefundener & gefixter Frontend-Bug (durch den neuen E2E-Test aufgedeckt)**
- [x] `frontend/src/logic/useModels.ts`: Die ausgewählte **Bereitschafts-Kategorie** ging verloren, solange der Level „Egal" war — `serializeModelFilters` schrieb die Kategorie nur zusammen mit einer Schwelle, während `parseModelFilters` sie ausschließlich aus `willingness_<kat>` rekonstruiert. Folge: Chip wirkte nach dem Klick inaktiv (`aria-pressed=false`) und der **Schwellen-Slider blieb dauerhaft `disabled`** → Schwelle nie setzbar. Fix: Kategorien ohne Level als Meta-Liste `willingness_category[]` persistieren (Backend ignoriert sie ohne Level) + Parser liest `[]`/skalare Variante. Regressionstest: `frontend/src/logic/__tests__/useModelRegistration.test.ts` (Test ersetzt, der das alte Verhalten festhielt).

**Bewusst ausgelassen (begründet)**
- [ ] **Age-Proof-Positivfall (Re-Upload ohne vorhandenen Proof):** nicht ohne Backend-Eingriff erzeugbar. `ProfileEditForm` blendet das Feld nur bei `profile.age_proof_required && !profile.age_proof_uploaded_at` ein; das öffentliche `POST /api/model-registration/{token}` erzwingt den Nachweis (`required`, v2), und der Owner-`POST /api/model-profil/{token}` setzt `age_proof_required=true` + die Datei (beim Bestehen bleibt `age_proof_uploaded_at` gesetzt). `age_proof_path === null` bei `age_proof_required === true` ist damit nur über Altbestände/einen direkten DB-Reset erreichbar — ein solcher Zustand existiert produktionsseitig nicht regulär.

**Umgebungs-Hinweise (kein Code-Delta)**
- Lokal scheiterte der Lauf zunächst an `429 Too Many Attempts`: das (gitignored) `backend/.env` hat **keinen** `MODEL_REGISTRATION_THROTTLE_LIMIT` → Limiter-Default 10/min. CI setzt in `backend/.env.ci` `MODEL_REGISTRATION_THROTTLE_LIMIT=1000`. Für die Verifikation wurde `.env` temporär auf 1000 gesetzt und danach **byte-identisch wiederhergestellt** (md5-geprüft).
- `pnpm test:e2e:smoke` (ohne `--workers=1`) verzeichnet die bekannte lokale SQLite-Flakiness `database is locked` (siehe Offen N3) — unabhängig von dieser Änderung.

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
- [ ] **P1-I7 (MEDIUM)** `rclone-backend-filter.txt` schließt `.env.production`/`.env.ci` nicht aus (destruktiver `sync`) — `:1-27`, `sync.sh:8`. (Anm. 2026-09-19: Remote-Ziel führt aktuell keine `.env.production`; Restrisiko bei künftiger Ablage.)
- [ ] **P1-I8 (LOW)** CI-Debug-Step fingerprintet Key-Material; getracktes `.env.encrypted`; Node-Runtime ohne Checksum; `ACCOUNTING_EMAIL`-Env-Drift; Deployment-Doku widerspricht Code (`features/infrastructure/01-deployment.md:49-55`).

### P2 — Tests / Doku — ⏳ OFFEN

- [ ] **P2-T1 (MEDIUM)** E2E localStorage-Injection (STRICT-Verstoß) — `frontend/tests/e2e/client/cart-persistence.spec.ts:31-56`.
- [ ] **P2-T2 (MEDIUM)** Kanban-E2E: `waitForTimeout` + Pixel-Drag-Retries = flaky — `tests/e2e/helpers/KanbanHelper.ts:135,143,196,231`.
- [x] **P2-T3 (VERIFY)** E2E-Timeout-Policy im Config abgebildet — `frontend/playwright.config.ts`: `timeout: 120000` (per-test) + `globalTimeout: 900000` (whole-run, Policy 7→15 min). Validiert 2026-09-12 (CI-Run 34710924406: Shard ~3 min; lokal ~7 min). Commit `38d8664`.
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
  - Anm. 2026-09-19: Model-Deploy (`3db7437`) durch User redeployed.

---

## ✅ ERLEDIGT (2026-08-31, magenta) — Prod-Bildlieferung: Header-Mismatch (X-Sendfile vs X-Accel-Redirect)

- **Fix:** `.env.production` → `PROXY_DELIVERY_HEADER=X-Accel-Redirect` + `PHOTO_STORAGE_PATH=/var/www/photos`; `deployment/docker-compose.yml` → Pass-through `PHOTO_STORAGE_PATH=${PHOTO_STORAGE_PATH}` ergänzt; Backend-Container neu deployt.
- **Verifiziert:** alle `/api/media/*`-Größen (250/400/800/1200/2000) + Original-Branch liefern echte WebP/JPEG-Bytes (10–780 KB), kein `x-sendfile`/`x-accel-redirect`-Leak mehr; `/context`, `license-terms`, alle SPA-Chunks 200.
- **Restbefund:** „Fehler persistierte" war Browser-Cache der leeren `immutable`-Antworten (max-age 1 Jahr) + alte Bundle-Stände — nach Cache-Leeren + Voll-Refresh behoben.
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

---

## 🟠 IN ARBEIT / VERIFIKATION OFFEN (2026-09-23) — Card-Testing-Schutz (SOLL: V036)

> Approved architecture: `features/security/card-testing-protection.md`. Dateiprüfung für diesen Operations/Docs-Pass: V036, Backend-/Frontend-Code sowie gezielte Testquellen sind im Working Tree vorhanden. Der gezielte Backend-Verifikationslauf ist grün; ein lokaler PHPUnit-Gesamtlauf ohne Mailpit-Gruppe wurde versucht, scheiterte aber an fehlenden lokalen Services/Extensions. Die vollständige Vitest-Suite (**758/758**), der fokussierte Frontend-Recovery-Test, Lint und Build sind grün; `@smoke` und die vollständige Playwright-Suite wurden in diesem Pass nicht ausgeführt. Offene Implementierungslücken, Rollout-Gates und Betriebsprüfungen bleiben unten ausdrücklich offen.

**Architektur & Backend**
- [x] `backend/database/migrations/V036__card_testing_defenses.php` liegt als **separate** Migration vor; User→Stripe-Customer-Mapping, Order-Identität/Generation und bounded Failure-Telemetrie sind enthalten. `deployment/docker-compose.yml` führt `php artisan db:seed --force` nur nach erfolgreichem `php artisan migrate --force` aus und bricht bei einem Fehler des Migrations-/Seed-Gates ab; tatsächlicher Deployment-Start und Seed-Durchlauf bleiben Betriebsprüfung.
- [x] Serverseitige Checkout-Entscheidung ist im `CheckoutService` als Validierung/Server-Preis → Mindestalter → Idempotenz-Replay → Checkout-Quoten → Turnstile → PI-Erstellung vorhanden. Die Route behält absichtlich `throttle:api`; der benannte `checkout`-Limiter wird erst nach positiver Immediate-Stripe-Klassifikation im Service angewendet, sodass Quote-, Invoice-, Lieferschein- und Free-Pfade nicht dediziert geblockt werden und exakte Replays vor dem PI-Budget zurückkehren.
- [x] `CheckoutEligibilityService` erzwingt `STRIPE_CHECKOUT_NEW_ACCOUNT_HOURS` (Default 24h) serverseitig gegen `users.created_at`/UTC, vor Customer-/PI-Erstellung; Quote-, Invoice- und Free-Order-Pfade werden nicht durch den Immediate-Stripe-Zweig blockiert. Passende PHPUnit-Testquellen liegen vor.
- [x] `CheckoutIdempotencyService` und `StripePaymentService` persistieren/wiederverwenden Pending-PIs mit Key/Fingerprint/Generation, deterministischem Stripe-Key `pi_{order_id}_{generation}`, 409-Konflikten und terminaler Cancel/Expiry-Erkennung; V036-Ersatz verlangt vollständige Identity-Metadata, Legacy-Orders dürfen neue Felder omitten. Ein Lost-Key-Fallback ändert niemals den ursprünglichen Audit-Key oder das Fingerprint der bestehenden Order und verwendet diese Werte für nachfolgende PI-Erstellung/Metadaten. Vor der Coupon-/Pricing-Auflösung wird für einen neuen/fehlenden Key ein begrenzter, User-/Brand-sicherer Kandidatenvergleich mit persistierten Server-Totals durchgeführt; ein Client-Amount wird nie verwendet. Beim Creator wird für bestehende Orders nochmals ausschließlich die persistierte Identity verwendet, auch wenn der Recovery-Request einen neuen Client-Key mitbringt. Der Fallback filtert nicht nach `orders.created_at`; Remote-PI-Timestamp/Identity entscheiden über Reuse/Ersatz. Ein Remote-`succeeded` ohne signierten Webhook liefert `payment_pending`/Poll-URL ohne Client-Secret. Ein Create-Response ohne Client-Secret bleibt pending/502 und wird mit derselben deterministischen Stripe-Idempotency-Key wiederholt. Legacy-3-Argument-Aufrufe behalten `pi_{order_id}` für bestehende Legacy-Orders; ein fehlender Order fail-closed vor Stripe. V036-Aufrufe verwenden `pi_{order_id}_{generation}`. Frontend-Recovery speichert nur den opaken Idempotency-Key, keinen Client-Secret/PI-Identifier.
- [x] Die Identity-Cache-Locks in `CheckoutIdempotencyService` erhalten einen Lease von `4 × Stripe-Timeout + 30s` (aktuell 350s bei 80s SDK-Default), damit Retrieve/Cancel/Customer/PI-Aufrufe eines Checkout-Vorgangs nicht durch einen 15s-Lease überlappt werden; der 5s-Wartepfad bleibt ein kontrollierter 409. Regressionstest deckt den tatsächlich an `Cache::lock()` übergebenen TTL ab.
- [x] `payment_intent.payment_failed` ist mit Signaturprüfung, Event-ID-Dedupe, PI-/Order-/User-/Key-/Fingerprint-/Generationsprüfung, saturating Failure-Count und auf 64 Zeichen bereinigten Failure-Codes implementiert; rohe Stripe-Fehler-/Client-Secrets werden nicht persistiert oder geloggt. Failure-Velocity verwendet ausschließlich den persistierten `orders.ip_address`-Snapshot; Stripe-Webhook-Ingress-IP wird nicht als Customer-IP verwendet, Siteverify-`remoteip` bleibt separat. Webhook-Callbacks mit Status `>=500` lassen den Event-Claim retryable; nur ordinary 200/ignored responses werden als `processed` markiert.
- [x] `payment_intent.succeeded` prüft PI-ID, Metadata, User-/Customer-Mapping, Generation, Betrag, Währung und `amount_received`; nur der konditionale/lock-geschützte Übergang `pending_payment → paid` wird zugelassen. Legacy-Orders dürfen einen fehlenden PI-Customer auch nach späterem User-Mapping akzeptieren; ein explizit konfligierender Customer bleibt gesperrt. Fehlende expandierte Fee-Daten blockieren den Paid-Übergang nicht: `stripe_fee_cents` bleibt `null` und der Payout-Prozentfallback greift; echte `0` bleibt ein eigener persistierter Wert. Passende Identity-/Amount-/Customer-/Dedupe-/Fee-Regressionstestquellen liegen vor.
- [x] `stripe:cancel-stale-payment-intents` ist als begrenzter hourly Scheduler mit Remote-Verify, Success-Schutz, PI-/Order-/Amount-/Currency-/V036-Identity-Check vor Cancel, vollständigem V036-Metadensatz inklusive `portal_user_id` sowie Customer-Abgleich, konditionalem Statuswechsel und Race-Guards vorhanden; dedicated Command-Testquellen liegen vor. Live-Run/Recovery-Nachweis unter realen Stripe-Daten bleibt offen.
- [x] Optionales Cloudflare-Turnstile ist serverseitig fail-closed umgesetzt: nur bei vollständiger Site-/Secret-Konfiguration aktiv, User-/IP-/Failure-Velocity-Schwellen, Action `checkout`, User-cdata, Hostname/Remote-IP, kurzer Timeout und ohne Token-/Response-Persistenz; Build-Guard und Vitest-/PHPUnit-Testquellen sind vorhanden. Produktivschlüssel und Betriebsverhalten bleiben offen.
- [x] Initial-Stripe-Response-Validierung ist fail-closed: V036-Identity inklusive `portal_user_id`/Customer, Status/Amount/Currency/ID/Client-Secret werden vor PI-Persistierung geprüft; `StripePaymentService::safePaymentIntent` gibt nur sichere Validierungsfelder zurück. Generation-Ersetzung nutzt Lock+Conditional-CAS, prüft Cancel-Response-ID/Status und der Creator selbst kann Paid/Cancelled nicht wieder öffnen. Der Missing-`created`-Pfad ist retryable ohne Cancel.
- [x] `PaymentIntentReconciliationService` bündelt strikten Success-/Fee-/Mail-Übergang für signierte Webhooks und den Stale-Command. Ein signiertes Success-Event bei `stripe_payment_intent_id = null` bindet die PI nur nach vollständiger V036-Prüfung unter Row-Lock/CAS; Command-`succeeded` reconciliert nur remote abgerufene PIs. Regressionstests decken Race, Null-Link, Fee und Mail ab.
- [x] `STRIPE_CHECKOUT_ENABLED` (Default `true`, Server-only, invalid config fail-closed) blockiert neue positive Immediate-Stripe-PI-/Customer-Erstellung mit generischem retryable `503`; Invoice-, Quote- und Free-Pfade bleiben unberührt. Der Wert ist in `.env.example`, `.env.ci` und dem Docker-Compose-Env-Passthrough verdrahtet. Ein früher 100%-Coupon-Free-Cart für einen jungen Account löst keine Age-/Quota-/Turnstile-Gates aus.
- [x] Die erste vertrauenswürdige Checkout-IP wird bereits bei `Order::create` für Invoice-, Quote-, Free- und Immediate-Stripe-Orders persistiert; Immediate-Retries verwenden ausschließlich `whereNull(ip_address)` und überschreiben vorhandene Evidenz nicht. Regressionstest deckt den Invoice-Pfad und den Failed-Create/Retry mit wechselnder IP ab.
- [ ] **Scope-Follow-up (nicht in diesem Task):** Persistente Request-Deduplizierung/-Idempotenz für nicht-Immediate-Checkout-Nebenpfade ohne PaymentIntent (Rechnung/Lieferschein, settled-free und reaktive Quote-Anfragen) bleibt bewusst außerhalb des genehmigten Immediate-Stripe-Vertrags. Der Browser sendet den opaken Key weiterhin auf allen Checkout-Requests; das Backend persistiert/wertet ihn für diese Nebenpfade nicht aus. Eine Ausweitung benötigt eine separate Feature-SOLL sowie passende PHPUnit-/Playwright-Tests.

**Frontend / Stripe.js**
- [x] Stripe.js wird über einen gemeinsamen application-weiten Loader mit begrenztem Retry und Publishable-Key geladen; `main.tsx` initialisiert ihn unabhängig vom konkreten Shopping-Routenpfad. Ein Stripe-Secret oder Client-Secret wird nicht im Frontend gespeichert.
- [x] PaymentIntent/PaymentElement und 3DS bleiben erhalten; Zahlungserfolg wird erst nach authentifiziertem Serverstatus akzeptiert, begrenzt gepollt und bei verzögertem Paid-Status mit Warenkorb/Idempotency-Key wiederherstellbar gehalten. Backend-`payment_pending` ohne Client-Secret wird im Cart als nicht-destruktiver Recovery-Zustand behandelt; der bestehende Warenkorb/Recovery-Key bleibt erhalten. Gezielte 3DS-Return/Cancel/Expiry-E2E-Szenarien sind noch nicht vollständig abgedeckt (siehe Tests).
- [x] Turnstile wird nur nach Serverflag `turnstile_required` gerendert, lädt seinen expliziten Renderer genau einmal, sendet den einmaligen Token beim nächsten Checkout-Versuch und setzt ihn nach Verbrauch/Fehler zurück; deutsche Fehler-/Retry-Zustände und fehlende Site-Key-Konfiguration sind abgedeckt.

**Betrieb, Stripe Dashboard & Privacy**
- [ ] Getrennte Test-/Live-Keys bzw. RAKs, least privilege, Webhook-Signing-Secrets und Endpoint-Subscription für Success/Failed/Dispute/Refund prüfen; **Stripe Dashboard/Radar**: Velocity-/Card-Testing-/High-Risk-Regeln, Review-Queue, False-Positive-Rollback und Alerts dokumentieren.
- [ ] **3DS-Betriebscheckliste**: SCA/frictionless/challenge/failure/timeout/mobile/return testen; Radar nicht als Ersatz für lokale Limits verwenden, Payment-Method-Settings und Testkarten verifizieren.
- [ ] Monitoring/Runbook für PI-Rate, Replays, User/IP-429, Failure-Velocity, Identity-Mismatch/Quarantäne, Cleanup, Account-Age-Rejections und Turnstile anlegen; Logs ohne PAN/CVC/Secret/Raw-Turnstile-Token.
- [ ] Datenschutzhinweise/ROPA/Prozessor-/DPA- und Cookie-Dokumentation für Stripe-Customer-/PI-IDs, IP(+Hash), Fingerprint, Failure-Codes und Turnstile finalisieren. `Privacy.tsx` enthält bereits einen technischen Teilabschnitt; Zweck/Legal-Ground, konkrete Retention, Lösch-/Anonymisierungsregeln und DPO-/Rechtsfreigabe sind noch nicht nachgewiesen.

**Tests — verpflichtender DoD**
- [x] **PHPUnit/Feature+Unit:** Gezielter Card-Testing-Lauf für V036-Schema, Customer-Mapping, Fingerprint/Key-Konflikte, User-/IP-Limiter, 24h-Mindestalter, Generation/PI-Reuse, Legacy-Webhooks, Lost-Key-Fallback (inklusive Null-PI/Stale-Ersatz und frisch ersetztem PI), Initial-Response-/`created`-Fail-Closed, Creator-/Cancel-Races, Remote-`succeeded`-Recovery inklusive Null-Link-Binding, Command-Reconciliation, Kill-Switch, Fee-Fallback, Order-IP-Velocity/IP-Evidenz, Coupon-Finite-Use und stale Cleanup ist grün: **173 Warnungen, 732 Assertions** bei fehlendem `backend/.env`. Zusätzlich wurden PHP-Syntax und `git diff --check` erfolgreich geprüft. Offen bleiben echte konkurrierende Requests und die vollständige Suite.
- [x] **PHPUnit/Webhook-/Security-Tests:** Der fokussierte Webhook-/Security-Teil ist im selben Lauf mit **173 Warnungen, 732 Assertions** grün und deckt Failure-Dedupe/Telemetrie, Success-Identity-/Amount-/Currency-/Customer-Guards, Legacy-Metadaten, konditionalen Paid-Übergang, signierte Null-Link-Reconciliation, Claim-Retry, Mail-Queue-Retry, Fee-null-vs-zero, Command-`succeeded`, Stale-Cleanup-Race/Limit/Null-PI, Kill-Switch und Turnstile-Siteverify-Zustände ab. Der separate `CheckoutServiceTest` bleibt wegen der externen Mailpit-Abhängigkeit umgebungsbedingt rot: **2 failed, 15 warnings, 88 assertions**, da `127.0.0.1:8025` nicht verfügbar ist. Der zusätzliche Gesamtlauf mit `--exclude-group=mailpit` bleibt lokal umgebungsbedingt rot (**180 failed, 1466 warnings, 7 passed, 3827 Assertions**): fehlender `APP_KEY`/lokale `.env`, Meilisearch auf `127.0.0.1:7700`, Mailpit auf `127.0.0.1:8025` sowie fehlende Bild-/Exif-Tooling-Extensions.
- [x] **Vitest-/Frontend-Verifikation:** `ClientCartView.test.tsx` deckt den `payment_pending`-Response ohne Client-Secret ab (**21/21 passed**); die vollständige Vitest-Suite ist **758/758 passed**; `pnpm lint:fix`, Lingui-Compile und `pnpm build` sind grün. Playwright bleibt separat offen.
- [ ] **Playwright E2E:** `frontend/tests/e2e/client/turnstile-checkout.spec.ts` enthält einen getaggten `@feature:card-testing`-Nutzerfluss für den risk-basierten Turnstile-Retry; die bestehende Stripe-Suite enthält Decline/Retry und `@smoke`-Success, aber noch keine vollständige `@feature:card-testing`-Abdeckung für Kontoalter-/Limit-Block, Idempotenz-Reuse ohne doppelte PI, 3DS-Return/Expiry und stale/recovery. Kein E2E-Lauf wurde in diesem Pass gestartet.
- [ ] Nach Code-Änderungen `php artisan test` (gesamte Suite) und die getaggten Playwright-Smoke-/Feature-Läufe durch einen separaten Verifikations-Subagenten grün ausführen; Ergebnis/Counts im selben Block nachtragen. Gezielter Backend-Lauf (**173 Warnungen, 732 Assertions**), vollständige Vitest-Suite (**758/758**), `pnpm lint:fix`, Lingui-Compile, `pnpm build`, PHP-Syntax und Diff-Check sind grün; bis zum separaten Full-PHPUnit-/E2E-Lauf wird ausdrücklich **keine** vollständige Green-Verifikation behauptet.

**Verifikation / Übergabe**
- [x] Operations/Docs-Pass hat die vorhandenen Migrations-, Backend-, Frontend- und Testdateien inventarisiert und die Deployment-Migrations-/Seed-Reihenfolge korrigiert; dies ersetzt weder Code-Review noch Testläufe.
- [ ] Diff-Review gegen `features/security/card-testing-protection.md` (V036 separat, keine Secrets/PII, keine ungeprüften Stripe-/Turnstile-Bypässe) durch separaten Reviewer.
- [ ] Vor Live-GO: offene Limit-/Race-Lücke schließen, Radar/3DS/Webhook/Privacy-Checkliste abhaken, Monitoring-Alarme testen, Emergency-Kill-Switch und Rollback ohne Datenverlust dokumentieren; danach Status im Task-Board auf erledigt setzen.
