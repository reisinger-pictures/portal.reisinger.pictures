<?php

namespace Database\Factories;

use App\Models\LicenseUseCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicenseUseCase>
 */
class LicenseUseCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'base_price' => $this->faker->numberBetween(1000, 50000),
            'flatrate_tier' => $this->faker->randomElement(['none', 'web', 'print', 'original']),
            'is_commercial' => $this->faker->boolean(40),
        ];
    }
}
