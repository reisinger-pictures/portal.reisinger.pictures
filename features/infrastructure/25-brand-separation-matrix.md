# Brand Separation Matrix — RP vs SRP

> **Status:** active (analysis)
> **Last updated:** 2026-07-06
> **See also:** `features/infrastructure/12-brand-registry-and-settings-fixes.md`

## Introduction

The portal runs two white-label brands:

- **RP (B2B)** — `portal.reisinger.pictures` (prod), `portal.localhost` (dev). Full admin portal with tenants, CRM, invoicing, contracts, and photographer management.
- **SRP (B2C)** — `buy.reisinger.pictures` (prod), `buy.localhost` (dev). Reduced end-customer portal for reisinger.pictures direct sales.

Runtime brand is resolved from the HTTP host by `BrandRegistry::fromHost()` (backend) and `getBrandFromHostname()` (frontend). The host prefix `buy.` → SRP, everything else → RP.

## Separation Matrix

| Feature | RP | SRP | Cross-Brand | Notes |
|---|---|---|---|---|
| **Tenants/Organizations** | ✅ Full UI | ❌ Hidden (sidebar `!isSrp`) | Super-Admin (brand=null) sees all brands | Route requires `requiredFeature="b2b"`. Tenant model has nullable `brand` column. |
| **Coupons** | ❌ UI blocked ("only on SRP") | ✅ Full CRUD | Super-Admin can manage via API from any context | Frontend gating only (`!isSrp` early return). Backend `CouponController` may create coupons on any brand. |
| **CRM (Customers)** | ✅ Full CRUD | ❌ No UI | Brand-scoped by `Customer::forCurrentBrand()` | Route requires `requiredFeature="b2b"`. Customers have `brand` column. |
| **Invoices** | ✅ Full | ⚠️ Via orders (SRP orders generate RP invoices) | Invoice brand is inherited from order | `InvoiceService` brands from order->brand, fallback B2B. Snapshots have `brand` column. |
| **Payouts (Admin)** | ✅ Full | ❌ No admin payouts on SRP | — | Sidebar item only under `isAdmin && !isSrp`. |
| **Payouts (Photographer)** | ✅ My payouts | ✅ My payouts | — | Route has no `requiredFeature` guard. |
| **License Catalog (Products)** | ✅ Full CRUD | ✅ API scope (`forCurrentBrand()`) | Each brand has own use-cases/modifiers | Edit UI only on RP (`!isSrp` + `isSuperAdmin`). |
| **Contracts** | ✅ Full | ❌ No UI | Brand column on Contract model | `ContractController` scopes by `BrandRegistry::currentOrDefault()`. |
| **Reisinger-Kalkulator** | ✅ Premium Tarif | ✅ B2C Flex + Premium toggle | Shared settings (brand-prefixed via `srp_*` keys) | **Complex**: SRP shows both B2C Flex AND Premium Tarif tabs. Calculator lives in `CalculatorSettingsCard` (both brands) and `ShootingCalculatorModal`. |
| **Settings / Branding** | ✅ Own theme/logo/watermark | ✅ Own theme/logo/watermark | `SettingResolver` with prefix | `applyTheme()` sets brand-specific daisyUI themes (`b2b-*` / `srp-*`). |
| **FTP Inboxes** | ✅ Photographer inbox | ✅ Photographer inbox | Per-user, brand-scoped via `getAllowedGalleryIds()` | `ftp_slug` is user-level, not brand-level. |
| **Stats** | ✅ Admin/OrgAdmin stats | ✅ Admin/OrgAdmin stats | Cross-brand (no brand filter in queries) | Admin sees ALL galleries. No brand filtering in `StatsCalculationService`. |
| **Registration** | ✅ Brand=B2B for new users | ✅ Brand=SRP for new users | Auto-join scoped by tenant's brand | `AuthController::register()` writes `BrandRegistry::currentOrDefault()` into user.brand. |
| **Login** | ✅ Brand-bound check | ✅ Brand-bound check | Super-Admin (brand=null) cross-brand | `AuthController::login()` rejects brand mismatch (U-01). |
| **Search (Global)** | ✅ All galleries | ✅ All galleries | Cross-brand by role | `SearchView` no brand gating. |
| **Watermarks** | ✅ Own SVGs | ✅ Own SVGs | Per-brand file prefix | `SettingsController` uses `BrandRegistry::prefix()` for `_watermarks/` path. |

## Gaps & Complexities

### 1. Reisinger-Kalkulator — mixed ownership
The calculator is available **on both brands** but with different modes:
- **RP default** (non-SRP): Full "Shooting-Paket Kalkulator" with duration, images, outdoor/flatrate/discount
- **SRP default**: "B2C Flex-Paket Rechner" with portrait/couple/nude tiers
- **SRP Premium toggle**: On SRP, users can switch to the RP "Premium Tarif" calculator

This makes the calculator a **cross-brand feature** with brand-aware UI switching. Settings are stored brand-scoped via the `SettingResolver` prefix.

### 2. Coupons — API supports cross-brand, UI is SRP-only
The `ManagementCouponsView` blocks RP users with an early return, but the backend `CouponController` has no brand gating. A super-admin on RP could theoretically manage SRP coupons via direct API calls. The **de facto boundary is UX-only**, not enforced at the API level.

### 3. Stats — no brand scoping for admins
`StatsCalculationService::getAdminStats()` runs `Gallery::count()` across ALL brands. An admin logged into SRP sees statistics for all RP galleries too. This may be intentional (admin oversight) but is inconsistent with the brand-scoping pattern used everywhere else.

### 4. Super-Admin visibility
A super-admin (`user.brand = null`) has **unrestricted cross-brand access**. They can:
- Log in at both portals
- See all galleries (all brands)
- Access all B2B admin features from any context
- Manage SRP coupons while on RP (via API)

**Question**: Should the super-admin *see* everything on both UIs, or should the UI hide cross-brand items when the admin is on a specific brand domain?

### 5. Domain naming mismatch
The `features/infrastructure/12-brand-registry-and-settings-fixes.md` (line 14) mentions `story.reisinger.pictures` as the SRP domain, but the actual codebase uses `buy.reisinger.pictures`. The `story` domain was likely an earlier naming that was changed.

## Open Architecture Questions

See "Architect's Questions" section below.

## Questions for the Architect

1. **Calculator ownership:** Is the Reisinger-Kalkulator intentionally cross-brand, or should SRP only show the B2C Flex calculator (without the "Premium Tarif" toggle)? If it should be cross-brand, should the RP calculator settings remain editable on SRP too?

2. **Strict separation vs mixed-mode:** Is the current "mixed mode" (some features strictly separated by brand, others cross-brand) intentional, or should we move toward stricter brand isolation? E.g., should coupons be truly cross-brand (with a brand column) or stay SRP-only with API-level enforcement?

3. **Super-Admin UI:** When a super-admin logs into the SRP portal, should they see ALL admin features (invoices, payouts, contracts) or only SRP-relevant ones? Currently the sidebar hides B2B features on SRP even for super-admins, but the routes remain accessible via direct URL navigation.
