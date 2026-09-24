# SettingResolver — Current Brand-Scoped Settings Access

**Status:** Current (reviewed 2026-09-24)
**Tags:** `setting`, `brand`, `resolver`
**Related:** [`21-brand-config-driven.md`](21-brand-config-driven.md), [`22-brand-settings-overlay.md`](22-brand-settings-overlay.md)

## 1. Storage model

The `settings` table is a key/value store with a `brand` column and the
composite database key `(key, brand)`. The currently configured live brand is
`rp`; older `srp` examples in this file are historical compatibility labels,
not a second current brand.

`Setting` is responsible for keeping the composite key brand-aware on reads and
writes. Consumers must not query an unconstrained `key` for brand-scoped data.

## 2. Resolver contract

`backend/app/Services/SettingResolver.php` exposes:

```php
public function get(string $key, mixed $default = null): mixed;
public function getRaw(string $key): mixed;
public function set(string $key, mixed $value): void;
```

There is no current `isSrp()` method and no `srp_` key-prefix resolver. The
brand context comes from `BrandRegistry::currentOrDefault()`.

## 3. Read and write behavior

### `get()`

`get($key, $default)` reads, in order:

1. `(key, current brand)`;
2. the canonical `rp` row when the current brand is not `rp`;
3. the supplied default.

The fallback exists for shared values; it is not an authorization bypass for
explicit cross-brand reads.

### `getRaw()`

`getRaw($key)` intentionally reads the first matching row without a brand
scope. It is reserved for explicitly global compatibility lookups. It must not
be used for ordinary per-brand settings or as a substitute for a brand-scoped
query.

### `set()`

`set($key, $value)` writes only to `(key, current brand)` using
`updateOrCreate`. Explicit cross-brand writes for the brand-settings overlay use
the dedicated `BrandSettingsService` path, not this resolver.

## 4. Consumers

- License terms and billing settings use `get()`/`set()`.
- Volume-preset defaults use the `VolumePresetService`; legacy `srp_*` keys are
  read only when a preset is first materialized.
- `AppServiceProvider` reads the brand-scoped `pricing_strategy` setting to bind
  the default `PricingStrategy`.
- `Gallery::effective_licensing_mode` and the checkout grouping layer apply the
  gallery-level mode/preset overrides described in
  [`17-pricing-strategy-pattern.md`](17-pricing-strategy-pattern.md).

## 5. Design decisions

| Decision | Current rule |
|---|---|
| Brand isolation | `brand` column plus composite-key-aware model queries |
| Shared-value fallback | Current brand, then canonical `rp`, then caller default |
| Legacy prefixes | `srp_*` names may remain as compatibility input, not as the resolver API |
| Raw access | Explicit opt-in only (`getRaw`) |
| Overlay writes | Dedicated service for whitelisted `brand_config.*` keys |

## 6. Related documents

- [`21-brand-config-driven.md`](21-brand-config-driven.md) — static brand source
  of truth and the current `rp` brand.
- [`22-brand-settings-overlay.md`](22-brand-settings-overlay.md) — DB overlay
  whitelist and explicit cross-brand write contract.
- [`17-pricing-strategy-pattern.md`](17-pricing-strategy-pattern.md) — pricing
  strategy resolution and gallery overrides.
