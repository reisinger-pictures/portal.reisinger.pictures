<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Persists and reads per-brand overrides of brand configuration (F3, Option B — DB-Overlay).
 *
 * Overrides live on the existing `settings` table (PK (key, brand), migration V019).
 * `config/brands.php` stays the default/fallback. Only a fixed whitelist of config
 * keys may be overridden per brand.
 *
 * Key namespace: `brand_config.<config_key>` (e.g. `brand_config.name`,
 * `brand_config.features.orgs`). The `brand` column carries the brand key.
 * Writes use Eloquent `updateOrCreate` directly on the `Setting` model — NOT
 * `SettingResolver::set()`, because that hangs off the host-derived brand context
 * (current request) and must not be used for explicit cross-brand writes.
 */
class BrandSettingsService
{
    /**
     * Config keys (from config/brands.php) that may be overridden per brand.
     * Nested keys use dot-notation (e.g. `features.orgs`).
     */
    public const OVERRIDABLE = [
        'name',
        'portal_name',
        'impressum_url',
        'primary_color',
        'secondary_color',
        'frontend_url',
        'from_address',
        'from_name',
        'accounting_email',
        'features.orgs',
    ];

    private const KEY_PREFIX = 'brand_config.';

    public function settingKey(string $configKey): string
    {
        return self::KEY_PREFIX.$configKey;
    }

    /**
     * Persist (or reset) brand overrides from a partial payload.
     *
     * Only whitelisted keys are accepted; everything else is silently ignored.
     * A `null` value resets that key back to the config default by deleting the
     * override row.
     *
     * @param  string  $brand  brand key from config('brands')
     * @param  array  $payload  partial associative payload (config_key => value|null)
     * @param  bool  $audit  emit an audit log line for changed keys
     * @return string[] the config keys that were actually changed
     */
    public function apply(string $brand, array $payload, bool $audit = true): array
    {
        $changed = [];

        foreach ($payload as $configKey => $value) {
            if (! in_array($configKey, self::OVERRIDABLE, true)) {
                continue;
            }

            $settingKey = $this->settingKey($configKey);

            if ($value === null) {
                Setting::where('key', $settingKey)->where('brand', $brand)->delete();
                $changed[] = $configKey;

                continue;
            }

            Setting::updateOrCreate(
                ['key' => $settingKey, 'brand' => $brand],
                ['value' => $this->normalizeForStorage($configKey, $value)]
            );
            $changed[] = $configKey;
        }

        if ($audit && $changed !== []) {
            Log::info('Brand settings updated', [
                'brand' => $brand,
                'changed' => $changed,
            ]);

            // A brand-settings write must invalidate the memoized brand config
            // (BrandRegistry::loadAllConfigs) so the next read picks up the
            // override within the same process/request.
            BrandRegistry::clearCache();
        }

        return $changed;
    }

    /**
     * Read all DB overrides for a brand as a config_key => value map.
     * Only whitelisted keys are returned; values are cast back to their
     * native PHP type (e.g. `features.orgs` => bool).
     */
    public function overridesFor(string $brand): array
    {
        // Tolerate a missing table (fresh :memory: test DBs without
        // RefreshDatabase, pre-migration states): no overrides then.
        if (!Schema::hasTable('settings')) {
            return [];
        }

        $rows = Setting::query()
            ->where('brand', $brand)
            ->where('key', 'like', self::KEY_PREFIX.'%')
            ->get(['key', 'value']);

        $overrides = [];
        foreach ($rows as $row) {
            $configKey = substr($row->key, strlen(self::KEY_PREFIX));
            if (! in_array($configKey, self::OVERRIDABLE, true)) {
                continue;
            }
            $overrides[$configKey] = $this->castFromStorage($configKey, $row->value);
        }

        return $overrides;
    }

    private function normalizeForStorage(string $configKey, mixed $value): string
    {
        if ($configKey === 'features.orgs') {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function castFromStorage(string $configKey, ?string $value): mixed
    {
        if ($configKey === 'features.orgs') {
            return $value === '1';
        }

        return $value;
    }
}
