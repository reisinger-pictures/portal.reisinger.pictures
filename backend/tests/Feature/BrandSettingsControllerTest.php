<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F3 (Step 2): Brand settings overlay endpoint.
 *
 * Covers the 401/403/200 authorization matrix, persistence with the
 * `brand_config.*` key, 422 validation cases, null-reset, merge-precedence,
 * the public brand-config reflecting the override, and no cross-brand leak.
 */
class BrandSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // BrandRegistry caches brand configs in a static property that
        // RefreshDatabase does not reset between tests; clear it so each
        // test builds the config fresh from the (rolled-back) settings table.
        BrandRegistry::clearCache();
    }

    private function tokenFor(string $role): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return auth('api')->login($user);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer $token"];
    }

    private function superAdminToken(): string
    {
        return $this->tokenFor(UserRole::SUPER_ADMIN->value);
    }

    private function adminToken(): string
    {
        return $this->tokenFor(UserRole::ADMIN->value);
    }

    public function test_unauthenticated_cannot_read(): void
    {
        $this->getJson('/api/management/brand-settings')->assertStatus(401);
    }

    public function test_unauthenticated_cannot_write(): void
    {
        $this->putJson('/api/management/brand-settings/rp', ['primary_color' => '#123456'])
            ->assertStatus(401);
    }

    public function test_plain_admin_can_read(): void
    {
        $token = $this->adminToken();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/brand-settings')
            ->assertStatus(200)
            ->assertJsonPath('brands.0.id', 'rp');
    }

    public function test_plain_admin_cannot_write(): void
    {
        $token = $this->adminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => '#123456'])
            ->assertStatus(403);
    }

    public function test_super_admin_can_read(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/brand-settings')
            ->assertStatus(200);
    }

    public function test_super_admin_can_write(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => '#123456'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_persists_override_with_brand_config_key(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => '#123456'])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.primary_color',
            'brand' => 'rp',
            'value' => '#123456',
        ]);
    }

    public function test_invalid_brand_param_is_rejected(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/does-not-exist', ['primary_color' => '#123456'])
            ->assertStatus(422);
    }

    public function test_validation_rejects_bad_hex(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => '#zzz'])
            ->assertStatus(422);
    }

    public function test_validation_rejects_bad_email(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['from_address' => 'not-an-email'])
            ->assertStatus(422);

        // `from_name` is a display name, not an e-mail — it must accept plain
        // strings (see BrandSettingsFromNameTest for the positive case).
        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['from_name' => str_repeat('a', 256)])
            ->assertStatus(422);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['accounting_email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_validation_rejects_bad_url(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['impressum_url' => 'notaurl'])
            ->assertStatus(422);
    }

    public function test_null_resets_override_to_config_default(): void
    {
        $token = $this->superAdminToken();

        // Set an override first.
        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => '#123456'])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.primary_color',
            'brand' => 'rp',
        ]);

        // Reset it via null.
        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => null])
            ->assertStatus(200);

        $this->assertDatabaseMissing('settings', [
            'key' => 'brand_config.primary_color',
            'brand' => 'rp',
        ]);

        // Effective reverts to the config default.
        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/brand-settings')
            ->assertStatus(200)
            ->assertJsonPath('brands.0.effective.primary_color', '#1E5631')
            ->assertJsonPath('brands.0.overrides', []);
    }

    public function test_merge_precedence_db_beats_config(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => '#ABCDEF'])
            ->assertStatus(200);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/brand-settings')
            ->assertStatus(200)
            ->assertJsonPath('brands.0.defaults.primary_color', '#1E5631')
            ->assertJsonPath('brands.0.overrides.primary_color', '#ABCDEF')
            ->assertJsonPath('brands.0.effective.primary_color', '#ABCDEF');
    }

    public function test_features_orgs_boolean_override(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['features' => ['orgs' => false]])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.features.orgs',
            'brand' => 'rp',
            'value' => '0',
        ]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/brand-settings')
            ->assertStatus(200)
            ->assertJsonPath('brands.0.effective.features.orgs', false);
    }

    public function test_public_brand_config_reflects_override(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => '#ABCDEF'])
            ->assertStatus(200);

        $this->withHeaders(['Host' => 'rp.localhost'])
            ->getJson('/api/settings/brand-config')
            ->assertStatus(200)
            ->assertJsonPath('primary_color', '#ABCDEF');
    }

    public function test_no_cross_brand_leak(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['primary_color' => '#123456'])
            ->assertStatus(200);

        // The override is stored exactly under brand 'rp' and nowhere else.
        $this->assertSame(
            1,
            Setting::where('key', 'like', 'brand_config.%')->where('brand', 'rp')->count()
        );
        $this->assertSame(
            0,
            Setting::where('key', 'like', 'brand_config.%')->where('brand', '!=', 'rp')->count()
        );

        // Other configured brands keep their config defaults (no leak).
        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/brand-settings')
            ->assertStatus(200);

        foreach (config('brands', []) as $id => $data) {
            if ($id === 'rp') {
                continue;
            }
            $this->withHeaders(['Host' => $data['hostnames'][0] ?? 'localhost'])
                ->getJson('/api/settings/brand-config')
                ->assertJsonPath('primary_color', $data['primary_color'] ?? '#1E5631');
        }
    }
}
