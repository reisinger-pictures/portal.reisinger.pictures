<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand' => Brand::B2B,
            'owner_id' => User::factory(),
            'status' => ProjectStatus::ANFRAGE->value,
            'position' => 0,
            'client_name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'package' => $this->faker->optional()->word(),
            'price_cents' => 0,
            'payment_status' => 'open',
        ];
    }
}