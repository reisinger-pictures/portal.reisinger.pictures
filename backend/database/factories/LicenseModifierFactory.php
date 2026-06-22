<?php

namespace Database\Factories;

use App\Models\LicenseModifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicenseModifier>
 */
class LicenseModifierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'percent_surcharge' => $this->faker->randomFloat(2, 0, 150),
            'is_included_in_flatrate' => $this->faker->boolean(30),
        ];
    }
}
