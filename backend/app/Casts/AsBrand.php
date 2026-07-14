<?php

namespace App\Casts;

use App\Enums\Brand;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class AsBrand implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        return Brand::tryFrom($value) ?? $value;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Brand) {
            return $value->value;
        }
        return (string) $value;
    }
}
