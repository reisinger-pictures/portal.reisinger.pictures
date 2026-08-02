# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-02. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
> - Email-Template-Kompatibilität: gemeinsames Layout `emails/layouts/app.blade.php` + Button-Partial, 7 Templates auf 100% Kompatibilität, Env-Fix `FRONTEND_URL=4321` (Commit `…Email-Kompatibilität…`)
>
> Test-Regel (DoD): Backend-Änderungen → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## ✅ PRIO 1 — Phase 2: Board-Hygiene + Lightroom-Kataloge pro Fotograf + Projekt→Bildbearbeitung-Übernahme — ABGESCHLOSSEN

> **Freigabe & Umsetzung 2026-08-02** (User-Feedback). Stats-Modul verworfen → Übersicht/Hygiene.
> **Ausführungsmodus:** Build-Agent dokumentiert; Implementer (Backend ∥ Frontend); separater Verifikations-Agent (NIE der Implementer). Alle Tasks inkl. Tests verifiziert.

**Geliefert:**
- **Abbruch-Status:** `PhotoJobStatus::ABGEBROCHEN='abgebrochen'`, `ProjectStatus::STORNIERT='storniert'` (V025 in-place, nicht deployed); `move()`-Validation erweitert; Statuswechsel → `workflow_logs`.
- **Auto-Cleanup:** `app:cleanup-board-items` (Grace `BOARD_CLEANUP_GRACE_DAYS`=30), löscht `bezahlt`/`storniert`-Projects + `veroeffentlicht`/`abgebrochen`-PhotoJobs (updated_at < grace), `dailyAt('06:00')` in `routes/console.php`.
- **Lightroom-Kataloge pro Fotograf (Settings-Pattern-Entscheidung):** `lightroom_catalogs(user_id, name, position)`, unique `(user_id,name)`, self-only CRUD (`ownedBy`-Scope); Verwaltung in „Mein Profil" (Card nur Fotograf/Super-Admin); `PhotoJobModal`-Select = eigene Kataloge; Settings-Card entfernt. **Privacy-Regel:** `lightroom_catalog_is_mine` server-seitig in allen Serialisierungswegen (index/store/update/move); Rohwert bleibt im Payload (Roundtrip), UI zeigt fremde Katalognamen nicht.
- **Handoff:** `POST /api/management/projects/{id}/handoff` (nur super_admin) → erzeugt verlinkten PhotoJob (`linked_photo_job_id`), 422 bei Doppel-Handoff.
- **SOLL-Doku:** `features/infrastructure/26-per-user-settings.md` (neu) + `features/b2b/11-kanban-board.md` + `features/infrastructure/README.md`-Index.

**Verifikation (unabhängiger Agent):** Backend `php artisan test` → 1063 passed · Frontend `pnpm test:run` → 553 passed, `pnpm lint` 0 warnings, `pnpm build` ok · E2E `@feature:kanban` 16/16, `@smoke` 54/54 (3× stabil) · Policy-Review sauber (kein `any`/`@ts-ignore`, Tailwind-/.style-, Zod-, required-, Tag-Policy).

**Flaky-Fix (pre-existing):** `smtp-test-mail.spec.ts` globales `deleteAllMessages()` → `MailpitHelper::deleteMessagesFor(email)` via `DELETE /api/v1/search?query=to:"<email>"` (Mailpit-DELETE-`messages` ist NICHT scoped, nur `/search`). `@smoke` dadurch stabil.

**Hinweis Deployment:** V025 ist **nicht deployed** und wurde in-place erweitert (Konsolidierung aller Nicht-Prod-Migrationen ≥V025 vor Deploy). Lokale Dev-DB `portal_dev_db` wurde nicht-destruktiv manuell synchronisiert (Enums + `lightroom_catalogs`-Tabelle) — vor Deploy in Prod greift `php artisan migrate`.

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

---

## 🔴 PRIO 1 — Iteration (Feedback 2026-08-02): Kanban UX + CRM/PDF-Integration

> SOLL-Doku: `features/b2b/11-kanban-board.md`. Freigegeben 2026-08-02 via User-Feedback.

1. **Layout (Desktop):** Board darf NICHT einzeilig mit horizontalem Scroll sein. Hybrid responsiv: Desktop → mehrere Spalten via Grid (dynamische Spaltenanzahl, aber KEIN `overflow-x`), Mobile → Zeilenumbruch. Erstellen-Button prominent hervorheben (nicht versteckt).
2. **Super-Admin-Sicht:** Mit Super-Admin-Login müssen BEIDE Boards sichtbar sein (Projekte **und** Bildbearbeitung). `usePermissions`-Login: Super-Admin soll auch `canAccessProductionBoard` sehen (aktuell nur `isPhotographer`). E2E-verifiziert.
3. **CRM/Meilisearch-Autofill** beim "Neues Projekt anlegen": Client-Name-Autovervollständigung via Meilisearch/CRM; bei Treffer Name/Email/Telefon übernehmen. Kein Auto-Erstellen von Customer (nur Autofill).
4. **PDF-Drop auf Projekte-Seite:** Exakt wie beim "Manuellen Angebot" (nutzt bestehende PDF-Erkennung): PDF-Datei auf Projekte-Seite ziehen → Felder werden per PDF-Analyse vorbefüllt (client_name/email/amount/package). Deterministisch: bei Analyse Erfolg → neues Projekt vorschlagen/erstellen; sonst unbestimmt → manuell-Vorschlagsfluss.
5. **E2E:** Da Dev-Server läuft (frontend:4321, backend im via vite proxy https://portal.test): `npx playwright test --grep @smoke` + `@feature:kanban` ausführen.

---

## 🔴 PRIO 1 — Iteration 2 (Feedback 2026-08-02): Route+Tabs, DnD-Scope, Create-CTA
> SOLL-Doku: `features/b2b/11-kanban-board.md` (Abschnitt Frontend/Routing aktualisieren).

1. **Routen-Konsolidierung für Super-Admin:** Bildbearbeitung-Status **und** Projekt-Status auf EINE Route mit Query-Parameter bringen (z.B. `/boards?tab=projects|production`). Query steuert Tabs → schneller Wechsel. **Tabs-Navigation nur für Super-Admin anzeigen.** Andere Rollen behalten ihren einzelnen Board-Zugang ohne Tabs. Tabs-Container nur sichtbar, wenn der Nutzer Tabs hat (=Super-Admin).
2. **DnD NUR für Super-Admin:** Karten-Verschieben via Drag&Drop exklusiv super_admin. Für admin/photographer DnD aus; Statuswechsel dann via Edit-Modal (Select). (Agreed: Board-Zugriff Admin→Projekte, Photographer→Produktion bleibt, aber ohne Drag.)
3. **Create-CTA umbauen:** Der globale "Neues Projekt/Neuer Auftrag"-Button (außerhalb der Karten, im Board-Header) ergeben wenig Sinn (fehlender Status-Kontext). Diesen extra CTA ENTFERNEN; stattdessen den **Per-Spalten-"-"-Button im Card-Header deutlich hervorheben** (primär/flex, nicht `btn-xs btn-ghost` versteckt).
4. **Verifikation:** Frontend lint/build/tests, E2E @smoke + @feature:kanban (Server läuft), vision auf Screenshots (beide Boards, Tabs-Container).

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

## 📐 Graphify-Architektur-Analyse (Session 2026-08-02) — Findings (interaktiv verifiziert)

> Quelle: Monorepo-Graph (4114 Nodes) — God-Nodes User, Gallery, UserRole + Phantom-Edges + DevOps-Fakten. Verifikation über 3 interaktive Runden.

### Findings (alle nur dokumentiert, keine Tasks)
1. **User-God-Entity + UserRole-Fragmentierung** (zusammenhängend): `User` ist dünner Auth-Actor (id + Rollen), aber trägt Domainlogik (`hasPurchasedPhoto`, Watermark/Tier, `ftp_slug`). Gleichzeitig sind Role-Checks über 3 Mechanismen verstreut. **→ Backlog:** Konsolidierung User (Domainlogik in Services) + Role-Prüfungen (AccessControlService) als EIN gebündelter Task. `canAccessGallery()` in Richtung Security auflösen (nicht Gallery selbst).
2. **FTP-Settings:** Lokaler Watch-Ordner mit 2 Spalten am `users`-Model (`ftp_slug`, `current_ftp_gallery_id`). Option A bestätigt: Spalten bleiben, nur Logik dokumentiert. **Kein Task.**
3. **Gallery:** Legitimer Domain-Aggregate; beobachten. `canAccessGallery()` wird Teil der Security-Konsolidierung (Punkt 1).
4. **Phantom-Inferred-Edges:** Großzahlen sind Namenskollisionen; aktueller Artifact sauber.
5. **DevOps-Fakten:** robots.txt bewusst asymmetrisch (Frontend blockt /api/, Backend permissiv); compose local==test, prod gehärtet (Startup-Guard).

### Backlog-Einträge — siehe Tabelle unten

---

## ✅ Session 2026-07-31 — nginx & Caddy-Direktauslieferung (Migration) — ABGESCHLOSSEN

Commit `e341216`. Caddy liefert `dist/` direkt via `import spa` + CSP/XFO (mit korrektem `sha256`-Hash fürs Inline-Script + `js.stripe.com`), nginx-Container entfernt. Alle Tasks + Doku-Fixes (K1, K2, M1/M2, I1, I2, I3) verifiziert. `SystemMiscTest` grün. Deploy-Sequenz (Server) siehe Commit-Message — außerhalb dieser Session.

## ✅ Session 2026-08-02 — E2E @smoke: Stripe-Env + Mailpit-Flake behoben

3 vorbestehende @smoke-Failures analysiert (Stripe-Checkout, brand-isolation, magic-link) und behoben:

1. **Stripe Positive Flow (Desktop+Mobile):** `backend/.env` enthielt Platzhalter-Keys (`STRIPE_KEY/STRIPE_SECRET=...<your_stripe_...>`), `frontend/.env.local` nutzte `pk_test_51TJyXn1...` (Konto `51TJyXn1`) — kein passender `sk_test`. **Fix:** `backend/.env` + `frontend/.env.local` auf die validierten CLI-Test-Keys des Stripe-Kontos `51TJyXb0` (= Live-Konto, `stripe config --list`) umgestellt; Vite-Server neu gestartet. Checkout erstellt nun echten PaymentIntent. Verifikation: CLI-`sk_test` gegen `api.stripe.com/v1/balance` (200) getestet. KEINE committed Secrets (nur ungetrackte lokale `.env`-Dateien). Der laufende `stripe listen`-Tunnel + `stripe_secret.txt` passt zum selben Konto.
2. **brand-isolation + magic-link (Mailpit-Token-Flake):** `MailpitHelper.getMessageForEmail` fragte `/messages?query=<email>` ohne `limit` — Mailpit paginiert (Default ~50); unter 8 parallelen Workern füllt sich die Mailbox schnell, die Ziel-Mail rutschte aus der ersten Seite → `Token: null` → `resetUserPassword` fehlgeschlagen. **Fix:** `frontend/tests/e2e/helpers/MailpitHelper.ts:13-36` — präzise `to:"<email>"`-Query + `limit=1000` + Empfänger-Exact-Match + Polling 20×500ms.

**Verifikation:** `npx playwright test --grep @smoke` → **54/54 passed** (1.2 min). Route-Guard (Mobile) zusätzlich 2× wiederholt: 20/20 grün. `tsc --noEmit` 0 Fehler.

## 🔙 Backlog (nicht in dieser Session)

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings für bestehende Brands, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). Nicht für near future geplant. → `features/infrastructure/21-brand-config-driven.md` §Follow-up |
| **A1** | User-God-Entity entschärfen + Role-Prüfungen konsolidieren (AccessControlService). | Graphify-Analyse 2026-08-02, verifiziert. User trägt Domainlogik (hasPurchasedPhoto, Watermark/Tier, ftp_slug) → extrahieren; Role-Checks über 3 Mechanismen + Frontend-Dup → auf EINE Implementierung. canAccessGallery() in Richtung Security auflösen. Größerer Refactor, separate Session. |

## 📊 Verification (2026-08-01)

| Suite | Result |
|-------|--------|
| PHPUnit | ✅ 999 passed (2423 assertions) — vorher 5 Stripe-Checkout-Tests fehlerhaft (echter Stripe-Call mit Platzhalter-Key im lokalen `.env`). Fix: `tests/Support/MocksStripeClient.php` (Trait, `ApiRequestor::setHttpClient`), eingesetzt in `OrderCheckoutTest` + `CheckoutCouponRevalidationTest`; Debug-`dump()` aus `OrderCheckoutTest` entfernt. |
| Vitest | ✅ 492 passed (50 files) |
| ESLint | ✅ `pnpm lint:fix --max-warnings 0` |
| Build (tsc+vite) | ✅ |
| Playwright `@smoke` | ✅ **54/54 passed (2026-08-02)** — Stripe-Env (Test-Keys Konto `51TJyXb0`) + Mailpit-Pagination-Fix; Server lief (frontend:4321, proxy→portal.test) |

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
