# Brand Settings Overlay Pattern (SOLL)

> Implemented as **F3** (2026-08-19). This document is the canonical SOLL for the
> per-brand settings-overlay mechanism. The static source of truth for brand config
> is `config/brands.php` (see `21-brand-config-driven.md`); this overlay layers
> optional per-brand overrides on top of it.

## Decision (SOLL)

Brand configuration stays **config-file-driven** (`config/brands.php`). F3 does
**not** add a brand CRUD layer. Instead it provides a small, fixed **whitelist of
overridable fields** that *super-admins* may override per existing brand. Overrides
are persisted in the already-existing `settings` table — **no new migration**.

This is the **DB-Overlay / "Option B"** pattern: the config file is the default and
fallback; the database carries only the deltas (overrides). Adding a new brand is
still a code change (config entry + optional enum case), not an overlay row.

## Storage

- Table: `settings` (composite PK `(key, brand)`, established by migration **V019**).
- Key namespace: `brand_config.<config_key>` (e.g. `brand_config.name`,
  `brand_config.features.orgs`). The `brand` column carries the brand key.
- Writes go through `Setting::updateOrCreate` / `delete` **directly**, not
  `SettingResolver::set()` — the resolver hangs off the host-derived (current-request)
  brand context and must not be used for explicit cross-brand writes.
- A reset is expressed by a `null` value, which deletes the override row so the
  brand falls back to the config default.

## Overridable fields whitelist

Defined in `BrandSettingsService::OVERRIDABLE` (`app/Services/BrandSettingsService.php`).
Only these keys may be overridden per brand; everything else is silently ignored:

| Config key | Type | Validation (`StoreBrandSettingsRequest`) |
|------------|------|------------------------------------------|
| `name` | string (≤255) | `sometimes`, `nullable`, `string`, `max:255` |
| `portal_name` | string (≤255) | `sometimes`, `nullable`, `string`, `max:255` |
| `impressum_url` | url\|null | `sometimes`, `nullable`, `url` |
| `primary_color` | hex string | `sometimes`, `nullable`, `regex:/^#[0-9a-fA-F]{6}$/` |
| `secondary_color` | hex string | `sometimes`, `nullable`, `regex:/^#[0-9a-fA-F]{6}$/` |
| `frontend_url` | url\|null | `sometimes`, `nullable`, `url` |
| `from_address` | email\|null | `sometimes`, `nullable`, `email` |
| `from_name` | email\|null | `sometimes`, `nullable`, `email` |
| `accounting_email` | email\|null | `sometimes`, `nullable`, `email` |
| `features.orgs` | boolean | `sometimes`, `nullable`, `boolean` (stored as `0`/`1`) |
| `pricing_strategy` | `scope_licensing`\|`volume_licensing`\|null | `sometimes`, `nullable`, `Rule::in(['scope_licensing', 'volume_licensing'])` |

**Never overridable** (config-only): `theme`, `logos` (`logo_path`, etc.),
`hostnames`, `is_active`, `features.volume_licensing` (binding handled elsewhere).

## Merge precedence

**DB override > config default.**

On every read, `BrandRegistry::buildFromArray()` constructs a `BrandConfig` from
`config('brands.<id>')` and then overlays the persisted `brand_config.*` overrides
(only whitelisted keys, nested keys via dot-notation) before instantiation. The
resulting `BrandConfig` is what the rest of the app (mailers, PDFs, frontend
`/api/brand-config`) consumes — so an override is effective everywhere the config
is read. The pricing strategy is the one exception: its resolution reads the
overlay row directly (see below), because the memoized config lives in one
process only.

After a successful write, `BrandRegistry::clearCache()` invalidates the memoized
config so the same process/request picks up the override immediately (defense-in-depth
on top of the request lifecycle).

### `pricing_strategy` is not a config default

`pricing_strategy` is the one whitelisted key that **has no counterpart in
`config/brands.php`**. The config file carries no pricing mode, so the generic
"DB override > config default" merge has no default to fall back to for it —
`defaults.pricing_strategy` is `null` today. The readout nevertheless reads the
config key (`config('brands.<id>.pricing_strategy')`), so a config that ever
carries one shows it truthfully instead of a hardcoded `null`. That config key
is the *starting value* the merged `BrandConfig` carries — it is **not** a
fallback of the resolution: the resolver consults the overlay, the settings
row, the `rp` row and the built-in default, never the config key.

The effective mode of a brand is therefore resolved by the single choke point
`app/Services/PricingStrategyResolver.php`, in this order:

1. **Brand overlay** — `brand_config.pricing_strategy` of that brand. The
   strongest source: a super-admin set it deliberately for that brand. The
   resolver reads this row **directly from the database on every resolution**,
   not through the memoized `BrandConfig` — the memo lives in one php-fpm
   worker only, so a write would otherwise be invisible in every other
   worker (and the same cart object would be priced differently depending on
   which worker served the request). The merged `BrandConfig` still carries
   the overlay for the rest of the brand configuration.
2. **Settings row** — the brand-scoped `settings.pricing_strategy` row.
3. **`rp` row as brand fallback** — the same row of the canonical B2B brand, for
   every brand that is not `rp` itself (mirrors `SettingResolver::get()`).
4. **Default** — `scope_licensing`.

A stored value that is not one of the two valid modes is **ignored** and the
resolution falls through to the next source — an unknown value must never leak
into the mode, because every consumer treats it as a closed set.

The resolver is the only place that queries the settings table for that key.
`Gallery::effective_licensing_mode`, the public pricing endpoint
(`SettingsController::resolveLicensePricingContext()`), the runtime strategy
binding (`AppServiceProvider::register()`) and the admin UI all read through it,
so they can never disagree. See
[`17-pricing-strategy-pattern.md`](17-pricing-strategy-pattern.md) §3.1.

## API contract

### `GET /api/management/brand-settings`

Auth: `auth:api` (any management role may read).

Returns, for **every** configured brand, the merge inputs and result:

```json
{
  "brands": [
    {
      "id": "rp",
      "editable_fields": ["name", "portal_name", "impressum_url", "primary_color", "secondary_color", "frontend_url", "from_address", "from_name", "accounting_email", "features.orgs", "pricing_strategy"],
      "defaults":  { "name": "...", "portal_name": "...", "impressum_url": null, "primary_color": "#1E5631", "secondary_color": "#A4B494", "frontend_url": null, "from_address": null, "from_name": null, "accounting_email": null, "features": { "orgs": false }, "pricing_strategy": null },
      "overrides": { "primary_color": "#123456", "pricing_strategy": "volume_licensing" },
      "effective": { "name": "...", "portal_name": "...", "impressum_url": null, "primary_color": "#123456", "secondary_color": "#A4B494", "frontend_url": null, "from_address": null, "from_name": null, "accounting_email": null, "features": { "orgs": false }, "pricing_strategy": "volume_licensing" },
      "pricing_strategy_source": "overlay"
    }
  ]
}
```

- `defaults` — raw `config/brands.<id>` projection (no DB overlay). `pricing_strategy`
  is the config's own value: `null` today, because the config carries no pricing
  default (see above) — it is **not** hardcoded to `null`, so a config that ever
  carries one shows it truthfully.
- `overrides` — only the persisted `brand_config.*` deltas for that brand.
- `effective` — merged result (overrides applied over defaults) = live `BrandConfig`.
  `pricing_strategy` is the **resolved** mode of the brand, not the raw overlay.
- `pricing_strategy_source` — which source produced `effective.pricing_strategy`:
  `overlay` (brand overlay), `setting` (settings row of the brand or its `rp`
  fallback) or `default` (nothing configured → `scope_licensing`).

### `PUT /api/management/brand-settings/{brand}`

Auth: `super_admin` middleware (defense-in-depth: request also requires `isAdmin`/`isSuperAdmin`).

- `{brand}` must exist in `config('brands')` (`Rule::in(array_keys(config('brands')))`).
- **Partial** payload — only changed keys are sent. Each value may be a typed value
  or `null` (resets that field to the config default by deleting the override row).
- Non-whitelisted keys are silently dropped; invalid values return `422`.

```jsonc
// request body (partial)
{ "primary_color": "#123456" }          // set override
{ "primary_color": null }                // reset to config default
{ "features": { "orgs": false } }        // nested key, stored as brand_config.features.orgs
{ "pricing_strategy": "volume_licensing" } // brand overlay for the pricing mode
{ "pricing_strategy": null }              // drop the overlay — the settings row governs again
```

Response:

```json
{ "success": true, "effective": { /* merged BrandConfig fields, incl. pricing_strategy */ } }
```

A successful change emits a single user-attributed audit log line
(`Brand settings updated`, with `user`, `brand`, `changed`).

## Frontend components

| File | Role |
|------|------|
| `frontend/src/logic/useBrandSettings.ts` | SWR hook: `GET` lists brands; `updateBrandSettings(brand, payload)` → `PUT /api/management/brand-settings/{brand}` then revalidates. Exports `BrandSetting`/`BrandSettingFields` interfaces, the `BrandPricingStrategySource` union (`overlay`\|`setting`\|`default`), the `PRICING_STRATEGY_MODES` constant with the `PricingStrategyMode` union, the partial-nullable `BrandSettingsPayload`, the `toPricingStrategyMode` narrowing, and a Zod schema factory (`createBrandSettingsSchema`, kept out of module scope per i18n rules). |
| `frontend/src/ui/management/components/BrandSettingsCard.tsx` | Per-brand editor. RHF + zod with an explicit "Speichern" button (no per-keystroke PUT); only dirty fields are sent on save (because `from_name` default is not an e-mail, sending the full form would trip server-side e-mail validation). "Auf Standard zurücksetzen" sends `null` for every field to drop all overrides and re-hydrates the form (the dirty state is cleared with it). **Rendered only for super-admins** (`return null` if `!isSuperAdmin`). |
| `frontend/src/ui/management/ManagementSettingsView.tsx` | Mounts `<BrandSettingsCard/>` guarded by `{isSuperAdmin && ...}`. |

### The "Preisstrategie" select

The pricing strategy is edited through a `<select>` with three options: the empty
value ("no brand overlay"), `scope_licensing` and `volume_licensing`.

The **form value is the overlay** (`overrides.pricing_strategy`), never the
effective mode. Hydrating the form with the effective value would silently create
an overlay for a mode that is merely in force (e.g. provided by the settings
row) the first time an admin saves an unrelated field. The effective mode and
its source are shown as a read-only hint below the select
("Aktiv: <Modus> (<Quelle>)").

An empty selection is sent as `null` and therefore deletes the override row — the
settings row governs again.

"Auf Standard zurücksetzen" deletes **all** overrides of the brand. The form is
re-hydrated from that state right away (RHF `reset()`), so the select shows
"Standard (DB-Einstellung)" again and the dirty state is cleared: a later save
of another field must not re-send `pricing_strategy` and re-create the overlay
row that was just deleted.

UI strings are German (UI policy); the underlying contracts/code are English.

## Tests (regression coverage)

- `backend/tests/Feature/BrandSettingsControllerTest.php` — auth matrix (401 unauth, 403 admin-on-write, 200 super-admin), 422 validation cases (bad hex, bad email, bad url, unknown brand), null-reset, merge-precedence, public brand-config reflects override, no cross-brand leak.
- `backend/tests/Feature/BrandPricingStrategyOverlayTest.php` — the pricing-mode resolution through the overlay: overlay wins over the settings row, settings row governs without an overlay, `scope_licensing` without both, unknown stored values (settings row and overlay) are ignored and fall through, the `rp` row as brand fallback, per-brand isolation, the gallery's own `licensing_mode` still wins, the management endpoint persists/resets/validates (`422` on an unknown mode, `403` for a non-super-admin) and reports `pricing_strategy_source`. It also pins that a write that does not touch the strategy creates no overlay, that the gallery of another brand resolves the `rp` row (and its own row, if it has one), that the runtime binding and the public endpoint resolve the same brand, and that the overlay is read **fresh**: a write that lands while the process-local `BrandRegistry` memo still holds the pre-write config is still resolved as the overlay (the memo is not consulted for the resolution).
- `frontend/src/logic/__tests__/useBrandSettings.test.ts` — SWR keying, partial PUT, null-reset revalidation, the pricing overlay in the payload, the resolved mode and its source.
- `frontend/src/ui/management/components/__tests__/BrandSettingsCard.test.tsx` — the select renders the three options, the **overlay** (not the effective value) hydrates it, the dirty value is sent, the reset sends `null`, the effective mode and its source are shown as a hint, a save that does not touch the strategy sends no `pricing_strategy`, and a reset clears the select and the dirty state (a later save of another field does not re-send the strategy).
- `frontend/tests/e2e/admin/settings/brand-settings.spec.ts` — super-admin edits persist across reload (`@feature:admin:brand-settings`); the pricing strategy is set as a brand overlay and reset again; normal admin does not see the card.
