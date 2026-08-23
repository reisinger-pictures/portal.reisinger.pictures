# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-19. **Nur offene TODOs + erledigte Referenz-Blöcke.** Architekturentscheidungen in `features/`.
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

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

## 📋 Archivierte Backlog-Pläne (2026-08-04)

> Nur Referenz. Umsetzung abgeschlossen oder obsolet.

- **A1** User-God-Entity → ✅ erledigt (Steps 1–7, siehe oben)
- **F3** Brand Settings UI → ✅ erledigt (siehe oben)
- **P1** Coupon photo_package → ✅ erledigt (siehe oben)
- **Stack-Konsolidierung** → ❌ OBSOLET (SQLite-Richtung)

---

## 🚫 Blockiert (Dependency-Migration 2026-08-23)

- **typescript 6→7:** Risiko durch TS7, erst nach Framework-Support. TS 7.0 ist zu frisch (kein Support durch Vite/Rolldown-Babel-Pipeline, ESLint-Typescript-Stack, React-Compiler-Preset). `frontend/package.json` bleibt bei `^6.0.3`. Nachzuziehen, sobald das Tooling TS7 deklariert.
