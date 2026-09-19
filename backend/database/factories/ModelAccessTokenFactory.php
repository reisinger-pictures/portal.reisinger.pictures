<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\ModelAccessToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModelAccessToken>
 */
class ModelAccessTokenFactory extends Factory
{
    protected $model = ModelAccessToken::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'token' => Str::random(64),
            'expires_at' => now()->addHours(ModelAccessToken::TTL_HOURS),
            'created_by' => null,
        ];
    }
}
