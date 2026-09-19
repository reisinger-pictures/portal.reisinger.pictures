<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\ModelProfile;
use App\Services\ModelQuestionnaire;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelProfile>
 */
class ModelProfileFactory extends Factory
{
    protected $model = ModelProfile::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [],
            'gender' => null,
            'age_proof_required' => false,
            'age_proof_path' => null,
            'age_proof_uploaded_at' => null,
            'submitted_at' => now(),
        ];
    }
}
