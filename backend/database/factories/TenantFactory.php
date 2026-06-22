<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'domain' => $this->faker->unique()->domainName(),
            'invoice_frequency' => $this->faker->randomElement(['immediate', 'monthly', 'quarterly']),
        ];
    }

    public function immediate(): static
    {
        return $this->state(fn (array $attributes) => ['invoice_frequency' => 'immediate']);
    }
}
