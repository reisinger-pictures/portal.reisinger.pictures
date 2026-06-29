<?php
namespace App\Services;

use App\Models\Setting;
use App\Support\BrandRegistry;

class SettingResolver
{
    public function isAtr(): bool
    {
        return BrandRegistry::isAtr();
    }

    public function prefix(string $key): string
    {
        if (!$this->isAtr()) {
            return $key;
        }
        if (str_starts_with($key, 'atr_')) {
            return $key;
        }
        return 'atr_' . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $prefixed = $this->prefix($key);
        $value = Setting::where('key', $prefixed)->value('value');
        if ($value !== null) {
            return $value;
        }
        if ($prefixed !== $key) {
            $fallback = Setting::where('key', $key)->value('value');
            if ($fallback !== null) {
                return $fallback;
            }
        }
        return $default;
    }

    /**
     * Read a setting value WITHOUT applying the brand prefix — i.e. the raw key as stored.
     * Used for keys that are intentionally global (not brand-scoped).
     */
    public function getRaw(string $key): mixed
    {
        return Setting::where('key', $key)->value('value');
    }

    public function set(string $key, mixed $value): void
    {
        $prefixed = $this->prefix($key);
        Setting::updateOrCreate(
            ['key' => $prefixed],
            ['value' => $value]
        );
        if ($prefixed !== $key) {
            Setting::where('key', $key)->delete();
        }
    }

}
