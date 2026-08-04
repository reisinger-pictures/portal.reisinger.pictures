# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-03. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Kanban-SOLL (Rollen-Matrix, DnD-Desktop-only, Status-Select): `features/b2b/11-kanban-board.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## 🟡 OFFEN — Kanban: PDF-Drop auf Projekte-Seite — E2E-Test fehlt

Funktionalität vorhanden via `useProjectPdfDrop` (vorbefüllt client_name/email/amount/package, verdrahtet in `ManagementProjectsBoard.tsx`). **Offen: E2E-Verifikation** — kein Test in `frontend/tests/e2e/admin/projects-board.spec.ts`.

---

## 🟡 OFFEN — Neues Angebot-Feature (Futures-Hinweis)

Sobald Implementierung eines neuen Angebots-Features beginnt, auf `@atlaskit/pragmatic-drag-and-drop` setzen. (File-Drop Invoice/Upload bleibt dokumentiert nativ — `features/b2b/11-kanban-board.md`.)

---

## 🟡 IN ARBEIT — Kanban-DnD auf `@atlaskit/pragmatic-drag-and-drop` migriert (Session 2026-08-04)

**Status:** Fertig + verifiziert. Code + Unit-Tests + E2E grün: `pnpm lint:fix` (0 warnings), `pnpm build` grün, Vitest 591 passed, E2E `@feature:kanban` **12/12 passed** (Desktop Chrome), E2E `@smoke` **56/56 passed**.

Umgestellt:
- Deps: `@dnd-kit/dom`/`@dnd-kit/react` entfernt, `@atlaskit/pragmatic-drag-and-drop@2.0.1` + `@atlaskit/pragmatic-drag-and-drop-hitbox@2.0.0` ergänzt (`frontend/package.json`).
- `KanbanBoard.tsx`: `DragDropProvider`/`useSortable`/`useDroppable` → `draggable()` + `dropTargetForElements()` (`/element/adapter`), `monitorForElements()` als zentraler Drop-Handler, `combine()`, `disableNativeDragPreview()`. HitBox-Drop (oberhalb/unterhalb Zielkarte) statt sortable-`index`. dnd-kit-`removeChild`-Workaround entfällt. UX-Goldstandard (aus `alexreardon/pragmatic-board`): `getIsSticky: () => true` auf allen Targets, in-flow `CardShadow` (kein Layout-Shift-Feedback-Loop), Originalkarte während Drag `hidden`, Floating-Preview via `setCustomNativeDragPreview` (originale Breite, in `I18nProvider` gewrappt, `rotate-2 opacity-95 shadow-2xl`).
- `KanbanBoard.test.tsx`: Mocks auf pragmatic-Adapter umgestellt, 14 Tests (HitBox above/below, Column-Append, self-drop, non-card, canceled).
- `KanbanHelper.ts` (E2E): `dragCard` nutzt **echte Maus-Events** (`page.mouse.move/down/up` → `Input.dispatchMouseEvent`), nicht `dragTo()` — der `dragTo()`-Shortcut berechnet den Drop-Punkt einmalig vor dem Hover und setzt die Scroll-Position zurück, sodass der in-flow Drop-Shadow das Layout live verschiebt und der Drop ins Leere fällt (v. a. bei parallelen Läufen auf dirty DB). Bewährter Ablauf aus dem dnd-kit-Helper (vorheriger Commit): `mouse.down()` am Quell-Zentrum → **danach** Ziel-Scroll-Reset → Drop-Punkt LIVE aus frischen Boxen der ersten Zielkarte auflösen → `mouse.move(steps)` → `mouse.up()`. Touch-CDP-Pfad entfernt (DnD ohnehin Desktop-only).
- `projects-board.spec.ts`: `test.describe.configure({ mode: 'serial' })` — die Drag-Tests verändern das Board live und teilen dieselbe (dirty) DB + Anfrage-Spalte; Parallel-Läufe stören die Drags (Layout-Shift / verschobene Drop-Ziele).
- `features/b2b/11-kanban-board.md` §6 DnD: Paket-Anforderung aktualisiert.

**E2E-Nachweis (grün):** `cd frontend && npx playwright test --grep @feature:kanban` (Desktop Chrome) → 12 passed. Nach jedem Code-Change zusätzlich `test:e2e:smoke` → 56 passed.

---

---

## ✅ ERLEDIGT — REVIEW-Findings — Kanban-Boards (Review 2026-08-03, Commits 4ae39e1..685a843)

Alle 4 Findings gefixt + unabhängig verifiziert (Session 2026-08-03). Gates: Backend `php artisan test` 1088 passed/0 failed, Vitest 585 passed, `lint:fix`/`build` grün, E2E @smoke 54 passed.

| # | Severity | Fix (uncommitted) | Tests |
|---|----------|-------------------|-------|
| **R1** | moderat | `payment_status` wird in `ProjectBoardController::store()/update()` gegen `PaymentStatus::cases()` validiert + persistiert (`store`: `?? OPEN`, `update`: `fill()`). Kein anderer Writer. Modal-Select-Werte passen zum Enum. | `ProjectBoardTest` (5 neue) + `ProjectModal.test.tsx` |
| **R2** | niedrig–moderat | `CleanupBoardItems` pluckt `linked_photo_job_id` nach der Projekt-Löschung und exkludiert via `whereNotIn` → referenzierte Endstatus-Jobs bleiben erhalten, Orphans weiter löschbar. | `CleanupBoardItemsTest` (3 neue) |
| **R3** | niedrig | `ManagementBoardsView` löst den effektiven Tab per `resolveBoardTab()` während des Renderings aus Permissions (kein useEffect) → Fotograf ohne `?tab` landet auf Production-Board statt "Kein Zugriff". Super-Admin-Default bleibt `projects`. | 2 Vitest + E2E `production-board.spec.ts` |
| **R4** | niedrig–moderat | Badge-Bedingung `|| isSuperAdmin` in `PhotographerProductionBoard.tsx:106`; Flag-Semantik unverändert; Doc §8.2 als Display-Layer-Convenience (keine Server-Secrecy) formuliert. | `PhotoJobBoardTest` (1), `PhotographerProductionBoard.test.tsx`, E2E |

**LOW-Hardening-Notizen — ✅ ERLEDIGT (2026-08-03, final verifiziert):**
- **R1-Edge:** `payment_status`-Rule jetzt ohne `nullable` in `store()` (`ProjectBoardController.php:71`) und `update()` (`:105`) → explizites `null` → 422 statt 500/201. Tests: `test_store_rejects_null_payment_status_with_422` + `test_update_rejects_null_payment_status_with_422`.
- **R3-Kosmetik:** `ManagementBoardsView` normalisiert die URL per render-time `<Navigate replace />` (Pattern wie `ProtectedRoute`) → effektiver Tab steht immer in der URL (Fotograf: `/boards?tab=production`, Super-Admin: `/boards?tab=projects`).

**Übrige Notes (Stand unverändert):**
- `workflow_logs` fehlt bei Modal-Statuswechseln (durch Test `test_update_accepts_status_without_workflow_log` bewusst) — Audit-Trail ist pfadabhängig.
- `move()` dupliziert Status-Listen hartkodiert, `store()/update()` leiten sie aus Enums ab → Drift-Risiko bei neuen Status. `PaymentStatus`-Enum ungenutzt (`Project.php:63`).
- Nach Konsolidierung wieder 2 undeployte Migrationen (V025 + V026) → vor Deploy zu EINER Migration zusammenfassen (Migration Policy).
- ~~V025 `down()` setzt `invoice_snapshots.tax_rate` zurück auf `NOT NULL DEFAULT 20`~~ — **Non-Issue (User-Entscheid 2026-08-03):** `down()` wird nie ausgeführt, bleibt als Regel leer.
- `index()` sortiert `status` alphabetisch (`bezahlt` < `rechnung`) ≠ Board-Reihenfolge (nur API-Kosmetik).

---

## 🔙 Backlog

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **A1** | User-God-Entity entschärfen + Role-Prüfungen konsolidieren (AccessControlService). | Graphify-Analyse 2026-08-02. Größerer Refactor, separate Session. |
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). → `features/infrastructure/21-brand-config-driven.md` §Follow-up |
| **Stack-Konsolidierung** | Ein Compose statt zwei (db 3306, mailpit 8025/1025, meili 7700 + search-test 7701); `docker-compose.test.yml` + `docker/test/` löschen; Configs (`backend/.env`, `phpunit.xml`) auf 3306/1025; nach `migrate:fresh` immer `db:seed`; `scripts/e2e-up.sh` idempotent + `.run`-Configs vereinheitlichen; Doku (README, AGENTS.md §5, e2e-test-strategy). | User-Auftrag 2026-08-02, Plan ausgearbeitet, Umsetzung offen. |


