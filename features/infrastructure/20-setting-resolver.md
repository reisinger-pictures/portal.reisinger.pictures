# SettingResolver — Brand-Scoped Settings Access

**Status:** active  
**Tags:** `setting`, `brand`, `resolver`, `brand-scope`  
**Related:** `11-brand-settings-separation.md`, `12-brand-registry-and-settings-fixes.md`, `14-per-brand-catalog.md`

## 1. Context

The `settings` table is a plain key/value store with an additional `brand` ENUM column (`'rp'` / `'srp'`). Keys are **unprefixed** — brand isolation is achieved via the `brand` column, not via key prefixes (the legacy `srp_*` prefix approach is deprecated).

The `SettingResolver` (`backend/app/Services/SettingResolver.php`) is the single authority for reading and writing settings with brand-scoping. All controllers, mail classes, and Blade templates MUST use this resolver instead of querying the `Setting` model directly or constructing brand-prefixed keys manually.

## 2. Interface

```php
namespace App\Services;

class SettingResolver
{
    /** Read a setting value scoped to the current brand (fallback → B2B → $default). */
    public function get(string $key, mixed $default = null): mixed;

    /** Read a setting value WITHOUT brand scoping — the raw key as stored. */
    public function getRaw(string $key): mixed;

    /** Write a setting value scoped to the current brand. */
    public function set(string $key, mixed $value): void;

    /** Convenience helper: is the current runtime brand SRP? */
    public function isSrp(): bool;
}
```

## 3. Fallback Chain

`get()` resolves values in this order:

1. **Current brand row** — `Setting::where('key', $key)->where('brand', BrandRegistry::currentOrDefault())`
2. **B2B fallback** — if the current brand is NOT B2B and no brand-specific row exists, fall back to `brand = 'rp'`
3. **Default value** — the `$default` parameter passed to `get()`

This ensures that SRP reads transparently fall back to shared B2B values for keys that have not been explicitly set per brand, while B2B reads never hit the fallback (they ARE the canonical source).

### Example

| Key | `brand='rp'` | `brand='srp'` |
|-----|-------------|--------------|
| `bank_iban` | `AT12 3456…` | `DE89 1234…` |
| `term_editorial` | `Nur redaktionelle…` | *(not set)* → falls back to `rp` value |

## 4. Write Behaviour

`set($key, $value)` writes **only** to the current brand row via `updateOrCreate(['key' => $key, 'brand' => $brand], ['value' => $value])`. It never writes to a different brand.

## 5. Raw Access (Intentional Scope Bypass)

`getRaw($key)` queries the `settings` table without any brand filter. It returns the first matching row regardless of brand. This is used for intentionally global keys — currently only `pricing_strategy` (which controls which `PricingStrategy` implementation is bound in `AppServiceProvider::register()`).

Consumers SHOULD NOT use `getRaw()` for per-brand data.

## 6. Consumers

| Consumer | Method | Key Examples |
|----------|--------|-------------|
| `SettingsController::getLicenseTerms()` | `get()` | `base_price`, `term_editorial`, `mult_commercial` |
| `SettingsController::updateLicenseTerms()` | `set()` | Same keys (maps legacy `srp_*` request keys to unprefixed) |
| `SettingsController::getBillingDetails()` | `get()` | `bank_iban`, `bank_bic`, `company_street` |
| `SettingsController::updateBillingDetails()` | `set()` | Same keys |
| `SettingsController::getWatermark()` | `get()` | `watermark_opacity` |
| `SettingsController::updateWatermark()` | `set()` | `watermark_opacity` |
| `VolumeLicensingStrategy` | `get()` | `srp_price_per_image_tier1` → via `SettingResolver` (injected) |

## 7. Relation to BrandRegistry

- `SettingResolver` reads the brand from `BrandRegistry::currentOrDefault()` — it does NOT resolve the brand itself.
- `BrandRegistry::prefix()` is used for **file-system concerns** (watermark SVG file names, asset paths) — this is a separate concern from settings scoping and does NOT use `SettingResolver`.

## 8. Design Decisions

| Decision | Rationale |
|----------|-----------|
| Brand column on `settings` table | Avoids `srp_*` key prefix pollution; enables `(key, brand)` unique constraint |
| Fallback to B2B | Keeps SRP manageable — only override keys that differ |
| `getRaw()` as opt-in | Prevents accidental cross-brand reads; caller must explicitly choose to bypass scope |
| No caching in resolver | Settings change infrequently; callers (controllers) may add their own caching if profiling shows a bottleneck |

## 9. Related Documents

- `features/infrastructure/11-brand-settings-separation.md` — original spec for settings symmetry
- `features/infrastructure/12-brand-registry-and-settings-fixes.md` — BrandRegistry architecture
- `features/infrastructure/14-per-brand-catalog.md` — brand-column migration spec
