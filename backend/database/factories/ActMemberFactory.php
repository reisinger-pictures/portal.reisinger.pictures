<?php

namespace Database\Factories;

use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActMember>
 */
class ActMemberFactory extends Factory
{
    protected $model = ActMember::class;

    public function definition(): array
    {
        return [
            'act_id' => Act::factory(),
            'customer_id' => Customer::factory(),
            'role' => 'member',
            'position' => 0,
        ];
    }
}
