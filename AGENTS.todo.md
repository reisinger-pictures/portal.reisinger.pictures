# Task Board — Portal Reisinger Pictures

> Stand: 2026-07-31. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
> - Email-Template-Kompatibilität: gemeinsames Layout `emails/layouts/app.blade.php` + Button-Partial, 7 Templates auf 100% Kompatibilität, Env-Fix `FRONTEND_URL=4321` (Commit `…Email-Kompatibilität…`)
>
> Test-Regel (DoD): Backend-Änderungen → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## 🔙 Backlog (nicht in dieser Session)

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings für bestehende Brands, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). Nicht für near future geplant. → `features/infrastructure/21-brand-config-driven.md` §Follow-up |

## 📊 Verification (2026-07-18)

| Suite | Result |
|-------|--------|
| PHPUnit | ✅ (unchanged — nur Lua-Plugin-Änderungen) |
| Vitest | ✅ (unchanged — nur Lua-Plugin-Änderungen) |
| ESLint | ✅ (unchanged) |
| Build (tsc+vite) | ✅ (unchanged) |
| Playwright `@smoke` | ✅ (unchanged) |
| Plugin Smoke | ✅ manuell durchgeführt |
