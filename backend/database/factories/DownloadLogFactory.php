<?php

namespace Database\Factories;

use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DownloadLog>
 */
class DownloadLogFactory extends Factory
{
    public function definition(): array
    {
        $itemType = $this->faker->randomElement(['single_image', 'full_zip']);

        return [
            'user_id' => User::factory(),
            'gallery_id' => Gallery::factory(),
            'item_type' => $itemType,
            'resolution_tier' => $this->faker->randomElement(['web', 'print', 'original']),
            'photo_count' => $itemType === 'full_zip'
                ? $this->faker->numberBetween(2, 50)
                : 1,
            'payload' => $itemType === 'full_zip'
                ? ['photo_ids' => $this->faker->randomElements(
                      array_map(fn () => $this->faker->uuid(), range(1, 5)),
                      $this->faker->numberBetween(2, 5),
                  )]
                : ['photo_id' => $this->faker->uuid()],
        ];
    }

    public function singleImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_type' => 'single_image',
            'photo_count' => 1,
        ]);
    }

    public function fullZip(int $photoCount = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'item_type' => 'full_zip',
            'photo_count' => $photoCount,
        ]);
    }
}
