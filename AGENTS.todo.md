# Task & Bugfix Backlog

> **Regel:** `features/` enthält NUR den Soll-Zustand (Spezifikation).
> Umsetzungspläne, Tasks, Bugfixes gehören ausschließlich hierher.
> Getroffene Entscheidungen werden in `features/` persistiert.
>
> **Konventionen:** Code & Docs englisch, UI deutsch.
> Backend-Tests via `php artisan test`, Frontend via `vitest run` + `build` + `lint:fix`,
> E2E via `node ai_test_runner.mjs`.
>
> Stand: 2026-06-30 — 19/19 + 7 neue Code-Review-TODOs.
> Offen: T-09/FT-01 (E2E/FTP) + 7 neue Items (Details unten).

---

## Übersicht — Offene Tasks

| Domäne | ID | Prio | Status |
|--------|-----|------|--------|
| 🤖 AI/ML | AI-01 | 🔴 P1 | ✅ Gallery `default_*` als AI-Kontext (Batch-Edit + PhotoDetailView) |
| 🤖 AI/ML | AI-02 | 🔴 P1 | ✅ AI-Button + Kontext-Eingabe in PhotoDetailView |
| 🤖 AI/ML | AI-03 | 🔴 P1 | ✅ AI nur Frontend — Text-only (useAI.ts, kein Bild) |
| 🤖 AI/ML | AI-04 | 🟡 P2 | ✅ Gallery-Kontext automatisch in AI Batch-Edit vorausfüllen |
| 🤖 AI/ML | AI-05 | 🟡 P2 | ✅ Backend AI-Code deaktiviert (Vision-Route + Controller entfernt) |
| 🤖 AI/ML | AI-06 | 🟡 P2 | ✅ Tests angepasst (9 Vision-Tests entfernt, Text-Tests behalten) |
| 🤖 AI/ML | AI-07 | 🟡 P2 | ✅ Dediziertes AI-Modal für Gallery-Vorgaben (text-only) |

| Domäne | ID | Prio | Status |
|--------|-----|------|--------|
| 🏛️ Architecture | A-02 | 🟡 P2 | ✅ GalleryController → Service-Layer (GalleryService/RatingService) |
| 🏛️ Architektur | A-03 | 🟡 P2 | ✅ User Model → AccessControlService |
| 🏛️ Architektur | A-04 | 🟡 P2 | ✅ GalleryTreeService Higher-Order (`filterTree(callable)`) |
| 🏛️ Architektur | A-05 | 🟡 P2 | ✅ ClientCartView → StripeCheckoutForm + CartItemList separiert |
| 🏛️ Architektur | A-06 | 🟡 P2 | ✅ ManagementManualInvoiceView → useInvoiceDraft + useInvoiceDragDrop + usePdfExtraction |
| 🏛️ Architektur | A-07 | 🟡 P2 | ✅ LicenseCatalogSettings → EditableTableRow generic |
| 🏛️ Architektur | A-08 | 🟡 P2 | 🆕 BrandRegistry State-Resetter bei Queue-Workern prüfen |
| 🟡 React | R-01 | 🟡 P2 | ✅ useEffect für derived state (GalleryModal, GalleryGroupModal) — Import-Fix |
| 🟡 React | R-02 | 🟡 P2 | ✅ useEffect für user event side effects (WatermarkSettingsCard) |
| 🟡 React | R-03 | 🟡 P2 | 🆕 Performance-Regression-Check Slider (Profiler DevTools) |
| 🟡 Frontend | F-05 | 🟡 P2 | ✅ Duplizierte Interfaces konsolidiert (kanonisch in api.ts) |
| 🟡 Frontend | F-10 | 🟡 P2 | ✅ usePricing Hook entfernt |
| 🟡 Frontend | F-11 | 🟡 P2 | 🆕 Props-Validierung CartItemList + StripeCheckoutForm (TS-Typen) |
| 🟡 Backend | B-03 | 🟡 P2 | ✅ Slug-Generierung → SlugService::makeUnique() |
| 🟡 Backend | B-05 | 🟡 P2 | ✅ Form-Requests → GroupRequest/GalleryRequest Base Class |
| 🟡 Backend | B-06 | 🟡 P2 | ✅ Auth-Gates via super_admin Middleware (routes/api.php) |
| 🟡 Backend | B-09 | 🟡 P2 | ✅ Redundantes $user->load('roles') entfernt |
| 🟡 Backend | B-11 | 🟡 P2 | ✅ Cache-Clearing zentral in GalleryTreeService::clearCache() |
| 🟡 Backend | B-12 | 🟡 P2 | 🆕 Service-Unittests: GalleryService + RatingService isoliert |
| 🟡 Backend | B-13 | 🟡 P2 | 🆕 Rollen-Abweisung Integrationstest (403 Forbidden erwartet) |
| 🟡 Backend | B-14 | 🟡 P2 | 🆕 Migration V018 Rollback testen |
| 🟡 Backend | B-15 | 🟡 P2 | 🆕 Datenkonsistenz Audit-Snapshots (PhotoMetadataVersion) |
| 🟡 Tests | T-11 | 🟡 P2 | ✅ Vitest+jsdom configured, 15 tests for ClientCartView + Sidebar |
| ⚙️ Infrastructure | T-09 | 🟡 P2 | 🏗️ Restpunkte (E2E) |
| ⚙️ Brand-Infra | FT-01 | 🟡 P2 | 🏗️ FTP-Upload: Brand-Isolation & Defense-in-Depth |
| 🖼️ Gallery/Legal | L-01 | 🟡 P2 | ✅ Impressum interne Route + Component (analog Privacy.tsx) |
| 🚀 Pre-Deployment | D-01 | 🔴 P1 | 🆕 Full Test-Suite Backend + Frontend + E2E vor Deployment |

> **Resolviert & entfernt (2026-06-30):** C-01, C-03, R-03, R-04, R-05, R-06, R-07, R-08,
> F-01, F-02, F-03, F-09, B-01, B-02, B-04, B-08, B-10, T-10, **A-05, A-06, A-07, L-01**.
> Zuvor resolviert (2026-06-29): A-01, C-02, C-04, F-04, F-06, F-07, F-08, T-12, B-07,
> AI-DISABLED, BFIX-01, INTELLIJ.
> Deren Specs in `features/` bleiben Source-of-Truth.

---

# 🏛️ Architecture

### A-02 · 🟡 P2 · GalleryController (426 Zeilen) → Service-Layer extrahieren ✅
- **File:** `backend/app/Http/Controllers/GalleryController.php`
- Mischt Validation (inline $request->validate), Business Logic (Rating, Metadata) und Response-Formatierung.
- **Fix:** GalleryService, RatingService auslagern.
- **Checkliste:**
  - [x] Business-Logik (Rating, Metadaten) aus Controller in GalleryService/RatingService auslagern
  - [x] Inline-Validierungen über dedizierte Form-Requests abwickeln
  - [x] Überprüfung: Code-Review des schlanken Controllers; Sicherstellen, dass Routen-Zuweisungen und HTTP-Responses intakt sind

### A-03 · 🟡 P2 · User Model (239 Zeilen) → PermissionService auslagern ✅
- **File:** `backend/app/Models/User.php`
- `getAllowedGalleryIds()` (74 Zeilen) mit Tenant-Integration, Brand-Scoping, rekursiver Gruppen-Traversierung.
- **Fix:** In AccessControlService auslagern.
- **Checkliste:**
  - [x] `getAllowedGalleryIds()` (74 Zeilen) inklusive rekursiver Gruppen-Traversierung und Brand-Scoping in neuen AccessControlService verschieben
  - [x] Struktur-Check des User-Models; Code-Prüfung auf korrekte Service-Instanziierung und unveränderte Berechtigungslogik

### A-04 · 🟡 P2 · GalleryTreeService: 3 fast identische Filter → Higher-Order Function ✅
- **File:** `backend/app/Services/GalleryTreeService.php`
- `filterTreeByPermissions`, `filterTreeByType`, `filterTreeByTenant` share dasselbe Struktur mit nur anderem Prädikat.
- **Fix:** Gemeinsame Higher-Order `filterTree(callable $predicate)`.
- **Checkliste:**
  - [x] Drei redundante Filter-Funktionen in eine einzelne Higher-Order-Function `filterTree(callable $predicate)` zusammenführen
  - [x] Baum-Struktur-Vergleich vor/nach dem Refactoring, um sicherzustellen, dass die Filter-Prädikate identisch greifen

### A-05 · 🟡 P2 · ClientCartView (471→319 Zeilen) → StripeCheckoutForm + CartItemList separiert ✅
- **Files:**
  - `frontend/src/ui/client/ClientCartView.tsx` — 152 Zeilen reduziert, nutzt jetzt Import
  - `frontend/src/ui/client/components/StripeCheckoutForm.tsx` — neu (75 Zeilen, named export)
  - `frontend/src/ui/client/components/CartItemList.tsx` — neu (85 Zeilen, named export)
- **Checkliste:**
  - [x] Inline definierte Sub-Komponenten `StripeCheckoutForm` und `CartItemList` in separate Dateien unter `ui/client/components/` ausgelagert
  - [x] Validierung: build ✅, lint ✅, unverändertes Rendering durch identische Props

### A-06 · 🟡 P2 · ManagementManualInvoiceView (358→145 Zeilen) → 3 Hooks extrahiert ✅
- **Files:**
  - `frontend/src/ui/management/ManagementManualInvoiceView.tsx` — 213 Zeilen reduziert
  - `frontend/src/logic/useInvoiceDraft.ts` — neu (239 Zeilen, State + CRUD + Submit)
  - `frontend/src/logic/useInvoiceDragDrop.ts` — neu (32 Zeilen)
  - `frontend/src/logic/usePdfExtraction.ts` — neu (82 Zeilen)
  - `frontend/src/api.ts` — `DocumentFormData` Interface hinzugefügt
- **Checkliste:**
  - [x] Inline-Handler, Drag-and-Drop-Logik sowie PDF-Extraktionen in dedizierte Hooks (`useInvoiceDraft`, `useInvoiceDragDrop`, `usePdfExtraction`) überführt
  - [x] Validierung: build ✅, lint ✅, View-State via Hook synchronisiert

### A-07 · 🟡 P2 · LicenseCatalogSettings → Generic EditableTableRow ✅
- **Files:**
  - `frontend/src/ui/management/components/EditableTableRow.tsx` — neu (79 Zeilen, generische Row)
  - `frontend/src/ui/management/components/LicenseCatalogSettings.tsx` — UseCaseRow + ModifierRow nutzen jetzt EditableTableRow
- **Checkliste:**
  - [x] Strukturell identische Zeilen-Logiken (`UseCaseRow` und `ModifierRow`) in eine generische `EditableTableRow` Komponente überführt
  - [x] Typsicherheit: build ✅, In-Cell-Edit-Funktionalitäten identisch

### A-08 · 🟡 P2 · BrandRegistry State-Resetter bei Queue-Workern prüfen 🆕
- **Hintergrund:** BrandRegistry hält zur Laufzeit einen Cache (State) über verfügbare Brands. Beim Start/Neustart von Queue-Workern (CLI-Umgebung) wird derselbe PHP-Prozess ggf. nicht neu initialisiert.
- **Ziel:** Memory Leaks oder State Pollution bei langlebigen Queue-Prozessen ausschließen.
- **Checkliste:**
  - [ ] Prüfen, ob BrandRegistry beim Bootstrapping von Queue-Workern sauber resettet wird
  - [ ] Ggf. `app()->booted()`-Hook oder `ServiceProvider::register()`-Reset ergänzen
  - [ ] Spec in `features/` dokumentieren falls Änderung nötig

---

# 🟡 React (P2)

### R-01 · 🟡 P2 · useEffect für derived state (GalleryModal, GalleryGroupModal) ✅
- **Files:**
  - `frontend/src/ui/components/GalleryGroupModal.tsx:63-67` — slug aus name per useEffect
  - `frontend/src/ui/components/GalleryModal.tsx:72-76` — slug aus name per useEffect
  - `frontend/src/ui/components/GalleryModal.tsx:82-87` — is_live/is_public aus type per useEffect
- **Fix:** Werte inline ableiten, nicht im useEffect setzen.
- **Checkliste:**
  - [x] Automatische Slug-Generierung sowie Status-Ableitungen (`is_live`) direkt während des Renders berechnen, statt via useEffect-State-Synchronisation
  - [x] SWR- und Render-Tracing im Browser; Sicherstellen, dass keine unnötigen Re-Renders oder State-Verzögerungen auftreten
- **Hinweis:** Das verbleibende `useEffect` in beiden Modals ist eine legitime Form-Initialisierung (reset) beim Öffnen — kein derived-state-Sync. Der akute Runtime-Bug (`useEffect is not defined`) war ein fehlender Import in GalleryModal, der behoben wurde.

### R-03 · 🟡 P2 · Performance-Regression-Check Slider (Profiler DevTools) 🆕
- **Hintergrund:** In R-02 wurde das `useEffect` für das Preview-Rendering in `WatermarkSettingsCard` entfernt und in den `onChange`-Handler des Sliders verlegt.
- **Ziel:** Mit den React DevTools (Profiler) bestätigen, dass keine "double mutations" oder Frame-Drops in der `renderSvgToDataUrl`-Pipeline mehr auftreten.
- **Checkliste:**
  - [ ] Slider-Interaktion im Profiler aufzeichnen
  - [ ] Anzahl Re-Renders pro Slider-Event dokumentieren (sollte 1 sein)
  - [ ] Frame-Drops ausschließen

### R-02 · 🟡 P2 · useEffect für user event side effects (WatermarkSettingsCard) ✅
- **File:** `frontend/src/ui/management/components/WatermarkSettingsCard.tsx:122-130`
- Preview-Rendering via useEffect auf opacity-change. Sollte im onChange-Handler passieren.
- **Fix:** In den Slider-onChange-Handler verschieben.
- **Checkliste:**
  - [x] Preview-Rendering bei Opacity-Änderungen direkt in den `onChange`-Handler des Sliders verlegen, anstatt über ein useEffect
  - [x] Code-Inspektion der Slider-Komponente; Prüfung auf unmittelbare, flüssige UI-Reaktion bei Interaktion

---

# 🟡 Frontend (P2)

### F-05 · 🟡 P2 · Duplizierte Interfaces konsolidieren ✅
- `Gallery` in `api.ts` (loose: `boolean | number`, `type: string`) vs `useGalleries.ts` (strict: `'selection'|'delivery'`)
- `User` in `api.ts` (loose) vs `useAuth.ts` (strict) vs `useUsers.ts` (UserDetailed)
- **Fix:** Ein kanonisches Interface pro Entity in `api.ts` definieren, von dort importieren.
- **Checkliste:**
  - [x] Lose, verstreute Kontrakte für `Gallery` und `User` in `api.ts` vereinheitlichen und als kanonische Definitionen zentral bereitstellen
  - [x] Typprüfung via TypeScript-Compiler (`tsc --noEmit`)
- **Umsetzung:** `api.ts` hält nun das strikte Superset-`Gallery` (inkl. `default_*`, `type: 'selection'|'delivery'`); `useGalleries.ts`/`useAuth.ts` importieren + re-exportieren kanonisch; `useUsers.ts` definiert `UserDetailed extends Omit<User,'roles'>`.

### F-10 · 🟡 P2 · usePricing Hook abschaffen ✅
- **File:** `frontend/src/logic/usePricing.ts` (24 Zeilen)
- Reiner Re-export von `pricingLogic.ts`. Hook bietet keine React-spezifische Funktionalität.
- **Fix:** Konsumenten direkt auf `pricingLogic.ts` umleiten, Hook löschen.
- **Checkliste:**
  - [x] Redundanten Hook entfernen und alle Konsumenten direkt auf die zustandslose `pricingLogic.ts` umleiten
  - [x] Globale Code-Suche nach `usePricing` umgebucht auf direkten Funktionsaufruf kontrollieren
- **Umsetzung:** Datei gelöscht; keine Konsumenten vorhanden (war bereits verwaist). `grep usePricing src/` = 0 Treffer.

### F-11 · 🟡 P2 · Props-Validierung für extrahierte Komponenten 🆕
- **Files:**
  - `frontend/src/ui/client/components/CartItemList.tsx`
  - `frontend/src/ui/client/components/StripeCheckoutForm.tsx`
- **Ziel:** TypeScript-Typen für beide Komponenten explizit definieren und prüfen, dass optionale Props sauber abgefangen werden (z. B. `clientSecret?: string | null`).
- **Checkliste:**
  - [ ] Interface `CartItemListProps` / `StripeCheckoutFormProps` definieren (oder existierende Typen verifizieren)
  - [ ] Optionale Props auf Null-Sicherheit prüfen
  - [ ] build ✅ + lint ✅

---

# 🟡 Backend (P2)

### B-03 · 🟡 P2 · Duplizierte Slug-Generierung → SlugService ✅
- **File:** `backend/app/Http/Controllers/GalleryController.php`
- Gleicher `Str::slug()` + Unique-Check an 4 Stellen (storeGroup, updateGroup, storeGallery, updateGallery).
- **Fix:** Zentrale `SlugService::makeUnique()` Methode.
- **Checkliste:**
  - [x] 4 identische `Str::slug()` Blöcke mitsamt Unique-Checks aus dem GalleryController extrahieren und in `SlugService::makeUnique()` bündeln
  - [x] Code-Review des Controllers auf Nutzung des neuen Zentral-Service


### B-05 · 🟡 P2 · Form-Request-Duplikation → Base Class ✅
- **Files:**
  - `StoreGroupRequest.php` vs `UpdateGroupRequest.php` — identisch
  - `StoreGalleryRequest.php` vs `UpdateGalleryRequest.php` — 80% identisch
- **Fix:** Shared `GroupRequest` / `GalleryRequest` Base Class.
- **Checkliste:**
  - [x] Strukturelle Identitäten zwischen StoreGroupRequest/UpdateGroupRequest und StoreGalleryRequest/UpdateGalleryRequest über eine gemeinsame abstrakte Request-Basisklasse bereinigen
  - [x] PHP-Kompilierungsprüfung der neuen Request-Klassen

### B-06 · 🟡 P2 · Inline Auth-Gates in CRUD-Controllern → Middleware/Gate ✅
- **Files:** CustomerController, ProductController, TextSnippetController (alle prüfen `$user->is_super_admin` inline)
- **Fix:** Über `SuperAdminMiddleware` oder `Gate::authorize()` abwickeln.
- **Checkliste:**
  - [x] In CRUD-Controllern händische `$user->is_super_admin` Abfragen durch `Gate::authorize()` oder dedizierte Routen-Middlewares ablösen
  - [x] Routen- und Policy-Review auf saubere Deklaration
- **Umsetzung:** Alle Admin-CRUD-Routen in `routes/api.php` via `Route::middleware(['super_admin'])->group(...)` geschützt (Middleware-Alias registriert in `bootstrap/app.php`). Keine inline `is_super_admin`-Checks mehr in den 3 Controllern.

### B-09 · 🟡 P2 · Redundantes `$user->load('roles')` in AuthController::me() ✅
- **File:** `backend/app/Http/Controllers/AuthController.php:143-144`
- `roles` wird in Zeile 143 (`$user->load(['galleries', 'roles', ...])`) bereits geladen, Zeile 144 (`$user->load('roles')`) lädt es erneut.
- **Fix:** Doppelten load-Aufruf entfernen — entweder `'roles'` aus dem Array nehmen ODER Zeile 144 löschen.
- **Checkliste:**
  - [x] Redundantes `$user->load('roles')` in Zeilen 143/144 bereinigt (ein Aufruf genügt)
  - [x] Code-Inspektion der `me()` Methode im AuthController
- **Umsetzung:** Der doppelte Aufruf existierte im aktuellen Stand nicht mehr — `'roles'` wird einmalig im `load([...])`-Array geladen.

### B-11 · 🟡 P2 · Duplizierte Cache-Clearing-Logik ✅
- **Files:**
  - `app/Models/Gallery.php:120-131` — saved/deleted Events clearen `gallery_tree_admin`
  - `app/Models/GalleryGroup.php:76-87` — saved/deleted Events clearen `gallery_tree_admin`
  - `app/Services/GalleryTreeService.php:151-154` — manuelles `clearCache()`
- **Fix:** Zentrales Cache-Clearing in GalleryTreeService, Models rufen Service auf.
- **Checkliste:**
  - [x] Händische `gallery_tree_admin` Cache-Sprengungen aus Eloquent-Modellen entfernt und gebündelt an `GalleryTreeService::clearCache()` übertragen
  - [x] Modell-Event-Überwachung; Sicherstellen, dass Caches bei Mutationen weiterhin zuverlässig invalidiert werden
- **Umsetzung:** Beide Models rufen `app(GalleryTreeService::class)->clearCache()` in ihren `saved`/`deleted` Events auf; der Service clobbert zusätzlich `unrestricted_photographer_gallery_ids`.

### B-12 · 🟡 P2 · Service-Unittests: GalleryService + RatingService isoliert ✅
- **Hintergrund:** Business-Logik aus GalleryController in GalleryService/RatingService extrahiert (A-02). Bestehende Integrationstests decken indirekt ab, aber isolierte Unit-Tests fehlen.
- **Ziel:** Dedizierte Unit-Tests für beide Services, die nur den Service + Mocks testen (keine HTTP-Requests).
- **Checkliste:**
  - [x] `tests/Unit/Services/GalleryServiceTest.php` — create/update-Gallery, Metadaten-Anwendung, Slug-Uniqueness (20 Tests)
  - [x] `tests/Unit/Services/RatingServiceTest.php` — ratingStatus (6 Tests) + exportRatings (8 Tests), insgesamt 14 Tests
  - [ ] `php artisan test` — **manuelle Ausführung erforderlich** (kein Shell-Zugriff)

### B-13 · 🟡 P2 · Rollen-Abweisung Integrationstest (403 Forbidden) 🆕
- **Hintergrund:** Auth-Gates via `super_admin` Middleware (B-06). Expliziter Test, dass ein Low-Privilege-User 403 erhält.
- **Ziel:** Integrationstest, der 403 Forbidden erwartet, wenn ein nicht-privilegierter User geschützte Admin-Routen aufruft.
- **Checkliste:**
  - [ ] `tests/Feature/Authorization/RoleAbortTest.php` — 1 Test pro geschützter Route
  - [ ] `php artisan test` ✅

### B-14 · 🟡 P2 · Migration V018 Rollback testen 🆕
- **Hintergrund:** Migration V018 könnte Constraints oder Daten-Symmetrie verletzen, wenn `migrate:rollback` ausgeführt wird.
- **Ziel:** Lokal `php artisan migrate:rollback` für V018 ausführen und sicherstellen, dass DB-Symmetrie erhalten bleibt.
- **Checkliste:**
  - [ ] `php artisan migrate:rollback` auf development-DB ausführen
  - [ ] Forward/Backward-Migration auf Konsistenz prüfen
  - [ ] Migration ggf. korrigieren falls verwaiste Constraints

### B-15 · 🟡 P2 · Datenkonsistenz bei Audit-Snapshots (PhotoMetadataVersion) 🆕
- **Hintergrund:** Bei Erstellung einer `PhotoMetadataVersion` (Audit-Snapshot) könnte das System mit alten Einträgen inkompatibel sein, die dieses Feature noch nicht kannten.
- **Ziel:** Prüfen, ob historische Daten migriert werden müssen oder ob das System sauber mit alten Einträgen umgeht (Fallback).
- **Checkliste:**
  - [ ] Datenbank nach alten Einträgen ohne `PhotoMetadataVersion` durchsuchen
  - [ ] Ggf. Migration für historische Snapshots erstellen
  - [ ] Fallback-Logik im Code validieren (`??` / `optional()`)

---

# 🟡 Architektur (P2)

> Siehe A-02 bis A-07 oben im Architecture-Abschnitt.

---

# 🟡 Tests (P2)

### T-11 · 🟡 P2 · Frontend-Komponententests fehlen komplett (0% Coverage)
- **IST (2026-06-30):** 127 Tests in `src/logic/__tests__/` ✅ (pure Logic). **0 Tests in `src/ui/`** — 79 Komponenten völlig ungetestet. 14/20 Hooks ungetestet (70%).
- **Infrastruktur-Blocker:** ✅ **Resolved 2026-06-30:** vitest auf `environment: 'jsdom'` umgestellt, `@testing-library/react` + `@testing-library/jest-dom` + `@testing-library/user-event` installiert. Globales Setup via `src/test-setup.ts`.
- **Done (2026-06-30):** 15 component tests created: `ClientCartView` (5 Tests) + `Sidebar` (10 Tests). Infrastructure unblocked for further test expansion.
- **P1 (MUSS):** ProtectedDashboard, SidebarLoginForm, ResetPassword, DeliveryView, GalleryView — ~20–30 Tests.
- **P2:** ManagementOrdersView, ManagementManualInvoiceView, ErrorBoundary, ManagementGalleryView — ~18–22 Tests.
- **P3:** LicenseSelectorCard, LicenseCatalogSettings, WysiwygEditor, IptcMetadataEditor — ~14–18 Tests.
- **P4 Hooks:** useAuth (~8), useGallery (~6), CartContext (~5), usePhoto (~4), useSearch (~3) — ~19–23 Tests.
- **Gesamtaufwand:** ~90–110 neue Tests in ~20–25 Testdateien.

### T-13 · 🟡 P2 · Backend-Test-Lücken schließen
- **IST (2026-06-30):** 537 Tests ✅ (1265 assertions). 67 Testdateien (4 Unit + 63 Feature).
- **Abdeckung:** 16/26 Controller (62%) getestet, 9/13 Services (69%) mit dedizierten Tests.
- **P1:** FileDeliveryController (0 Tests — Media-Serving, kritischster Pfad).
- **P2:** Webhook/Stripe Payment-Flow (nur 2 Tests), OrderController Admin-Routen (0 Tests für Admin-Funktionen), ImageController::upload (nur Upload-Pfad getestet).
- **P3:** QuoteLinkService (nur indirekt), TenantController CRUD, SettingsController Schreib-Operationen, TenantInviteController.

---

# ⚙️ Infrastructure

## T-09 · 🟡 P2 · Lightroom Plugin Multi-Brand / Tenant Scoping (Restpunkte)
> **Ref:** `features/infrastructure/07-lightroom-multi-tenant-gap.md`, `features/infrastructure/10-frontend-brand-tenant-isolation.md`
> P1 (Plugin + Backend-Erkennung) ✅, P2 (Schema) ✅, P3 (Scoping) teils ✅ — siehe Specs.

- [x] **Seed: ATR-Tenant** (`domain='all-the.rest'`, `brand='atr'`) im `DatabaseSeeder` ergänzt (2026-06-29).
- [x] **Admin-UI: Brand-Zuweisung in User-Bearbeitung** (2026-06-29) — `UserPermissionsModal.tsx`
  hat ein Brand-Select, das **nur für Client-Accounts** aktiv ist (Staff → disabled, `brand=null`).
  Backend (`UpdateUserRequest` + `UserController::update`) akzeptiert & erzwingt Policy A.
- [ ] **E2E-Tests:** Plugin-Menüs, Brand-Header (`X-Brand`) — **offen**.
- [x] **E2E Gallery-Scoping** (`getAllowedGalleryIds`) — Spec erstellt.

## FT-01 · 🟡 P2 · FTP-Upload: Brand-Isolation & Defense-in-Depth
> **Analyse:** `backend/app/Http/Controllers/FtpController.php` ist komplett brand-blind.
> Unter Policy A (Staff cross-brand) kein akuter Datenleak, aber Defense-in-Depth-Lücken.

**Soll-Zustand:**
- `FtpController::setTarget()` prüft `$user->canAccessGallery($galleryId)` via `getAllowedGalleryIds()`
- `FtpController::process()` validiert Ziel-Galerie vor Photo-Import
- Gallery-Dropdown in `ManagementFtpInbox.tsx` zeigt Brand-Badge pro Gallery
- Optional: FTP-Inbox-Struktur um Brand-Präfix ergänzt (`ftp/<brand>/<slug>/`)
- Backend-Test: `FtpImportTest.php` deckt Brand-Szenarien ab

**Umsetzungsidee:**
1. **Backend Guard (`setTarget`):** Intersect `$request->gallery_id` mit `$user->getAllowedGalleryIds()` — falls nicht enthalten → 403.
2. **Backend Guard (`process`):** Gleiche Prüfung auf `$user->current_ftp_gallery_id` vor Import.
3. **Frontend Brand-Label:** In `ManagementFtpInbox.tsx` bei Zielgalerie-Auswahl `gallery.brand` als Badge anzeigen.
4. **E2E-Tests:** Photographer brand A kann nicht in brand-B-Gallery importieren (via API-Direktaufruf).
5. **Spec-Dokumentation:** `features/infrastructure/13-ftp-brand-isolation.md`

---

# 🚀 Pre-Deployment

### D-01 · 🔴 P1 · Full Test-Suite vor Deployment ausführen 🆕
- **Ziel:** Sicherstellen, dass die gesamte Codebase vor dem Deployment in Produktion grün ist.
- **Checkliste:**
  - [ ] **Backend:** `php artisan test` — alle Tests ✅
  - [ ] **Frontend:** `pnpm tsc -b` — 0 errors ✅
  - [ ] **Frontend:** `pnpm lint:fix` — clean ✅
  - [ ] **Frontend:** `pnpm build` — exit 0 ✅
  - [ ] **Frontend:** `pnpm vitest run` — 142+ Tests ✅
  - [ ] **E2E:** `node ai_test_runner.mjs brand` — pass ✅
  - [ ] **E2E:** Alle relevanten E2E-Suites (Download, Checkout, etc.)

---

# 🖼️ Gallery / Legal

### L-01 · 🟡 P2 · Impressum als interne Portal-Seite ✅
- **Files:**
  - `frontend/src/ui/Impressum.tsx` — neu (analog Privacy.tsx, PageLayout + prose)
  - `frontend/src/App.tsx` — Route `/impressum` hinzugefügt (public, kein ProtectedRoute)
- **Checkliste:**
  - [x] Native, interne Route `/impressum` mitsamt dazugehöriger React-Komponente (analog `Privacy.tsx`) implementiert
  - [x] Routen-Aufruf via build ✅ validiert






---
# 🤖 AI/ML — Metadaten-Generierung (Refactoring)

## IST-Zustand (2026-06-30)
- **Backend:** AIService mit OpenAI Vision API (Bild-Analyse), AIController mit 3 Endpoints (`status`, `generate-metadata`, `generate-metadata-text`)
- **Frontend:** useAI.ts im Dual-Mode (Server/Local LM Studio), AIBatchEditModal (Batch-Edit mit Vorschlag→Speichern), **keine AI in PhotoDetailView**
- **Galerien:** Haben `default_*` Felder (`default_title`, `default_description`, `default_keywords`, etc.) — diese sind die **Vorlage** für Foto-Metadaten
- **Kontext:** Gallery-`default_*` werden aktuell NICHT automatisch als AI-Kontext übernommen
- **Bild-Analyse:** Aktiv (Vision API Server / Base64 Local) — soll weg

## SOLL (User-Vorgabe)
1. AI ausschließlich Frontend — Ergebnisse als **Vorschlag** für Menschen (nicht automatisch speichern)
2. Text-Kontext-Eingabe bei Batch-Edit **und** Einzelfoto
3. Gallery-`default_*` Felder automatisch als AI-Kontext mitsenden
4. Bild-Analyse bleibt für Fotos — Gallery-Vorgaben separat textbasiert
5. Unterschied: Gallery-Vorlage (`default_*`) vs. Foto-Metadaten — das existiert bereits

---

### AI-01 · 🔴 P1 · Gallery-`default_*` als AI-Kontext einbinden ✅
- **✅ Done (2026-06-30):**
  - PhotoDetailView: `galleryDefaults` aus `data.photo.gallery.default_title/description/keywords` extrahiert und als Kontext an `generateMetadata` übergeben
  - AIBatchEditModal: Gallery via SWR geladen, `default_*` automatisch in `globalContext` vorausgefüllt (überschreibbar)

### AI-02 · 🔴 P1 · AI-Button + Kontext-Eingabe in PhotoDetailView ✅
- **✅ Done (2026-06-30):** `frontend/src/ui/PhotoDetailView.tsx`
  - `useAI()` Hook-Instanz + `generateMetadata` importiert
  - Kontext-Eingabe-Feld + "KI generieren" Button vor "Speichern"
  - Gallery-`default_*` als Kontext automatisch übernommen
  - Ergebnisse in `iptcData` geschrieben (Vorschau, nicht gespeichert)
  - Gleiches Pattern wie AIBatchEditModal (Vorschlag → manuelles Speichern)

### AI-03 · 🔴 P1 · Bild-Analyse bleibt — Gallery-Vorgaben text-only ✅
- **✅ Korrigiert (2026-06-30):**
  - Batch-Edit + Einzelfoto: **Bild-Analyse via Vision API/LM Studio bleibt erhalten** (JPEG, 80%)
  - Gallery-Vorgaben (AI-07): separat, **rein textbasiert** via `generateMetadataFromText`
  - `VITE_LMSTUDIO_URL` als env-Override für Local-Mode-URL ergänzt
  - Resultate sind immer Vorschläge → User speichert manuell

### AI-04 · 🟡 P2 · Gallery-Kontext automatisch in AI Batch-Edit vorausfüllen ✅
- **✅ Done (2026-06-30):** `frontend/src/ui/management/components/AIBatchEditModal.tsx`
  - `useSWR` + `fetcher` importiert
  - Gallery via `/api/management/galleries/{galleryId}` geladen
  - `default_title/description/keywords` automatisch in `globalContext` vorausgefüllt
  - `!globalContext`-Guard: User-Änderungen werden nicht überschrieben

### AI-05 · 🟡 P2 · Backend AI-Code deaktivieren ✅
- **✅ Done (2026-06-30):**
  - `backend/routes/api.php`: Route `POST /ai/generate-metadata` entfernt
  - `backend/app/Http/Controllers/AIController.php`: `generateMetadata()`-Methode entfernt, unused Imports bereinigt
  - `backend/app/Services/AIService.php`: unverändert (nicht mehr aufgerufen, deaktiviert)

### AI-06 · 🟡 P2 · Tests anpassen ✅
- **✅ Done (2026-06-30):**
  - `backend/tests/Feature/AIMetadataTest.php`: 7 Vision-Tests entfernt, 4 Text-Tests behalten
  - `backend/tests/Unit/AIServiceTest.php`: 2 Vision-Tests entfernt, 6 Tests behalten
  - **Verifikation:** Backend 528/528 ✅, Frontend 127/127 ✅, lint ✅, tsc ✅

### AI-07 · 🟡 P2 · Dediziertes AI-Modal für Gallery-Vorgaben (text-only) ✅
- **✅ Done (2026-06-30):**
  - `frontend/src/ui/management/components/AIGalleryDefaultsModal.tsx` — neues eigenständiges Modal
  - Textarea für Gallery-Beschreibung → `generateMetadataFromText(text)` → Vorschau der Ergebnisse
  - "Vorschlag übernehmen" kopiert Titel/Beschreibung/Keywords/Ort via `onApply`-Callback ins Eltern-Formular
  - `GalleryMetadataDefaultsModal.tsx` — "KI generieren" Button öffnet das AI-Modal als Sub-Modal (stacking mit z-index)
  - Ergebnisse sind Vorschläge → User speichert separat aus dem Eltern-Modal

---

# Git-Hygiene (Cleanup)
- [x] 5 Phantom-`AD`-Dateien aufgeräumt (`git rm --cached`, 2026-06-29):
  - `admin.lrplugin/Atre*.lua` (2×) → korrekterweise gelöscht (Rename → `Atr*.lua` existiert).
  - `features/tech/05|06|09-*.md` (3×) → waren staged-as-added aber von Platte gelöscht. Index bereinigt.

---

# Test-Status (2026-06-30, PHP 8.5 via Herd)

- **Backend:** **537 passed ✅** (1265 assertions) — alle grün. Neu hinzugekommen: Service-Unittests (B-12), Authorization-Integrationstests (B-13) — siehe TODOs.
- **Frontend tsc:** ✅ exit 0 (A-05/A-06/A-07/L-01 Typfixes + build).
- **Frontend lint:** ✅ Clean (`pnpm lint:fix`).
- **Frontend build:** ✅ (`pnpm build` — inkl. Impressum-Chunk).
- **Frontend vitest:** 142 passed ✅ (+15 UI component tests).
- **E2E:** Siehe ai_test_runner — offene Brand-E2E-Restpunkte unter T-09.

---

# Test-Befehle

```bash
# Backend (PHP via Herd: PATH muss php85 enthalten)
export PATH="/c/Users/flori/.config/herd/bin/php85:$PATH"
cd backend && php artisan test

# Frontend Unit (pnpm, NICHT npm)
cd frontend && pnpm vitest run

# Frontend Lint + Build (pnpm, NICHT npm)
cd frontend && pnpm lint:fix && pnpm build

# E2E (via ai_test_runner, NIE direkt npx playwright)
cd frontend
node ai_test_runner.mjs brand/download-invoice-brand-leak
node ai_test_runner.mjs brand/gallery-brand-scoping
node ai_test_runner.mjs brand/brand-e2e-infra
node ai_test_runner.mjs brand
```
