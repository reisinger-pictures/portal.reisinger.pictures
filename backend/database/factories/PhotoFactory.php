<?php

namespace Database\Factories;

use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PhotoFactory extends Factory
{
    protected $model = Photo::class;

    public function definition(): array
    {
        return [
            'gallery_id' => Gallery::factory(),
            'lr_uuid' => $this->faker->uuid(),
            'width' => 2000,
            'height' => 1333,
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'user_id' => User::factory(),
            'captured_at' => $this->faker->optional()->dateTimeThisYear(),
        ];
    }
}
