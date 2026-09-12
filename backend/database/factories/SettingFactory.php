<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand' => Brand::B2B,
            'key' => 'test_' . Str::lower(Str::random(12)),
            'value' => $this->faker->word(),
        ];
    }
}
