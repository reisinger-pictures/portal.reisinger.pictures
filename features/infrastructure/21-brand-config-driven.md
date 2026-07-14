# Brand System — Config-Driven Architecture (SOLL)

> Implemented in commit `1831116` (2026-07-14), currently on `main`, **unpushed**.
> Supersedes the DB-table brand approach documented in `06-multi-domain-branding.md` / `08-org-brand-concept.md` for the *storage* layer — branding is now config-file-driven.

## Decision (SOLL)

Brands are configured **statically** via `config/brands.php`. The `brands` DB table was dropped. **SRP was removed entirely**; only `rp` (B2B) remains as a `Brand` enum case.

To add a new brand: add an entry to `config/brands.php` (+ optional `Brand` enum case + the code paths that need to know about it). New brands are a **code change**, not a DB row. This is intentional — single-brand is the current reality and the previous multi-tenant SRP concept is obsolete.

## What commit `1831116` changed

| Measure | Detail |
|---------|--------|
| `config/brands.php` new | Static brand config (currently only `rp`): `name`, `theme`, `hostnames`, `features`, `frontend_url`, `from_address`, `from_name`, `accounting_email`, `primary_color`, `secondary_color`. |
| `brands` table dropped | `V029__drop_brands_table.php` — `Schema::dropIfExists('brands')`. `V030` widens `contracts.brand` to `VARCHAR(20)`. |
| `Brand::SRP` enum case removed | `app/Enums/Brand.php` only `case B2B = 'rp'`. `prefix()`/`domain()` simplified. |
| `BrandConfig` value object extended | +6 fields: `frontendUrl`, `fromAddress`, `fromName`, `accountingEmail`, `primaryColor`, `secondaryColor`. |
| `BrandRegistry` switched to config | `loadAllConfigs()` reads `config('brands')`; `hardcodedConfig()` removed; `isSrp()` → `currentId()`; `fromHost()` `buy.`-fallback replaced by `*.localhost` dev fallback. |
| `AsBrand` cast new | `app/Casts/AsBrand.php` — `Brand::tryFrom($value) ?? $value` (raw string on unknown values). Applied to 14 models. |
| Backend forks generalized | `AbstractBrandAwareMailable`, `InvoiceController`, `InvoiceMail`, `ContractPdfService`, `SettingResolver` — `isSrp` removed, switched to `BrandConfig` fields. |
| `UpdateUserRequest` dynamic | `Rule::in(array_keys(config('brands')))` instead of `in:rp,srp`. |
| PDF blade | `isSrp` ternaries → fixed RP colors (`#1E5631`/`#A4B494`). |
| Frontend | `brandRegistry.ts` dev fallback `*.localhost`, `themeMap` only `rp`, daisyUI `srp-*` themes removed, types → `string`. |
| Deleted | `SrpSettingsSeeder.php`, `app/Models/Brand.php`, `frontend/public/brands/srp/*` (10 assets), 5 SRP-only test files, `SettingsBrandPrefixTest.php`. |

## Verification (2026-07-14, updated)

| Suite | Result |
|-------|--------|
| PHPUnit | ✅ 986 passed (2301 assertions), 17.1s (parallel) |
| Vitest | ✅ 476 passed (47 files), 2.93s |
| ESLint | ✅ `--max-warnings 0` |
| Build (tsc+vite) | ✅ |
| Playwright `@smoke` | ✅ 40/40 passed (F1 fixed Stripe iframe flaky test) |

`grep` verification (production code): no fatal references to `Brand::SRP`, `isSrp()`, `BrandModel`, `SrpSettingsSeeder` — all cleanly removed. 13 `brand` columns in the live DB are `VARCHAR(20)` (consistent).

## Strategy history (for traceability)

1. **Originally:** "SRP shutdown = collapse onto RP" → rejected (anti-multi-tenant).
2. **Planned pivot (14.07.):** "extend `brands` table, keep `Brand::SRP`, BrandConfig-driven" → **not implemented**.
3. **Actually implemented (`1831116`):** `config/brands.php` (static), `Brand::SRP` removed, `brands` table dropped. New brand = config-file entry + optional enum case (code change, no pure DB row).

## Pricing Strategy Resolution (Brand Context)

The brand-config-driven architecture interacts with the Pricing Strategy Pattern (`features/infrastructure/17-pricing-strategy-pattern.md`) via the `pricing_strategy` DB setting, which is brand-scoped (`config('brands.*.features.volume_licensing')` controls the flag; the actual strategy binding in `AppServiceProvider::register()` resolves at runtime:

```
settings.pricing_strategy (brand='rp') → AppServiceProvider → PricingStrategy binding
```

The `VolumeLicensingStrategy` (formerly "SRP" volume pricing) is now a **generic** volume-licensing mode usable by any brand, not SRP-specific. Brand-feature flags in `config/brands.php` are documentation-only — the actual resolution is DB-driven for runtime flexibility (and to allow the per-gallery override planned in F2).

## Follow-up work

| Task | Status | Description |
|------|--------|-------------|
| **F2** | ✅ Implemented (2026-07-14) | Per-gallery licensing override (`licensing_mode` on `galleries`, Mixed-Cart via `CheckoutService::groupItemsByLicensingMode()`). |
| **F3** | 🔙 Backlog | Admin-UI for brand settings (NOT full CRUD — only settings for existing brands). Requires config-write-layer or DB revert; not planned for near future. |
| **F4** | ✅ Implemented (2026-07-14) | Theme-override per brand (PDF colors from `BrandConfig` already dynamic; daisyUI themes renamed `rp-light`/`rp-dark`). |
