<?php

namespace Database\Factories;

use App\Models\PayoutPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutPool>
 */
class PayoutPoolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'month' => $this->faker->numberBetween(1, 12),
            'year' => (int) now()->format('Y'),
            // net_pool_cents etc. haben DB-Defaults; nur bei Bedarf überschreiben (siehe State-Methoden)
        ];
    }

    public function forMonth(int $month, int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'month' => $month,
            'year' => $year,
        ]);
    }

    public function withNetPool(int $cents): static
    {
        return $this->state(fn (array $attributes) => ['net_pool_cents' => $cents]);
    }
}
