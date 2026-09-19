<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\Act;
use App\Models\Customer;
use App\Services\ModelQuestionnaire;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Act>
 */
class ActFactory extends Factory
{
    protected $model = Act::class;

    public function definition(): array
    {
        return [
            'brand' => Brand::B2B,
            'manager_customer_id' => Customer::factory(),
            'act_type' => 'single',
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [],
            'person_count' => 1,
            'submitted_at' => now(),
        ];
    }
}
