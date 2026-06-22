<?php

namespace Database\Factories;

use App\Models\PhotographerStatement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhotographerStatement>
 */
class PhotographerStatementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'month' => $this->faker->numberBetween(1, 12),
            'year' => (int) now()->format('Y'),
            'status' => $this->faker->randomElement(['pending', 'rollover', 'approved', 'paid']),
        ];
    }

    public function forMonth(int $userId, int $month, int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'month' => $month,
            'year' => $year,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'pending']);
    }

    public function rollover(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'rollover']);
    }
}
