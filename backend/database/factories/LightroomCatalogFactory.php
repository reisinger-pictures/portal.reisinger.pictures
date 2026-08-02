<?php

namespace Database\Factories;

use App\Models\LightroomCatalog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LightroomCatalog>
 */
class LightroomCatalogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->date('Y-m'),
            'position' => 0,
        ];
    }
}
