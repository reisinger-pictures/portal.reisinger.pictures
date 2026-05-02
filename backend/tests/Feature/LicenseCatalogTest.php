<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\LicenseUseCase;
use App\Models\LicenseModifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LicenseCatalogTest extends TestCase {
    use RefreshDatabase;

    public function test_super_admin_can_manage_license_catalog() {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($superAdmin);

        // 1. Create Use Case
        $resUC = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->postJson('/api/management/settings/license-use-cases', [
                        'name' => 'Test Case', 'description' => 'Desc', 'base_price' => 10000, 'flatrate_tier' => 'web', 'sort_order' => 1
                    ]);
        $resUC->assertStatus(200);
        $ucId = $resUC->json('id');
        $this->assertDatabaseHas('license_use_cases', ['id' => $ucId, 'name' => 'Test Case']);

        // 2. Create Modifier
        $resMod = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->postJson('/api/management/settings/license-modifiers', [
                        'name' => 'Test Mod', 'description' => 'Mod Desc', 'percent_surcharge' => 50.5, 'is_included_in_flatrate' => true, 'sort_order' => 1
                    ]);
        $resMod->assertStatus(200);
        $modId = $resMod->json('id');
        $this->assertDatabaseHas('license_modifiers', ['id' => $modId, 'is_included_in_flatrate' => 1]);

        // 3. Update Modifier (Verhindert den Bug, den du gemeldet hast!)
        $resUpdateMod = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->putJson("/api/management/settings/license-modifiers/{$modId}", [
                        'name' => 'Updated Mod', 'description' => 'Mod Desc', 'percent_surcharge' => 75.0, 'is_included_in_flatrate' => false, 'sort_order' => 2
                    ]);
        $resUpdateMod->assertStatus(200);
        $this->assertDatabaseHas('license_modifiers', ['id' => $modId, 'name' => 'Updated Mod', 'is_included_in_flatrate' => 0]);

        // 4. Delete
        $this->withHeaders(['Authorization' => "Bearer $token"])->deleteJson("/api/management/settings/license-use-cases/{$ucId}")->assertStatus(200);
        $this->withHeaders(['Authorization' => "Bearer $token"])->deleteJson("/api/management/settings/license-modifiers/{$modId}")->assertStatus(200);
    }

    public function test_normal_admin_cannot_manage_catalog() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->postJson('/api/management/settings/license-use-cases', ['name' => 'Hack', 'base_price' => 1000])
             ->assertStatus(403);
    }
}
