<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use App\Models\ModelProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stable, version-independent projections of a ModelProfile used by the admin
 * model search (categories / act types / brand). ModelProfile is intentionally
 * not Searchable — these accessors are the DB-level contract.
 */
class ModelProfileAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_are_derived_from_the_answers_snapshot(): void
    {
        $customer = Customer::factory()->create(['brand' => Brand::B2B, 'is_model' => true]);

        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [
                ['scope' => 'person', 'key' => 'experience_portrait', 'label' => 'Erfahrung: Portrait', 'type' => 'select', 'value' => '-'],
                ['scope' => 'person', 'key' => 'experience_bikini', 'label' => 'Erfahrung: Bikini', 'type' => 'select', 'value' => '+'],
                ['scope' => 'person', 'key' => 'experience_akt', 'label' => 'Erfahrung: Akt', 'type' => 'select', 'value' => '--'],
            ],
            'gender' => 'female',
            'submitted_at' => now(),
        ]);

        $this->assertSame(['portrait', 'bikini'], $profile->categories());
        $this->assertSame('rp', $profile->brandValue());
    }

    public function test_act_types_collects_distinct_memberships(): void
    {
        $customer = Customer::factory()->create(['brand' => Brand::B2B, 'is_model' => true]);
        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'submitted_at' => now(),
        ]);

        foreach (['single', 'couple'] as $type) {
            $act = Act::create([
                'brand' => 'rp',
                'manager_customer_id' => $customer->id,
                'act_type' => $type,
                'catalog_version' => 'v1',
                'answers' => [],
                'person_count' => 1,
                'submitted_at' => now(),
            ]);
            ActMember::create([
                'act_id' => $act->id,
                'customer_id' => $customer->id,
                'role' => 'manager',
                'position' => 0,
            ]);
        }

        $actTypes = $profile->actTypes();
        sort($actTypes);
        $this->assertSame(['couple', 'single'], $actTypes);
    }
}
