<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\Setting;
use App\Support\BrandRegistry;

/**
 * The single resolution point for the brand-scoped pricing strategy.
 *
 * Three sources are consulted, in this order:
 *
 * 1. **Brand overlay** — the per-brand DB override (`brand_config.pricing_strategy`,
 *    written through `BrandSettingsService`). It is the strongest source: a
 *    super-admin set it deliberately for that brand. It is read from the
 *    database on every resolution, so a write is effective in every worker
 *    immediately (see {@see overlayFor()}).
 * 2. **Settings row** — the brand-scoped `pricing_strategy` setting row.
 * 3. **B2B fallback** — the same row for the canonical brand (`rp`), but only
 *    for a brand that is not `rp` itself. This mirrors the brand fallback of
 *    `SettingResolver::get()`.
 * 4. **Default** — `scope_licensing`.
 *
 * A stored value that is not one of `MODES` is ignored (fail closed to the next
 * source): an unknown value must never leak into the resolution, because every
 * consumer treats the mode as a closed set.
 *
 * Every direct read of the `pricing_strategy` setting goes through this class —
 * it is the only place that queries the settings table for that key.
 */
class PricingStrategyResolver
{
    public const SCOPE_LICENSING = 'scope_licensing';

    public const VOLUME_LICENSING = 'volume_licensing';

    /** @var string[] the complete set of valid pricing modes */
    public const MODES = [self::SCOPE_LICENSING, self::VOLUME_LICENSING];

    /** The mode that applies when no source provides a valid one. */
    public const DEFAULT = self::SCOPE_LICENSING;

    /** The brand overlay produced the value. */
    public const SOURCE_OVERLAY = 'overlay';

    /** A settings row (own brand or the B2B fallback) produced the value. */
    public const SOURCE_SETTING = 'setting';

    /** Nothing was configured; the built-in default applies. */
    public const SOURCE_DEFAULT = 'default';

    /**
     * The effective pricing mode for a brand.
     *
     * @param  string|null  $brandId  the brand id; defaults to the active brand
     *                                (or the canonical B2B brand without one)
     */
    public function resolve(?string $brandId = null): string
    {
        return $this->resolveWithSource($brandId)[0];
    }

    /**
     * Which source produced {@see resolve()}: `overlay`, `setting` or `default`.
     *
     * @param  string|null  $brandId  the brand id; defaults to the active brand
     */
    public function source(?string $brandId = null): string
    {
        return $this->resolveWithSource($brandId)[1];
    }

    /**
     * The effective pricing mode for an explicit brand id.
     */
    public function effectiveForBrand(string $brandId): string
    {
        return $this->resolve($brandId);
    }

    /**
     * Is a strategy *stored* for this brand at all?
     *
     * Unlike {@see resolve()} this ignores whether the stored value is a valid
     * mode: a row that holds an unknown value is still a stored configuration.
     * The compatibility fallback in `CheckoutService` needs exactly that
     * distinction — an invalid stored value must resolve to the default, not
     * hand the decision back to an injected strategy.
     *
     * The asymmetry to {@see resolve()} is deliberate: this method does **not**
     * consider the `rp` fallback. A brand without its own row has stored
     * nothing of its own, so the injected strategy stays in charge there —
     * the pre-existing `CheckoutService` compatibility fallback is preserved
     * exactly as it was before the resolver existed.
     */
    public function hasStoredStrategy(?string $brandId = null): bool
    {
        $brandId = $this->normalizeBrandId($brandId);

        if ($this->overlayFor($brandId) !== null) {
            return true;
        }

        return $this->settingFor($brandId) !== null;
    }

    /**
     * Resolve the mode and the source that produced it in one pass.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveWithSource(?string $brandId = null): array
    {
        $brandId = $this->normalizeBrandId($brandId);

        $overlay = $this->overlayFor($brandId);
        if ($this->isMode($overlay)) {
            return [$overlay, self::SOURCE_OVERLAY];
        }

        $setting = $this->settingFor($brandId);
        if ($this->isMode($setting)) {
            return [$setting, self::SOURCE_SETTING];
        }

        // Brand fallback: the canonical B2B row serves every other brand.
        if ($brandId !== Brand::B2B->value) {
            $fallback = $this->settingFor(Brand::B2B->value);
            if ($this->isMode($fallback)) {
                return [$fallback, self::SOURCE_SETTING];
            }
        }

        return [self::DEFAULT, self::SOURCE_DEFAULT];
    }

    /**
     * The brand id to resolve for.
     *
     * Without an explicit id the active brand wins; without an active brand
     * context the canonical B2B brand applies.
     */
    private function normalizeBrandId(?string $brandId): string
    {
        $brandId = $brandId === null ? '' : trim($brandId);

        if ($brandId !== '') {
            return $brandId;
        }

        return BrandRegistry::currentIdOrNull() ?? Brand::B2B->value;
    }

    /**
     * The per-brand overlay value, or null when the brand has none.
     *
     * The overlay row is read **directly from the database on every call** —
     * never through the memoized `BrandConfig`. `BrandRegistry` keeps its
     * merged brand configs in a process-local static property that only
     * `BrandRegistry::clearCache()` empties, and the HTTP path clears it in
     * the *writing* process only (`SettingsController::updateBrandSettings()`).
     * Every other php-fpm worker would therefore keep resolving the stale
     * `pricingStrategy = null` of its memo, so the same cart object would be
     * priced differently depending on which worker served the request.
     *
     * Reading the overlay row itself keeps the overlay brand-scoped for a
     * brand id that `config/brands.php` does not know, and it makes the
     * overlay effective in every worker at once. The merged `BrandConfig`
     * still carries the overlay (`BrandRegistry::buildFromArray()` merges the
     * whitelisted overrides into it) — that remains the value the rest of the
     * brand configuration uses.
     */
    private function overlayFor(string $brandId): ?string
    {
        $overrides = app(BrandSettingsService::class)->overridesFor($brandId);
        $value = $overrides['pricing_strategy'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The raw `pricing_strategy` settings row of a brand — unvalidated, so an
     * unknown value is visible here and rejected by the caller.
     */
    private function settingFor(string $brandId): ?string
    {
        $value = Setting::where('key', 'pricing_strategy')
            ->where('brand', $brandId)
            ->value('value');

        return is_string($value) ? $value : null;
    }

    private function isMode(?string $value): bool
    {
        return $value !== null && in_array($value, self::MODES, true);
    }
}
