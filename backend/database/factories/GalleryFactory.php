<?php

namespace Database\Factories;

use App\Models\Gallery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GalleryFactory extends Factory
{
    protected $model = Gallery::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);
        $type = $this->faker->randomElement(['selection', 'delivery']);
        
        return [
            'gallery_group_id' => null,
            'name' => ucfirst($name),
            'slug' => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1, 1000),
            'type' => $type,
            'is_live' => $type === 'delivery' ? $this->faker->boolean(20) : false,
            'is_public' => $type === 'delivery' ? $this->faker->boolean(50) : false,
            'password_hash' => null,
            'allow_client_metadata_edit' => false,
            'apply_metadata_to_photos' => false,
        ];
    }
}
