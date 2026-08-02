# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-01. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
> - Email-Template-Kompatibilität: gemeinsames Layout `emails/layouts/app.blade.php` + Button-Partial, 7 Templates auf 100% Kompatibilität, Env-Fix `FRONTEND_URL=4321` (Commit `…Email-Kompatibilität…`)
>
> Test-Regel (DoD): Backend-Änderungen → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## 🔴 PRIO 1 — Kanban-Board «Projekte» + «Bildbearbeitung» (NEU, PHASE 1)

> **Ziel-SOLL-Doku:** noch zu erstellen → `features/b2b/11-kanban-board.md`.
> **Stack:** React 19 + DaisyUI + React Router, Backend Laravel. DnD: **`@dnd-kit/react`** (v0.5.0, installiert) — bewusst NUR dieses Paket, KEIN `@dnd-kit/core` + `@dnd-kit/sortable`.

### Rollen- & Sichtbarkeits-Matrix (freigegeben 2026-08-02)

| Board | Zugriff (Rollenzuordnung) | Sichtbarkeit |
|-------|--------------------------|--------------|
| **Projekte** (kaufmännisch) | `super_admin`, `admin` | Super-Admin → alle Items; Admin → nur eigene |
| **Bildbearbeitung** (Produktion) | `super_admin`, `photographer` | Super-Admin → alle Items; Fotograf → nur eigene |

**Owner-Modell:** `created_by` (beim Anlegen = Ersteller) + optionales `assignee_id` (Reassign/Übergabe).
**Sichtbarkeits-Query:** `super_admin → getAll(); sonst → where(created_by = me OR assignee = me)`.
**Board-Zugriffs-Gate:** Projekte-Board nur admin/super_admin; Produktions-Board nur photographer/super_admin.

### Erweiterung der DnD-Logik (eigener Task, siehe Migration unten)
Die bestehende DnD-Landschaft nutzt native `dataTransfer.files`-Drops (PDF/Image-Upload) — diese bleiben OS-Level und sind nicht das Terrain von `@dnd-kit/react` (Element-/Card-Drag). Der Kanban-Card-Drag nutzt dagegen `@dnd-kit/react`. File-Drop-Zonen ggf. bewusst als native Ausnahme belassen (siehe Migrations-TODO).

### Datenmodell (NEUE, separate Migrationen — V033 ff.)
- `projects` (kaufmännisches Board): id UUID, `brand`, `owner_id`, `assignee_id?`, `client_name`, `email`, `phone`, `package` / `price_cents`, `payment_status` (`open|partly_paid|paid`), `status` (Enum → 5 Spalten), `position`, `linked_photo_job_id?`, Timestamps
- `photo_jobs` (Produktions-Board): id UUID, `brand`, `owner_id`, `assignee_id?`, `title`, `lightroom_catalog`, `total_count`, `selected_count`, `target_gallery_id?`, `is_private`, `status` (Enum → 5), `position`, Timestamps
- `workflow_logs` (polymorph, Phase-2-Stats): id UUID, `item_type` (`project|photo_job`), `item_id`, `from_status`, `to_status`, `user_id`, `created_at`

Status-Enums (`ProjectStatus`, `PhotoJobStatus`) als PHP-Enums → Spalten-Layout stabil.

### Backend
- 2 Modelle + 2 Enums + `WorkflowLog`-Model.
- `ProjectBoardController` + `PhotoJobBoardController` (Resource unter `/api/board/…`), middleware `management`.
- Owner/Assignee-Scoping-Service + Board-Rollen-Gates (analog `canAccessGallery`), `forCurrentBrand()`-Scope.
- PHPUnit `tests/Feature/Board/`: CRUD, Statuswechsel→Log, Owner-Scoping, Rollen-Gate, Brand-Isolation, Reassign.

### Frontend
- `ui/management/ManagementProjectsBoard.tsx` (+Route `/projects`) + `ui/photographer/PhotographerProductionBoard.tsx` (+Route `/production`).
- Einbindung `ManagementDashboard`-Weiche + `Sidebar` (neues Menü-Modul).
- `@dnd-kit/react` für Card-Drag zwischen Spalten (mobile/touch-faehig), optimistisches Update.
- Formulare: `react-hook-form` + `@hookform/resolvers/zod`, `required`-Attribute, Pflichtmarkierung via `index.css`-Mechanik (kein `(optional)`).
- Statuswechsel via Board-Update-POST + `workflow_logs`-Eintrag + Toast.
- Vitest (Logic/Hook, DnD-Handler) + Playwright E2E mit Tags (`@smoke`, `@feature:board`, `@mobile`).

### Migrations-TODO für DnD (auf `@dnd-kit/react`)
- [ ] **MVP:** Kanban-Card-Drag = `@dnd-kit/react` (einheitliche DnD-Orchestrierung).
- [ ] **Migriere `useInvoiceDragDrop` + `InvoiceDragDropZone`** (`src/logic/useInvoiceDragDrop.ts`, `src/ui/management/ManagementManualInvoiceView.tsx`): von `onDragOver/onDrop/dataTransfer.files` auf `@dnd-kit/react` File-Drop-Flow umziehen, wo sinnvoll — sonst als dokumentierte native Ausnahme belassen. Achtung: `@dnd-kit` optimiert für Element-Drag, Datei-Drops laufen nativer `dataTransfer`; ggf. als Drop-Zone bewusst belassen.
- [ ] **New Angebot-Feature**: sobald Implementierung beginnt, auf `@dnd-kit/react` setzen (nicht `@dnd-kit/core`+`sortable`).
- [ ] `UploadDropzone` (`src/ui/management/components/UploadDropzone.tsx`): prüfen, ob eine gemeinsame Drop-Basis mit Invoice-Drop erstellt werden soll → s.o. gleiche Entscheidung.

### API-Vertrag (Freigabe SOLL — Backend & Frontend MUST align)
Alle Routen in `['auth:api','management']`-Gruppe, Präfix `/api/management`. Item-JSON (beide Boards) liefert: `id, status, position, owner:{id,name}, assignee:{id,name}|null, created_at` + board-spezifische Felder.

- **Projekte** (`/api/management/projects`, nur admin/super_admin):
  - `GET` → `{projects: [...]}` · `POST` (owner = current user, brand via BrandRegistry, status=Anfrage, position=end) → 201 · `PUT /{id}` · `PATCH /{id}/move` (body `status`, `position`) → schreibt `workflow_logs` + Toast-Verhalen · `DELETE /{id}`
- **Produktion** (`/api/management/photo-jobs`, super_admin/photographer):
  - `GET` → `{photo_jobs: [...]}` · `POST` · `PUT /{id}` · `PATCH /{id}/move` · `DELETE /{id}`
- **Wichtig:** `ManagementMiddleware` um Präfix `api/management/photo-jobs*` für Photographer erweitern.
- **Sichtbarkeit Backend:** `super_admin → alle; sonst → where(owner_id = me OR assignee_id = me)`. Board-Gate: projects nur admin/super_admin, photo-jobs nur photographer(→super_admin via super_admin-middleware-drüber oder Gate).

- **Enums:** `app/Enums/ProjectStatus.php` = `{anfrage, angebot, beauftragt, rechnung, bezahlt}`; `app/Enums/PhotoJobStatus.php` = `{shooting, culling, bearbeitung, export, veroeffentlicht}` (Keys engl., Labels/UI deutsch).
- **Migration:** STRICTLY befolgt §3 Policy: Als Build-Agent dokumentiere ich hier — Umsetzung fragt NICHT erneut, es wurde 2026-08-02 freigegeben: **NEUE separate Migration(en)**, Nummern V033 ff. (users-FK `owner_id`/`assignee_id` via `foreignUuid`).

### DnD-Vertrag (@dnd-kit/react, NUR Paket `@dnd-kit/react`)
- Board-Views nutzen `DragDropProvider` + `useDroppable` (Spalten) + `useDraggable`/`useSortable` (Karten) + `DragOverlay`. Bei Status-/Positionswechsel `PATCH /position`.
- Kein `@dnd-kit/core`/`@dnd-kit/sortable`. File-Drop (Invoice/Upload) bleibt native `dataTransfer`.

### DoD
Backend `php artisan test` grün; Frontend `pnpm lint:fix --max-warnings 0` + `pnpm build` grün; Vitest grün; Playwright `@smoke` grün. Kein `any`/`@ts-ignore`/`eslint-disable`/Tailwind-Dynamic-Class/`.style`/localStorage-Injektion.

---

## ✅ Session 2026-07-31 — nginx & Caddy-Direktauslieferung (Migration) — ABGESCHLOSSEN

Commit `e341216`. Caddy liefert `dist/` direkt via `import spa` + CSP/XFO (mit korrektem `sha256`-Hash fürs Inline-Script + `js.stripe.com`), nginx-Container entfernt. Alle Tasks + Doku-Fixes (K1, K2, M1/M2, I1, I2, I3) verifiziert. `SystemMiscTest` grün. Deploy-Sequenz (Server) siehe Commit-Message — außerhalb dieser Session.

## 🔙 Backlog (nicht in dieser Session)

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings für bestehende Brands, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). Nicht für near future geplant. → `features/infrastructure/21-brand-config-driven.md` §Follow-up |

## 📊 Verification (2026-08-01)

| Suite | Result |
|-------|--------|
| PHPUnit | ✅ 999 passed (2423 assertions) — vorher 5 Stripe-Checkout-Tests fehlerhaft (echter Stripe-Call mit Platzhalter-Key im lokalen `.env`). Fix: `tests/Support/MocksStripeClient.php` (Trait, `ApiRequestor::setHttpClient`), eingesetzt in `OrderCheckoutTest` + `CheckoutCouponRevalidationTest`; Debug-`dump()` aus `OrderCheckoutTest` entfernt. |
| Vitest | ✅ 492 passed (50 files) |
| ESLint | ✅ `pnpm lint:fix --max-warnings 0` |
| Build (tsc+vite) | ✅ |
| Playwright `@smoke` | ⚠️ nicht ausführbar (Server offline: `ERR_CONNECTION_REFUSED localhost:4321`) — kein Code-induzierter Fehler |

## ✅ Session 2026-08-01 — Tracking-Integration (stats.reisinger.pictures) — ABGESCHLOSSEN

Tracker-Skript (`x7k2p.js`) war bereits in `index.html` eingebunden, aber ohne Custom-Events. Implementiert:

- `src/logic/tracking.ts` — typisierter Wrapper um `window.trackEvent` (Guard, Event-Name-Konstanten)
- `src/logic/usePageViewTracking.ts` + `PageViewTracker` in `App.tsx` — virtuelle Pageviews bei SPA-Route-Wechsel
- Foto-Interaktionen: `photo_view` (Lightbox-Slide via `usePhotoSwipe`, PhotoDetailView), `photo_swipe_open` (Lightbox-Open), `photo_download` (Zip-Download in `DeliveryView`, Einzel-Download + Admin-Download in `LicenseSelectorCard`)
- Warenkorb-Funnel: `add_to_cart` (LicenseSelectorCard + VolumeLicensingCard), `remove_from_cart` (`CartProvider`), `checkout_started`/`checkout_succeeded`/`checkout_failed` (`ClientCartView`)
- Tests: `tracking.test.ts`, `usePageViewTracking.test.tsx`

**Follow-up (gleiche Session):**
- Rating-Event `photo_rated` (photo_id, rating, has_comment) zentral in `useGallery.ratePhoto()` ergänzt — deckt Grid-Rating (`GridPhotoActions`), Lightbox-Bridge (`DaisyUIRatingBridge`) und Keyboard-Rating (`SelectionView`) ab.
- Integration/Component-Tests ergänzt: `useGallery.test.ts` (photo_rated ×2), `LicenseSelectorCard.test.tsx` (add_to_cart, photo_download via Admin-Download), `usePhotoSwipe.test.tsx` (photo_swipe_open, photo_view ×2, via gemocktem `PhotoSwipeLightbox` mit `vi.hoisted`).

**Offen/Note:** E2E `@smoke` lokal nicht lauffähig, weil Frontend-Dev-/Backend-Server nicht laufen. Events sind nur bei Nutzer-Interaktion sinnvoll messbar — vor Deployment `test:e2e` auf laufendem Stack ausführen.
