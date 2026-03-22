<?php

namespace Database\Factories;

use App\Models\GalleryGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GalleryGroupFactory extends Factory
{
    protected $model = GalleryGroup::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);
        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1, 1000),
            'is_public' => $this->faker->boolean(),
            'parent_id' => null,
        ];
    }
}
