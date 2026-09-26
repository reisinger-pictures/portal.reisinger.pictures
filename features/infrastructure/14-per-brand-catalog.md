# 14 — Per-Brand Catalog, CRM & Settings Isolation

> **Historical design record (2026-06-30).** This page preserves the former
> multi-brand proposal. The current runtime brand contract is RP-only and is
> documented in `21-brand-config-driven.md`; references to the removed second
> brand below are historical, not current configuration or seed instructions.
> Related: `11-brand-settings-separation.md`, `12-brand-registry-and-settings-fixes.md`,
> `15-strict-user-brand-isolation.md` (planned).

## 1. Goal

All catalog, CRM, and settings data MUST be isolated per brand. A user browsing/operating
under brand `rp` (reisinger.pictures) MUST only see `rp` rows; the same applies to `srp`
(story.reisinger.pictures). Cross-brand access is reserved for the single Super-Admin account
(`florian@reisinger.pictures`, `brand = null`).

## 2. Scope — affected tables (V019 added `brand ENUM('rp','srp')`)

| Table | Purpose | T-17 / U-03 |
|-------|---------|-------------|
| `products` | Pakete / discounts (Shooting Calculator + manual invoices) | brand-scoped CRUD |
| `license_use_cases` | Per-image licensing base prices | brand-scoped read + admin CRUD |
| `license_modifiers` | License surcharge modifiers | brand-scoped read + admin CRUD |
| `settings` | Global key/value config (prices, multipliers, terms, calc, watermark) | **migrated from `srp_` prefix to `brand` column** |
| `customers` | CRM customers | brand-scoped CRUD (U-03) |
| `text_snippets` | CRM text snippets | brand-scoped CRUD (U-03) |

## 3. Design decisions

### 3.1 Brand resolution source

Brand scoping uses `App\Support\BrandRegistry::currentOrDefault()->value` (resolves from HTTP host
via `BrandContextMiddleware`, falls back to `Brand::B2B` = `'rp'`). It is **independent of the
authenticated user** — the catalog endpoints (`LicenseCatalogController@index`) are public and
consume the host-derived brand. This matches the existing multi-domain resolution.

### 3.2 SettingResolver migration — `srp_` prefix → `brand` column

**OLD (deprecated by this spec):** settings namespaced via key prefix (`srp_base_price`,
`srp_watermark_opacity`, …). Implemented in `SettingResolver::prefix()`.

**NEW:** every settings row carries a `brand` column. Reads scope by `(key, brand)`:

```php
// SettingResolver::get($key, $default)
$brand = BrandRegistry::currentOrDefault()->value;
$value = Setting::where('key', $key)->where('brand', $brand)->value('value');
if ($value !== null) return $value;
// Fallback: B2B row (rp is the canonical/default brand for shared keys)
$value = Setting::where('key', $key)->where('brand', Brand::B2B->value)->value('value');
return $value ?? $default;
```

- `prefix()` is removed (or reduced to a no-op/deprecated shim) — no more `srp_` key construction.
- `set($key, $value)` writes with the current brand: `updateOrCreate(['key'=>$key,'brand'=>$brand], ['value'=>$value])`.
- `getRaw($key)` stays for intentionally brand-agnostic keys (rare).
- **Watermark** keys (`watermark_opacity`, svg assets) follow the same `brand`-column rule. Asset
  files keep their brand directory (`public/brands/srp/…`) — that is a separate file-name concern,
  not a settings concern.

### 3.3 Controller scoping pattern

Every read/write in `ProductController`, `LicenseCatalogController`, `CustomerController`,
`TextSnippetController` applies `->where('brand', BrandRegistry::currentOrDefault()->value)` on
queries and injects `'brand' => BrandRegistry::currentOrDefault()->value` on create. A shared
Eloquent local scope `forCurrentBrand()` on each model reduces duplication:

```php
public function scopeForCurrentBrand(Builder $q): Builder {
    return $q->where($q->getQuery()->from . '.brand', BrandRegistry::currentOrDefault()->value);
}
```

> **Super-Admin visibility:** The Super-Admin (`brand = null`) currently authenticates via the
> `super_admin` middleware on admin routes. Per the strict isolation decision (U-01), the Super-Admin
> is the ONLY cross-brand account. Admin catalog/CRM endpoints MAY show all brands to the Super-Admin
> by skipping the scope when the acting user is Super-Admin — OR keep brand-bound (simpler, the
> Super-Admin sees the host brand's catalog). **Decision for T-17:** keep it simple — the scope
> always binds to the host brand; a future "show all brands" admin view is out of scope. The
> Super-Admin can switch brand by operating on the respective portal domain.

### 3.4 Searchables (Customer, TextSnippet) — Scout

`Customer` and `TextSnippet` use Laravel Scout (`Searchable`). Scout search results are NOT
automatically DB-filtered. Two options:

- **Option A (chosen):** Apply a DB `->where('brand', …)` query filter **after** collecting Scout
  IDs (`Customer::search($q)->query(fn($q)=>$q->where('brand', …))`). Scout supports a `query`
  callback to add SQL constraints to the underlying select.
- Option B (rejected): reindex brand into the searchable array — heavier, denormalizes.

Option A keeps Scout intact and adds the brand filter at the DB layer.

### 3.5 `TextSnippet` shortcut uniqueness

The current `unique:text_snippets,shortcut` rule is global. T-17 narrows uniqueness to the brand:
a custom rule `Rule::unique('text_snippets', 'shortcut')->where(fn($q)=>$q->where('brand', $brand))`
on store, and `->ignore($id)` on update.

### 3.6 Defense-in-depth — PricingService & CheckoutService

`PricingService::calculateItemPriceCents` and `CheckoutService` resolve `useCaseId`/`modifierIds`
from client request input. Even though the catalog the client sees is brand-scoped, the IDs can be
tampered. T-17 adds an explicit brand guard: when loading a `LicenseUseCase`/`LicenseModifier`,
assert its `brand` matches the current brand (else treat as not-found / 422). This prevents
cross-brand price injection.

## 4. Models (add `brand` to `$fillable` + `$casts['brand'=>Brand::class]` + scope)

- `Product`, `LicenseUseCase`, `LicenseModifier`, `Customer`, `TextSnippet`: add `'brand'` to
  `$fillable`; add `'brand' => \App\Enums\Brand::class` to `$casts`; add `scopeForCurrentBrand`.
- `Setting`: add `'brand'` to `$fillable`; `$casts['brand'=>Brand::class]`. Note `Setting`'s
  primary key is `key` (string) — with brand isolation the effective unique key becomes
  `(key, brand)`. **A unique index `(key, brand)` is added via a follow-up migration if not
  already present** (T-17 may extend V019's index to a composite unique, or add a V020).

## 5. DatabaseSeeder (current contract)

`DatabaseSeeder::run()` resolves `BrandRegistry::currentOrDefault()` and calls
`seedCatalogForBrand($brand)` once for the currently configured brand. The current
implementation imports `App\Enums\Brand` and `App\Support\BrandRegistry`; it
does not call a second brand seeder or a removed model/enum case. Products,
license use cases, modifiers, settings, customers, and text snippets each carry
the resolved brand explicitly. The former two-brand instruction is historical
and must not be reintroduced.

## 6. SettingsController

- `getLicenseTerms`: replace raw `Setting::where('key','srp_base_price')…` reads with
  `SettingResolver::get('base_price')` etc. — so brand scoping is central. Remove explicit
  `srp_*` keys from the response.
- `updateLicenseTerms` validation: drop `srp_*` keys; the resolver writes with the current brand.

## 7. Tests

- `SettingsBrandPrefixTest` + `SettingResolverTest`: rewrite to assert brand-column behaviour
  (no `srp_` prefix). Set `config(['app.brand' => 'srp'])` to simulate the SRP host.
- `ProductApiTest`, `LicenseCatalogTest`, `CrmAndSnippetTest`: extend with a brand-scoping case
  — a row seeded under `rp` is invisible when `config('app.brand')='srp'` and vice versa.
- `CheckoutServiceTest` / `PricingServiceTest`: add a cross-brand tamper case (SRP host, rp
  useCaseId) → rejected.

## 8. Out of scope

- SRP volume/tiered pricing model (T-20) — separate spec.
- "Show all brands" Super-Admin admin view.
- Rabattcodes / coupons (future B2C feature).
