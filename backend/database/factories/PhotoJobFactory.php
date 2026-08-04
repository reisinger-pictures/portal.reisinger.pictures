<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Enums\PhotoJobStatus;
use App\Models\PhotoJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhotoJob>
 */
class PhotoJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand' => Brand::B2B,
            'owner_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'lightroom_catalog' => $this->faker->optional()->sentence(2),
            'total_count' => 0,
            'selected_count' => 0,
            'status' => PhotoJobStatus::IMPORTIERT->value,
            'position' => 0,
        ];
    }
}