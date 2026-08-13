<?php
namespace App\Services;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\VolumePreset;
use App\Models\VolumePresetTier;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for volume-licensing presets.
 *
 * - Ensures exactly one default preset per brand (`ensureDefaultPresetForBrand`),
 *   migrating the legacy flat `srp_price_per_image_tier*`/`srp_tier_threshold*`
 *   settings keys into the first generated preset.
 * - Resolves the effective preset for a gallery (gallery override, else brand default).
 * - Full CRUD including default promotion and delete constraints.
 */
class VolumePresetService
{
    /** Default tiers used when no srp_* settings exist (kept as documented fallback). */
    public const DEFAULT_TIERS = [
        ['min_quantity' => 0, 'price_cents' => 3000],
        ['min_quantity' => 10, 'price_cents' => 2500],
        ['min_quantity' => 20, 'price_cents' => 2000],
    ];

    /**
     * Return the default preset for the given brand, creating it on first access.
     *
     * Legacy `srp_*` settings are consumed once and baked into the preset tiers;
     * the settings rows are left untouched (harmless legacy keys).
     */
    public function ensureDefaultPresetForBrand(Brand|string $brand): VolumePreset
    {
        $brandValue = $brand instanceof Brand ? $brand->value : $brand;

        $existing = VolumePreset::where('brand', $brandValue)->default()->first();
        if ($existing !== null) {
            return $existing;
        }

        // If a non-default preset exists, promote it instead of duplicating.
        $fallback = VolumePreset::where('brand', $brandValue)->first();
        if ($fallback !== null) {
            $fallback->update(['is_default' => true]);
            return $fallback;
        }

        return DB::transaction(function () use ($brandValue) {
            $preset = VolumePreset::create([
                'brand' => $brandValue,
                'name' => 'Standard',
                'is_default' => true,
            ]);

            $tiers = $this->migrateLegacySettings($brandValue) ?? self::DEFAULT_TIERS;
            foreach ($tiers as $position => $tier) {
                VolumePresetTier::create([
                    'volume_preset_id' => $preset->id,
                    'position' => $position,
                    'min_quantity' => $tier['min_quantity'],
                    'price_cents' => $tier['price_cents'],
                ]);
            }

            return $preset;
        });
    }

    /**
     * Resolve the effective preset for a gallery.
     *
     * A gallery may reference a preset explicitly; otherwise the brand default applies.
     */
    public function resolveForGallery(?Gallery $gallery): VolumePreset
    {
        if ($gallery !== null && $gallery->volume_preset_id !== null) {
            $preset = VolumePreset::find($gallery->volume_preset_id);
            if ($preset !== null) {
                return $preset;
            }
        }

        $brand = $gallery?->brand ?? BrandRegistry::currentOrDefault();
        return $this->ensureDefaultPresetForBrand($brand);
    }

    /**
     * Resolve the brand-wide default preset (used for public display and
     * single-strategy binding).
     */
    public function resolveDefaultForBrand(Brand|string $brand): VolumePreset
    {
        return $this->ensureDefaultPresetForBrand($brand);
    }

    /**
     * Read the legacy `srp_*` settings for a brand and shape them into tier rows.
     * Returns null when no tier settings exist (caller falls back to defaults).
     */
    private function migrateLegacySettings(string $brandValue): ?array
    {
        $values = [];
        foreach (['srp_price_per_image_tier1', 'srp_price_per_image_tier2', 'srp_price_per_image_tier3'] as $key) {
            $value = \App\Models\Setting::where('key', $key)->where('brand', $brandValue)->value('value');
            $values[] = $value !== null ? (int) $value : null;
        }
        $threshold1 = \App\Models\Setting::where('key', 'srp_tier_threshold1')->where('brand', $brandValue)->value('value');
        $threshold2 = \App\Models\Setting::where('key', 'srp_tier_threshold2')->where('brand', $brandValue)->value('value');

        // Only migrate when at least one tier price exists; otherwise use defaults.
        if ($values[0] === null && $values[1] === null && $values[2] === null) {
            return null;
        }

        return [
            ['min_quantity' => 0, 'price_cents' => $values[0] ?? 3000],
            ['min_quantity' => $threshold1 !== null ? (int) $threshold1 : 10, 'price_cents' => $values[1] ?? 2500],
            ['min_quantity' => $threshold2 !== null ? (int) $threshold2 : 20, 'price_cents' => $values[2] ?? 2000],
        ];
    }

    /**
     * Create a new preset (brand-scoped to the current host). The first preset
     * created for a brand automatically becomes the default.
     */
    public function create(string $name, array $tiers): VolumePreset
    {
        $brand = BrandRegistry::currentOrDefault()->value;

        return DB::transaction(function () use ($name, $tiers, $brand) {
            $isFirst = VolumePreset::where('brand', $brand)->count() === 0;
            $preset = VolumePreset::create([
                'brand' => $brand,
                'name' => $name,
                'is_default' => $isFirst,
            ]);
            $this->replaceTiers($preset, $tiers);
            return $preset;
        });
    }

    /**
     * Update name + tiers (full replacement of the tier set).
     */
    public function update(VolumePreset $preset, string $name, array $tiers): VolumePreset
    {
        $preset->update(['name' => $name]);
        $this->replaceTiers($preset, $tiers);
        return $preset;
    }

    /**
     * Set the given preset as the brand's default (clearing the previous one).
     */
    public function setDefault(VolumePreset $preset): VolumePreset
    {
        if ($preset->is_default) {
            return $preset;
        }

        VolumePreset::where('brand', $preset->brand)->where('is_default', true)->update(['is_default' => false]);
        $preset->update(['is_default' => true]);
        return $preset;
    }

    /**
     * Delete a preset. Galleries referencing it are reset to the brand default.
     * The default preset cannot be deleted; the last preset cannot be deleted
     * (a default must always exist for checkout).
     */
    public function delete(VolumePreset $preset): void
    {
        $brand = $preset->brand;

        if ($preset->is_default) {
            throw new \InvalidArgumentException('Das Standard-Preset kann nicht gelöscht werden.');
        }

        if (VolumePreset::where('brand', $brand)->count() <= 1) {
            throw new \InvalidArgumentException('Mindestens ein Preset pro Brand muss bestehen.');
        }

        DB::transaction(function () use ($preset) {
            Gallery::where('volume_preset_id', $preset->id)->update(['volume_preset_id' => null]);
            $preset->delete();
        });
    }

    private function replaceTiers(VolumePreset $preset, array $tiers): void
    {
        $preset->tiers()->delete();
        $sorted = collect($tiers)->sortBy(fn ($tier) => (int) $tier['min_quantity'])->values();
        foreach ($sorted as $position => $tier) {
            VolumePresetTier::create([
                'volume_preset_id' => $preset->id,
                'position' => $position,
                'min_quantity' => (int) $tier['min_quantity'],
                'price_cents' => (int) $tier['price_cents'],
            ]);
        }
    }
}
