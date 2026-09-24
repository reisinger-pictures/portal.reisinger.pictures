# Brand System — Config-Driven Architecture (SOLL)

> Historical implementation reference from 2026-07-14 is not present in this
> checkout's Git object/history. The verification counts below are a historical
> snapshot; the live configuration contract is reviewed in this document and
> must be verified against the current files/config.
> Supersedes the DB-table brand approach documented in `06-multi-domain-branding.md` / `08-org-brand-concept.md` for the *storage* layer — branding is now config-file-driven.

## Decision (SOLL)

Brands are configured **statically** via `config/brands.php`. The `brands` DB table was dropped. **SRP was removed entirely**; only `rp` (B2B) remains as a `Brand` enum case.

To add a new brand: add an entry to `config/brands.php` (+ optional `Brand` enum case + the code paths that need to know about it). New brands are a **code change**, not a DB row. This is intentional — single-brand is the current reality and the previous multi-tenant SRP concept is obsolete.

### Migration frontier

The V025/V029/V030 references in the implementation history below describe
historical changes, not the current migration frontier. `V038` is the current
repository frontier (V037 Guest-Ownership, V036 Card-Testing); new schema
changes must be separate `V039+` files. V035 is the last recorded deployed
migration. Do not amend or replace a deployed migration, and keep the mandatory
seed step after every migration path.

## Historical implementation reference (2026-07-14; not present in this checkout)

| Measure | Detail |
|---------|--------|
| `config/brands.php` new | Static brand config (currently only `rp`): `name`, `theme`, `hostnames`, `features`, `frontend_url`, `from_address`, `from_name`, `accounting_email`, `primary_color`, `secondary_color`. |
| `brands` table absent | `V025__consolidated_after_v024.php` intentionally omits the historical V027/V029 brand-table work; the current migration tree contains no `V029__drop_brands_table.php`. The consolidated V025 migration owns the resulting brand-column contract. |
| `Brand::SRP` enum case removed | `app/Enums/Brand.php` only `case B2B = 'rp'`. `prefix()`/`domain()` simplified. |
| `BrandConfig` value object extended | +6 fields: `frontendUrl`, `fromAddress`, `fromName`, `accountingEmail`, `primaryColor`, `secondaryColor`. |
| `BrandRegistry` switched to config | `loadAllConfigs()` reads `config('brands')`; `hardcodedConfig()` removed; `isSrp()` → `currentId()`; `fromHost()` `buy.`-fallback replaced by `*.localhost` dev fallback. |
| `AsBrand` cast new | `app/Casts/AsBrand.php` — `Brand::tryFrom($value) ?? $value` (raw string on unknown values). Applied to 14 models. |
| Backend forks generalized | `AbstractBrandAwareMailable`, `InvoiceController`, `InvoiceMail`, `ContractPdfService`, `SettingResolver` — `isSrp` removed, switched to `BrandConfig` fields. |
| `UpdateUserRequest` dynamic | `Rule::in(array_keys(config('brands')))` instead of `in:rp,srp`. |
| PDF blade | `isSrp` ternaries → fixed RP colors (`#1E5631`/`#A4B494`). |
| Frontend | `brandRegistry.ts` dev fallback `*.localhost`, `themeMap` only `rp`, daisyUI `srp-*` themes removed, types → `string`. |
| Deleted | `SrpSettingsSeeder.php`, `app/Models/Brand.php`, `frontend/public/brands/srp/*` (10 assets), 5 SRP-only test files, `SettingsBrandPrefixTest.php`. |

## Historical verification snapshot (2026-07-14)

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
3. **Historical implementation record (not present in this checkout):** `config/brands.php` (static), `Brand::SRP` removed, `brands` table dropped. New brand = config-file entry + optional enum case (code change, no pure DB row).

## Pricing Strategy Resolution (Brand Context)

The brand-config-driven architecture interacts with the Pricing Strategy Pattern (`features/infrastructure/17-pricing-strategy-pattern.md`) via the brand-scoped `pricing_strategy` DB setting. The config feature flag is documentation/metadata only; the actual runtime strategy binding reads the DB setting in `AppServiceProvider::register()`. Gallery-level `licensing_mode` and `volume_preset_id` can override the brand default at checkout:

```
settings.pricing_strategy (brand='rp') → AppServiceProvider → PricingStrategy binding
```

The `VolumeLicensingStrategy` (formerly "SRP" volume pricing) is now a
**generic** volume-licensing mode usable by any configured brand, not
SRP-specific. Brand-feature flags in `config/brands.php` are documentation-only;
the actual resolution is DB-driven, and the per-gallery override is implemented
(the V025/V029 schema history is documented in `17` and `27`).

## Follow-up work

| Task | Status | Description |
|------|--------|-------------|
| **F2** | ✅ Implemented (2026-07-14) | Per-gallery licensing override (`licensing_mode` on `galleries`) and volume-preset assignment (`volume_preset_id`); mixed carts are grouped by `CheckoutService::groupItemsByLicensingMode()`. |
| **F3** | ✅ Implemented (2026-08-19) | Admin-UI for brand settings overlay (DB-Overlay, "Option B"). Per-brand overrides of a fixed config whitelist via `BrandSettingsService`, exposed in `ManagementSettingsView` (super_admin only). No new migration — reuses the V019 `settings` table. See `22-brand-settings-overlay.md`. |
| **F4** | ✅ Implemented (2026-07-14) | Theme-override per brand (PDF colors from `BrandConfig` already dynamic; daisyUI themes renamed `rp-light`/`rp-dark`). |

## F3 — Brand Settings Admin-UI (Implemented 2026-08-19)

F3 adds an **admin-UI for per-brand settings overrides** — deliberately **not** full CRUD on brands. It follows the **DB-Overlay ("Option B")** pattern (see `22-brand-settings-overlay.md`): `config/brands.php` remains the static default/fallback; a small whitelist of per-brand values can be overridden and persisted in the existing `settings` table.

### What was actually built

| Component | Detail |
|-----------|--------|
| Backend service | `app/Services/BrandSettingsService.php` — `OVERRIDABLE` whitelist, `apply()` (persist/reset) and `overridesFor()` (read). Writes via `Setting::updateOrCreate`/`delete` using the `brand_config.<key>` namespace; clears `BrandRegistry` memoized cache after a write. |
| Controller | `SettingsController::getBrandSettings()` (read) + `updateBrandSettings()` (write) — both in `app/Http/Controllers/SettingsController.php`. |
| Request | `app/Http/Requests/StoreBrandSettingsRequest.php` — partial payload, per-field type validation, `{brand}` whitelisted against `config('brands')`, `isAdmin`/`isSuperAdmin` authorization. |
| Routes | `backend/routes/api.php:192-193` — `GET /api/management/brand-settings` (auth:api, all management roles) and `PUT /api/management/brand-settings/{brand}` (`super_admin` middleware). |
| Merge | `BrandRegistry::buildFromArray()` overlays DB overrides onto the config default before constructing `BrandConfig`. Merge precedence: **DB override > config default**; config-only keys (theme, logos, hostnames, `is_active`) are never touched. |
| Frontend hook | `frontend/src/logic/useBrandSettings.ts` — SWR `GET`, `updateBrandSettings(brand, payload)` → `PUT`. Zod schema + partial-nullable `BrandSettingsPayload`. |
| Frontend UI | `frontend/src/ui/management/components/BrandSettingsCard.tsx` — per-brand editor (RHF + zod, explicit "Speichern" button, "Auf Standard zurücksetzen" → `null` overrides), rendered **only for super_admins** inside `ManagementSettingsView.tsx` (`{isSuperAdmin && <BrandSettingsCard/>}`). |
| Tests | `backend/tests/Feature/BrandSettingsControllerTest.php` (auth 401/403/200, 422 validation, null-reset, merge-precedence, no cross-brand leak), `frontend/src/ui/__tests__/useBrandSettings.test.ts`, `frontend/tests/e2e/admin/brand-settings.spec.ts` (`@feature:admin:brand-settings`). |

### Migration note

**No new migration was needed for F3.** Overrides reuse the existing `settings` table, whose composite primary key `(key, brand)` was established by **V019** (`V019__consolidated_fixes.php`, Part 1). F3 only introduces a new `brand_config.*` key namespace on that table.

### API endpoints

- `GET /api/management/brand-settings` → `{ brands: [{ id, editable_fields, defaults, overrides, effective }] }` for every configured brand.
- `PUT /api/management/brand-settings/{brand}` → partial payload (`{ field: value | null }`); `null` resets that field to its config default. Returns `{ success, effective }`. Super-admin only.

See `22-brand-settings-overlay.md` for the full SOLL contract (overridable whitelist, merge precedence, API shape, components).
