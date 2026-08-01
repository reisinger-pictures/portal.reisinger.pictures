# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-01. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
> - Email-Template-Kompatibilität: gemeinsames Layout `emails/layouts/app.blade.php` + Button-Partial, 7 Templates auf 100% Kompatibilität, Env-Fix `FRONTEND_URL=4321` (Commit `…Email-Kompatibilität…`)
>
> Test-Regel (DoD): Backend-Änderungen → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## ✅ Session 2026-07-31 — nginx → Caddy-Direktauslieferung (Migration) — ABGESCHLOSSEN

Commit `e341216`. Caddy liefert `dist/` direkt via `import spa` + CSP/XFO (mit korrektem `sha256`-Hash fürs Inline-Script + `js.stripe.com`), nginx-Container entfernt. Alle Tasks + Doku-Fixes (K1, K2, M1/M2, I1, I2, I3) verifiziert. `SystemMiscTest` grün. Deploy-Sequenz (Server) siehe Commit-Message — außerhalb dieser Session.

## 🔙 Backlog (nicht in dieser Session)

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings für bestehende Brands, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). Nicht für near future geplant. → `features/infrastructure/21-brand-config-driven.md` §Follow-up |

## 📊 Verification (2026-08-01)

| Suite | Result |
|-------|--------|
| PHPUnit | ✅ 999 passed (2423 assertions) — vorher 5 Stripe-Checkout-Tests fehlerhaft (echter Stripe-Call mit Platzhalter-Key im lokalen `.env`). Fix: `tests/Support/MocksStripeClient.php` (Trait, `ApiRequestor::setHttpClient`), eingesetzt in `OrderCheckoutTest` + `CheckoutCouponRevalidationTest`; Debug-`dump()` aus `OrderCheckoutTest` entfernt. |
| Vitest | ✅ 476 passed (47 files) |
| ESLint | ✅ `pnpm lint:fix --max-warnings 0` |
| Build (tsc+vite) | ✅ |
| Playwright `@smoke` | ⚠️ 36–37/42 grün. Rests: 2× Stripe-E2E (benötigt echte Stripe-Test-Keys im lokalen Backend-`.env` — Platzhalter `sk_test_<your…>`), 2× Mailpit-Password-Reset-Token-Timing (Flaky unter 8-Worker-Parallelität), 1× Structure-Tree (Flaky, isoliert grün). Lokale Auth-Throttle für E2E auf `AUTH_THROTTLE_LIMIT=1000` angehoben (`.env`, untracked). |
