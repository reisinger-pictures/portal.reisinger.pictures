<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'street' => fake()->streetAddress(),
            'zip' => fake()->postcode(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'uid' => fake()->optional(0.3)->numerify('ATU########'),
            'birthdate' => $this->faker->date(max: '-18 years'),
            'brand' => Brand::B2B,
        ];
    }
}
