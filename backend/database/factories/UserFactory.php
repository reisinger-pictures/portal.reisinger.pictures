<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'brand' => Brand::B2B,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'created_at' => now()->subDays(2),
        ];
    }

    public function recentAccount(): static
    {
        return $this->state(fn (): array => [
            'created_at' => now(),
        ]);
    }
}
