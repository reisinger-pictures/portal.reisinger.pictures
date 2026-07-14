# Task Board — Portal Reisinger Pictures

> Stand: 2026-07-14. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
>
> Test-Regel (DoD): Backend-Änderungen → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## ✅ Erledigt (2026-07-14)

| Task | Was | Tests |
|------|-----|-------|
| **F1** | Flaky Stripe Checkout E2E gefixt — `StripeHelper.ts` extrahiert (dedupliziert `stripe-checkout.spec.ts` + `quote-checkout.spec.ts`), Timeout 15s→30s, `FormHelper.fillStripeForm` delegiert an `StripeHelper::fillStripeForm`. | 40/40 `@smoke` Playwright ✅ |
| **F2** | Per-Gallery Licensing Override — V032 Migration (`licensing_mode` auf `galleries`), `effective_licensing_mode` Accessor, `CheckoutService::groupItemsByLicensingMode()` + `calculateMultiStrategyCart()` (Mixed-Cart), `SettingsController` mit `gallery_id` Query-Param, Frontend `useLicensingMode(galleryId?)`, Gallery-Form mit Dropdown. | 3× PHPUnit (`PricingStrategyResolutionTest`, `MixedCartPricingTest`), 5× Vitest (`useLicensingMode.test.ts`) |
| **F4** | Theme-Override pro Brand — PDF-Farben bereits dynamisch via `BrandConfig`, daisyUI-Themes umbenannt (`rp-light`/`rp-dark`), `primary_color`/`secondary_color` ins Frontend propagiert (`useBrand()`). | PHPUnit `ContractPdfServiceTest`, Vitest `useBrand.test.ts` |
| **F5** | `features/`-Doku gepflegt — `21-brand-config-driven.md`, `17-pricing-strategy-pattern.md` (Resolution + Planned: F2), `16-srp-volume-pricing.md` (Historical), `02-licensing-and-downloads.md` (aktuell). | Keine (Doku) |

## 🔙 Backlog (nicht in dieser Session)

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings für bestehende Brands, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). Nicht für near future geplant. → `features/infrastructure/21-brand-config-driven.md` §Follow-up |

## 📊 Verification (2026-07-14)

| Suite | Result |
|-------|--------|
| PHPUnit | ✅ 986 passed (2301 assertions), 17.1s parallel |
| Vitest | ✅ 476 passed (47 files), 2.93s |
| ESLint | ✅ `--max-warnings 0` |
| Build (tsc+vite) | ✅ |
| Playwright `@smoke` | ✅ 40/40 passed |
