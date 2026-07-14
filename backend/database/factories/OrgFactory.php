<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Org>
 */
class OrgFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'domain' => $this->faker->unique()->domainName(),
            'invoice_frequency' => $this->faker->randomElement(['immediate', 'monthly', 'quarterly']),
            'brand' => Brand::B2B->value,
        ];
    }

    public function immediate(): static
    {
        return $this->state(fn (array $attributes) => ['invoice_frequency' => 'immediate']);
    }


}
