<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelPhoto>
 */
class ModelPhotoFactory extends Factory
{
    protected $model = ModelPhoto::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'model_profile_id' => ModelProfile::factory(),
            'path' => 'model-photos/'.bin2hex(random_bytes(8)).'.enc',
            'original_name' => $this->faker->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => $this->faker->numberBetween(1000, 500000),
            'visibility' => ModelPhoto::VISIBILITY_INTERNAL,
            'is_primary' => false,
            'position' => 0,
        ];
    }
}
