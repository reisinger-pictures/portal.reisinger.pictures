<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Resources\GalleryGroupResource;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GalleryGroupDataContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_resource_exposes_only_minimal_org_data_and_untouched_update_keeps_pivot(): void
    {
        Cache::flush();

        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $group = GalleryGroup::factory()->create(['brand' => Brand::B2B]);
        $firstOrg = Org::factory()->create([
            'brand' => Brand::B2B,
            'name' => 'Organisation Eins',
        ]);
        $secondOrg = Org::factory()->create([
            'brand' => Brand::B2B,
            'name' => 'Organisation Zwei',
        ]);
        $group->orgs()->attach([$firstOrg->id, $secondOrg->id]);

        $showResponse = $this->actingAs($admin, 'api')
            ->getJson("/api/management/gallery-groups/{$group->id}")
            ->assertOk();

        $showOrgs = $showResponse->json('group.orgs');
        $this->assertCount(2, $showOrgs);
        foreach ($showOrgs as $org) {
            $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($org));
        }
        $this->assertEqualsCanonicalizing(
            [
                ['id' => $firstOrg->id, 'name' => 'Organisation Eins'],
                ['id' => $secondOrg->id, 'name' => 'Organisation Zwei'],
            ],
            $showOrgs,
        );

        $updateResponse = $this->actingAs($admin, 'api')
            ->putJson("/api/management/gallery-groups/{$group->id}", [
                'name' => $group->name,
                'slug' => $group->slug,
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing($showOrgs, $updateResponse->json('group.orgs'));
        $this->assertDatabaseHas('gallery_group_org', [
            'gallery_group_id' => $group->id,
            'org_id' => $firstOrg->id,
        ]);
        $this->assertDatabaseHas('gallery_group_org', [
            'gallery_group_id' => $group->id,
            'org_id' => $secondOrg->id,
        ]);

        $treeResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/management/galleries')
            ->assertOk();
        $treeGroup = collect($treeResponse->json('groups'))->firstWhere('id', $group->id);

        $this->assertNotNull($treeGroup);
        $this->assertEqualsCanonicalizing(
            [$firstOrg->id, $secondOrg->id],
            collect($treeGroup['orgs'])->pluck('id')->all(),
        );
    }

    public function test_group_resource_maps_loaded_org_relation_without_exposing_org_attributes(): void
    {
        $group = GalleryGroup::factory()->create();
        $org = Org::factory()->create([
            'brand' => Brand::B2B,
            'name' => 'Minimal Org',
            'domain' => 'minimal-org.example.test',
        ]);
        $group->orgs()->attach($org->id);

        $resource = (new GalleryGroupResource($group->load('orgs')))->toArray(request());

        $this->assertSame([[
            'id' => $org->id,
            'name' => 'Minimal Org',
        ]], $resource['orgs']);
        $this->assertArrayNotHasKey('domain', $resource['orgs'][0]);
        $this->assertArrayNotHasKey('brand', $resource['orgs'][0]);
    }
}
