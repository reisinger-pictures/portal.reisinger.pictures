<?php
namespace Tests\Feature\Pricing;

use App\Enums\Brand;
use App\Models\Role;
use App\Models\User;
use App\Models\VolumePreset;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VolumePresetControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminToken(): string
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value]));
        return auth('api')->login($superAdmin);
    }

    public function test_super_admin_can_create_update_default_and_delete_preset(): void
    {
        $token = $this->superAdminToken();

        // 1. Create
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/volume-presets', [
                'name' => 'Werbung',
                'tiers' => [
                    ['min_quantity' => 0, 'price_cents' => 5000],
                    ['min_quantity' => 8, 'price_cents' => 4000],
                ],
            ]);
        $res->assertStatus(200);
        $id = $res->json('id');
        $this->assertDatabaseHas('volume_presets', ['id' => $id, 'name' => 'Werbung']);
        $this->assertDatabaseHas('volume_preset_tiers', ['volume_preset_id' => $id, 'min_quantity' => 8, 'price_cents' => 4000]);

        // 2. Update (tiers replaced, sorted by min_quantity)
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/settings/volume-presets/{$id}", [
                'name' => 'Werbung V2',
                'tiers' => [
                    ['min_quantity' => 10, 'price_cents' => 3500],
                    ['min_quantity' => 0, 'price_cents' => 5000],
                ],
            ]);
        $res->assertStatus(200);
        $preset = VolumePreset::with('tiers')->find($id);
        $this->assertSame('Werbung V2', $preset->name);
        $this->assertSame([0, 10], $preset->tiers->pluck('min_quantity')->values()->toArray());

        // 3. Create a second preset and promote it to default
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/volume-presets', [
                'name' => 'Zweit',
                'tiers' => [['min_quantity' => 0, 'price_cents' => 3000]],
            ]);
        $secondId = $res->json('id');
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/settings/volume-presets/{$secondId}/default")
            ->assertStatus(200);
        $this->assertTrue(VolumePreset::find($secondId)->is_default);
        $this->assertFalse($preset->fresh()->is_default);

        // 4. Delete the now non-default preset
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/management/settings/volume-presets/{$id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('volume_presets', ['id' => $id]);
    }

    public function test_list_returns_presets_with_tiers(): void
    {
        $token = $this->superAdminToken();
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/volume-presets', [
                'name' => 'A',
                'tiers' => [['min_quantity' => 0, 'price_cents' => 1000]],
            ])->assertStatus(200);

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/volume-presets');
        $res->assertStatus(200);
        $this->assertTrue($res->json('presets.0.tiers.0.min_quantity') === 0);
    }

    public function test_validation_requires_tiers_and_rejects_empty(): void
    {
        $token = $this->superAdminToken();
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/volume-presets', ['name' => 'X', 'tiers' => []])
            ->assertStatus(422);
    }

    public function test_normal_admin_cannot_manage_presets(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/volume-presets', ['name' => 'Hack', 'tiers' => [['min_quantity' => 0, 'price_cents' => 1]]])
            ->assertStatus(403);
    }

    public function test_admin_can_read_presets_but_not_write(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/volume-presets')
            ->assertStatus(200)
            ->assertJsonPath('presets', []);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/volume-presets', ['name' => 'Hack', 'tiers' => [['min_quantity' => 0, 'price_cents' => 1]]])
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_list_presets(): void
    {
        $this->getJson('/api/management/settings/volume-presets')->assertStatus(401);
    }

    public function test_default_preset_cannot_be_deleted(): void
    {
        BrandRegistry::set(Brand::B2B);
        $default = app(\App\Services\VolumePresetService::class)->ensureDefaultPresetForBrand(Brand::B2B);

        $token = $this->superAdminToken();
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/management/settings/volume-presets/{$default->id}")
            ->assertStatus(422);
    }
}
