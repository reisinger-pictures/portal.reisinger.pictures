<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: `from_name` is a sender display name, not an e-mail address.
 * It must accept a plain string (previously validated as `email`, so a
 * display name could never be saved).
 */
class BrandSettingsFromNameTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($user);
    }

    public function test_from_name_accepts_sender_display_name(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/management/brand-settings/rp', [
                'from_name' => 'Reisinger Foto Team',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.from_name',
            'brand' => 'rp',
            'value' => 'Reisinger Foto Team',
        ]);
    }

    public function test_from_name_rejects_overly_long_value(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/management/brand-settings/rp', [
                'from_name' => str_repeat('a', 256),
            ]);

        $response->assertStatus(422);
    }
}
