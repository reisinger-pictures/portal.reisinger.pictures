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

**Never overridable** (config-only): `theme`, `logos` (`logo_path`, etc.),
`hostnames`, `is_active`, `features.volume_licensing` (binding handled elsewhere).

## Merge precedence

**DB override > config default.**

On every read, `BrandRegistry::buildFromArray()` constructs a `BrandConfig` from
`config('brands.<id>')` and then overlays the persisted `brand_config.*` overrides
(only whitelisted keys, nested keys via dot-notation) before instantiation. The
resulting `BrandConfig` is what the rest of the app (mailers, PDFs, frontend
`/api/brand-config`, pricing strategy resolution) consumes — so an override is
effective everywhere the config is read.

After a successful write, `BrandRegistry::clearCache()` invalidates the memoized
config so the same process/request picks up the override immediately (defense-in-depth
on top of the request lifecycle).

## API contract

### `GET /api/management/brand-settings`

Auth: `auth:api` (any management role may read).

Returns, for **every** configured brand, the merge inputs and result:

```json
{
  "brands": [
    {
      "id": "rp",
      "editable_fields": ["name", "portal_name", "impressum_url", "primary_color", "secondary_color", "frontend_url", "from_address", "from_name", "accounting_email", "features.orgs"],
      "defaults":  { "name": "...", "portal_name": "...", "impressum_url": null, "primary_color": "#1E5631", "secondary_color": "#A4B494", "frontend_url": null, "from_address": null, "from_name": null, "accounting_email": null, "features": { "orgs": false } },
      "overrides": { "primary_color": "#123456" },
      "effective": { "name": "...", "portal_name": "...", "impressum_url": null, "primary_color": "#123456", "secondary_color": "#A4B494", "frontend_url": null, "from_address": null, "from_name": null, "accounting_email": null, "features": { "orgs": false } }
    }
  ]
}
```

- `defaults` — raw `config/brands.<id>` projection (no DB overlay).
- `overrides` — only the persisted `brand_config.*` deltas for that brand.
- `effective` — merged result (overrides applied over defaults) = live `BrandConfig`.

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
```

Response:

```json
{ "success": true, "effective": { /* merged BrandConfig fields */ } }
```

A successful change emits a single user-attributed audit log line
(`Brand settings updated`, with `user`, `brand`, `changed`).

## Frontend components

| File | Role |
|------|------|
| `frontend/src/logic/useBrandSettings.ts` | SWR hook: `GET` lists brands; `updateBrandSettings(brand, payload)` → `PUT /api/management/brand-settings/{brand}` then revalidates. Exports `BrandSetting`/`BrandSettingFields` interfaces, partial-nullable `BrandSettingsPayload`, and a Zod schema factory (`createBrandSettingsSchema`, kept out of module scope per i18n rules). |
| `frontend/src/ui/management/components/BrandSettingsCard.tsx` | Per-brand editor. RHF + zod with an explicit "Speichern" button (no per-keystroke PUT); only dirty fields are sent on save (because `from_name` default is not an e-mail, sending the full form would trip server-side e-mail validation). "Auf Standard zurücksetzen" sends `null` for every field to drop all overrides. **Rendered only for super-admins** (`return null` if `!isSuperAdmin`). |
| `frontend/src/ui/management/ManagementSettingsView.tsx` | Mounts `<BrandSettingsCard/>` guarded by `{isSuperAdmin && ...}`. |

UI strings are German (UI policy); the underlying contracts/code are English.

## Tests (regression coverage)

- `backend/tests/Feature/BrandSettingsControllerTest.php` — auth matrix (401 unauth, 403 admin-on-write, 200 super-admin), 422 validation cases (bad hex, bad email, bad url, unknown brand), null-reset, merge-precedence, public brand-config reflects override, no cross-brand leak.
- `frontend/src/ui/__tests__/useBrandSettings.test.ts` — SWR keying, partial PUT, null-reset revalidation.
- `frontend/tests/e2e/admin/brand-settings.spec.ts` — super-admin edits persist across reload (`@feature:admin:brand-settings`); normal admin does not see the card.
