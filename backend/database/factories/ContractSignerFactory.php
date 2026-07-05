<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractSigner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContractSigner>
 */
class ContractSignerFactory extends Factory
{
    public function definition(): array
    {
        $allRoles = ['Model', 'Visagist', 'Kunde', 'Fotograf', 'Stylist'];

        return [
            'contract_id' => Contract::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'roles' => $this->faker->randomElements($allRoles, $this->faker->numberBetween(1, 2)),
            'personal_token' => Str::random(64),
            'status' => 'joined',
            'signed_at' => null,
        ];
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'invited',
            'signed_at' => null,
        ]);
    }

    public function signed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'signed',
            'signed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ]);
    }
}
