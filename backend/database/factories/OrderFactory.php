<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => 'pending',
            'is_quote_request' => false,
            'total_amount' => $this->faker->numberBetween(1000, 50000),
        ];
    }

    public function quoteRequest(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_quote_request' => true,
            'status' => 'pending',
            'total_amount' => 0,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'stripe_fee_cents' => $this->faker->numberBetween(50, 500),
        ]);
    }
}
