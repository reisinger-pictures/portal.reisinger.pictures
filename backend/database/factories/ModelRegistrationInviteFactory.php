<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\ModelRegistrationInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModelRegistrationInvite>
 */
class ModelRegistrationInviteFactory extends Factory
{
    protected $model = ModelRegistrationInvite::class;

    public function definition(): array
    {
        return [
            'token' => Str::random(64),
            'email' => fake()->unique()->safeEmail(),
            'label' => null,
            'brand' => Brand::B2B,
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
            'used_at' => null,
            'act_id' => null,
            'customer_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function used(): static
    {
        return $this->state(fn () => ['used_at' => now()]);
    }
}
