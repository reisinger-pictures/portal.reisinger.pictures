<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['web', 'print', 'original']),
            'name' => $this->faker->words(2, true),
            'price' => $this->faker->numberBetween(500, 30000),
        ];
    }

    public function tier(string $tier): static
    {
        return $this->state(fn (array $attributes) => ['type' => $tier]);
    }
}
