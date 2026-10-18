# Pricing Strategy Pattern — Current Architecture

> **Status:** Current SOLL (reviewed 2026-09-24). The former SRP portal and
> `Brand::SRP` were removed; the volume strategy is now a generic pricing mode.
> The historical origin is retained in
> [`16-srp-volume-pricing.md`](16-srp-volume-pricing.md). The canonical preset
> details are in [`27-volume-licensing-presets.md`](27-volume-licensing-presets.md).

## 1. Scope and current terms

The application has two pricing strategies:

- **Scope licensing** — item-by-item B2B pricing through
  `LicenseUseCase` and `LicenseModifier` records.
- **Volume licensing** — quantity-based pricing using a configurable
  `VolumePreset`.

The runtime brand is currently `rp` only. Names such as `srp_*` in settings,
request payloads, comments, or older documents are compatibility/history labels;
they do not describe a second live brand or a second current pricing model.

The brand-level default is the `pricing_strategy` setting. A gallery can
override that mode with `galleries.licensing_mode`, and a volume gallery can
select a concrete `galleries.volume_preset_id` (or `null` for the brand default).
These columns and the preset tables are part of V025/V029 and are live contracts,
not a planned F2 design.

## 2. Strategy contract

`App\Contracts\PricingStrategy` currently exposes:

```php
public function calculateCart(array $items, User $user, ?string $couponCode = null): array;
public function supportsCoupons(): bool;
```

`PricingService::calculateItemPriceCents()` remains a compatibility wrapper
that delegates to the injected strategy for a single item. Checkout itself uses
`calculateCart()` so it can calculate a complete server-authoritative cart.

### 2.1 `ScopeLicensingStrategy`

- Looks up the selected use case and modifiers for each non-quote item.
- Applies the user's flat-rate/tier and modifier surcharges.
- Preserves the `guardBrand()` defense against cross-brand catalog injection.
- Quote items are zero-priced.
- `supportsCoupons()` is `false`; scope pricing does not apply a coupon.

### 2.2 `VolumeLicensingStrategy`

- Receives a `VolumePreset` with any number of ordered tiers
  (`min_quantity`, `price_cents`).
- Counts non-quote items, selects the highest qualifying tier, and charges the
  base-tier amount less an itemized retroactive tier discount.
- Quote items are zero-priced and do not count toward the tier.
- Coupon calculations for `max_items` and `photo_package` use a separate
  effective-price item representation based on the qualifying tier. The
  invoice item lines remain at the base price and show the volume discount as
  separate `tier_breakdown` lines.
- The implementation also handles non-monotonic/duplicate tier data without
  allowing a volume step to increase the total; the invoice breakdown remains
  consistent with `totalCents`.
- `supportsCoupons()` is `true`; `CouponService` is applied after volume
  pricing.
- Volume-priced items are persisted with the supported `original` entitlement
  tier. `volume` is a pricing-mode label, not a downloadable resolution tier.

## 3. Resolution and mixed carts

### 3.1 Brand default

`AppServiceProvider::register()` binds the default `PricingStrategy` by reading
the brand-scoped `pricing_strategy` setting. The default fallback is
`scope_licensing`; the value `volume_licensing` selects the volume strategy.
The config feature flag is descriptive only; the database setting controls the
runtime resolution.

### 3.2 Gallery overrides

`Gallery::effective_licensing_mode` is resolved in this order:

1. `galleries.licensing_mode`, when set (`scope_licensing` or `volume_licensing`).
2. The gallery-brand `pricing_strategy` setting.
3. `scope_licensing` when no setting exists.

For volume mode, `VolumePresetService::resolveForGallery()` chooses the
gallery's `volume_preset_id` when valid, otherwise the brand default preset.

### 3.2.1 Public pricing endpoint vs. full price parameters

Three endpoints serve this resolution, split by what they may disclose
(owner decisions D-27/D-28/D-32):

- **`GET /api/settings/license-pricing` — public, deliberately minimized.** It
  returns exactly `pricing_strategy` and `volume_pricing`
  (`{preset_id, preset_name, tiers[]}`) for a supplied `gallery_id`. This is
  what the buy flow (photo page, cart, gallery view) reads. The effective mode
  is public by construction — `Gallery::effective_licensing_mode` is already
  resolved in the gallery tree the buy flow renders — and the tier table is the
  price *schedule* the client must display.
- **`GET /api/settings/license-terms` — `auth:api` + `super_admin`.** It carries
  every internal price parameter (`price_*`, `calc_*`, `srp_*`, `mult_*`,
  `base_price`, licence texts). Only the management UI (settings form, shooting
  calculator) reads it, and both callers are already super-admin gated.
- **`GET /api/settings/license-catalog` — public by design, throttled**
  (`throttle:api`, owner decision D-32). It is the *published customer price
  list*, not an internal parameter look-up: it projects `license_use_cases`
  (`name`, `description`, `base_price`, `flatrate_tier`, `is_commercial`) and
  `license_modifiers` (`name`, `description`, `percent_surcharge`,
  `is_included_in_flatrate`) through `LicenseUseCaseResource` /
  `LicenseModifierResource`. Those two resources carry no internal parameter.
  Two consumers read it through `useLicenseCatalog()`: the client license
  selector (`frontend/src/ui/client/components/LicenseSelectorCard.tsx` —
  `catalog.use_cases`, `uc.flatrate_tier`, `m.percent_surcharge`) and the
  management editor
  (`frontend/src/ui/management/components/LicenseCatalogSettings.tsx`, reachable
  via `PricingSettingsTabs.tsx`, which also renders `is_commercial` and
  `sort_order`). Both read the same public payload, so the management path
  discloses nothing extra. This is the same class as the
  deliberately public `volume_pricing` from D-28 — the customer must see the
  price they are asked to pay — in contrast to D-27, where the *calculation*
  parameters live.

The two *pricing* endpoints (`license-pricing`, `license-terms`) resolve the
gallery through the same shared `SettingsController::resolveLicensePricingContext()`
and project the preset through `SettingsController::volumePricingPayload()`, so a
gallery cannot resolve to a different mode or preset depending on which of them is
called. `license-catalog` does not resolve a gallery at all.

### 3.3 Checkout grouping

`CheckoutService` does not force every cart item through the brand-level binding.
It groups items by `(effective licensing mode, volume preset)` and calculates
each group with the matching strategy. This permits a mixed cart containing
scope and volume galleries, including different volume presets. The resulting
line items, tier breakdowns, and totals are merged before the order is created.

A signed quote token bypasses the normal strategy calculation after its
signature, expiry, and positive server-authoritative amount have been verified.

## 4. Coupons and pricing modes

The 2026-08-04 decision decoupled coupon **UI and management availability**
from the brand's pricing mode: the navigation and coupon forms are not hidden
because a gallery uses volume or scope pricing. That does not mean both
strategies currently calculate the same discount:

- `VolumeLicensingStrategy::supportsCoupons()` is `true`; fixed, percentage,
  and `photo_package` coupons are applied after volume pricing.
- `ScopeLicensingStrategy::supportsCoupons()` is `false`; checkout does not
  resolve or increment a coupon for a scope-only group. A coupon must not be
  silently consumed without a discount.
- Mixed carts apply the coupon only to the combined volume subtotal. Checkout
  validates the scoped coupon against all non-quote volume items and applies
  it once at order level; scope-only groups remain outside the discount. A
  checkout that requests a coupon for a volume group revalidates it and fails
  closed if it is invalid.
- A valid discount that reduces a positive cart to exactly zero follows the
  settled-free order path; it is not sent through PaymentIntent creation.

The current coupon data model and API details are maintained in
[`../ecommerce/08-srp-coupon-system.md`](../ecommerce/08-srp-coupon-system.md).
The old document's SRP host/brand assumptions are historical; the live
contract is brand-scoped coupon management on the configured `rp` brand.

## 5. Presets and legacy settings

`VolumePresetService` creates one default preset per brand and seeds it from
legacy `srp_price_per_image_tier*`/`srp_tier_threshold*` values when present.
The legacy rows are left in place for compatibility and are not the preferred
configuration path. New writes use the V029 `volume_presets` and
`volume_preset_tiers` tables, with gallery assignment through
`galleries.volume_preset_id`.

A per-brand `pricing_strategy` editor is not part of the current brand-settings
overlay whitelist. Exposing that setting in the admin UI remains a future task;
until then, the DB setting and gallery fields are the authoritative controls.

## 6. Decision history

1. **2026-07-01:** The strategy pattern was introduced with scope licensing and
   the then-SRP volume model.
2. **2026-07-14 (historical implementation step; its commit reference is not
   present in this checkout):** The SRP portal and `Brand::SRP` were removed. The
   volume implementation remained as a generic mode; the brand setting became
   the runtime selector.
3. **2026-07-14:** Gallery-level licensing mode and volume-preset assignment were
   implemented. The old “planned F2” description in earlier revisions is
   historical, not current.
4. **2026-08-04 (`2394999`):** Coupon UI/management was decoupled from pricing
   mode. The backend strategy capability remains explicit as described in §4.
5. **2026-08-13:** V029 introduced configurable, arbitrary-tier volume presets;
   see [`27-volume-licensing-presets.md`](27-volume-licensing-presets.md).

## 7. Related documents

- [`16-srp-volume-pricing.md`](16-srp-volume-pricing.md) — historical SRP
  origin and migration context.
- [`27-volume-licensing-presets.md`](27-volume-licensing-presets.md) — current
  preset data model, API, and frontend contract.
- [`21-brand-config-driven.md`](21-brand-config-driven.md) — current single-brand
  configuration and the boundary between config and DB settings.
- [`../ecommerce/09-stripe-checkout-flow.md`](../ecommerce/09-stripe-checkout-flow.md)
  — checkout/order state machine.
