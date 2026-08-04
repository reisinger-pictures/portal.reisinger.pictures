# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-04. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
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

## 🔙 Backlog

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **A1** | User-God-Entity entschärfen + Role-Prüfungen konsolidieren (AccessControlService). | Graphify-Analyse 2026-08-02. Größerer Refactor, separate Session. |
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). → `features/infrastructure/21-brand-config-driven.md` §Follow-up |
| **Stack-Konsolidierung** | Ein Compose statt zwei (db 3306, mailpit 8025/1025, meili 7700 + search-test 7701); `docker-compose.test.yml` + `docker/test/` löschen; Configs (`backend/.env`, `phpunit.xml`) auf 3306/1025; nach `migrate:fresh` immer `db:seed`; `scripts/e2e-up.sh` idempotent + `.run`-Configs vereinheitlichen; Doku (README, AGENTS.md §5, e2e-test-strategy). | User-Auftrag 2026-08-02, Plan ausgearbeitet, Umsetzung offen. |
