<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\InvalidateWatermarkCacheJob;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\VolumePresetService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return auth('api')->login($user);
    }

    private function superAdminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($user);
    }

    private function clientToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        return auth('api')->login($user);
    }

    public function test_get_license_terms_is_public(): void
    {
        Setting::updateOrCreate(
            ['key' => 'base_price', 'brand' => 'rp'],
            ['value' => '500']
        );

        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonStructure([
                'editorial', 'commercial', 'base_price', 'mult_commercial',
                'mult_unlimited', 'mult_international',
            ]);
    }

    public function test_get_license_terms_volume_pricing_uses_brand_default_preset(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);

        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'volume_licensing')
            ->assertJsonPath('volume_pricing.preset_name', 'Standard')
            ->assertJsonCount(3, 'volume_pricing.tiers')
            ->assertJsonPath('volume_pricing.tiers.1.min_quantity', 10);
    }

    public function test_get_license_terms_volume_pricing_resolves_gallery_preset(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);

        $presetService = app(VolumePresetService::class);
        $custom = $presetService->create('Custom', [
            ['min_quantity' => 0, 'price_cents' => 7000],
        ]);
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $custom->id,
        ]);

        $this->getJson('/api/settings/license-terms?gallery_id='.$gallery->id)
            ->assertStatus(200)
            ->assertJsonPath('volume_pricing.preset_id', $custom->id)
            ->assertJsonPath('volume_pricing.tiers.0.price_cents', 7000);
    }

    public function test_get_license_terms_volume_pricing_null_for_scope(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'scope_licensing']);

        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'scope_licensing')
            ->assertJsonPath('volume_pricing', null);
    }

    public function test_get_billing_details_requires_auth(): void
    {
        $this->getJson('/api/settings/billing-details')
            ->assertStatus(401);
    }

    public function test_authenticated_user_can_read_billing_details(): void
    {
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test GmbH']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT11 2222 3333 4444 5555']);

        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/settings/billing-details')
            ->assertStatus(200)
            ->assertJsonPath('bank_holder', 'Test GmbH')
            ->assertJsonPath('bank_iban', 'AT11 2222 3333 4444 5555');
    }

    public function test_non_admin_cannot_update_license_terms(): void
    {
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'mult_commercial' => 2.0,
                'mult_unlimited' => 1.5,
                'mult_international' => 1.5,
            ])
            ->assertStatus(403);
    }

    public function test_non_super_admin_cannot_update_billing_details(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_holder' => 'Hacker GmbH',
            ])
            ->assertStatus(403);
    }

    public function test_super_admin_can_update_billing_details(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_holder' => 'Berechtigt GmbH',
                'bank_iban' => 'AT99 8888 7777 6666 5555',
                'company_city' => 'Wien',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', ['key' => 'bank_holder', 'value' => 'Berechtigt GmbH']);
    }

    public function test_admin_can_read_system_info(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/system')
            ->assertStatus(200)
            ->assertJsonStructure([
                'laravel_build_time', 'php_version', 'laravel_version', 'db_version',
            ]);
    }

    public function test_system_info_build_time_refreshes_after_cache_clear(): void
    {
        // Regression (2026-08-17): the build time was cached with
        // rememberForever and had NO invalidation path — the value froze at the
        // first request and survived rclone syncs and container restarts.
        // The reset now happens via `php artisan cache:clear` in the backend
        // command block (every container start).
        Cache::put('laravel_build_time', now()->subDays(30)->getTimestamp());
        $token = $this->adminToken();

        // Cache hit: the stale value is served while the key exists.
        $cached = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/system')
            ->assertStatus(200)
            ->json('laravel_build_time');
        $this->assertSame(now()->subDays(30)->getTimestamp(), strtotime($cached));

        // Container start runs `php artisan cache:clear` — same effect here.
        Cache::forget('laravel_build_time');

        // Recomputed from the newest PHP file mtime — no longer frozen.
        $fresh = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/system')
            ->assertStatus(200)
            ->json('laravel_build_time');
        $this->assertGreaterThan(now()->subDays(30)->getTimestamp(), strtotime($fresh));
    }

    public function test_update_license_terms_requires_mult_fields(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'base_price' => 1000,
            ])
            ->assertStatus(422);
    }

    public function test_watermark_update_dispatches_retryable_cache_invalidation_job(): void
    {
        Queue::fake();
        $this->useTemporaryStorageDisk('photos');
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/management/settings/watermark', [
                'opacity' => 0.5,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        Queue::assertPushed(InvalidateWatermarkCacheJob::class, function (InvalidateWatermarkCacheJob $job): bool {
            return $job->tries === 3
                && $job->backoff === [30, 60, 120];
        });
    }

    public function test_watermark_cache_cleanup_throws_and_records_terminal_failure(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('directories')
            ->once()
            ->andReturn(['550e8400-e29b-41d4-a716-446655440000']);
        $disk->shouldReceive('deleteDirectory')
            ->twice()
            ->andReturn(false);
        Storage::set('photos', $disk);
        Log::spy();

        $job = new InvalidateWatermarkCacheJob;

        try {
            $job->handle();
            $this->fail('Expected watermark cache deletion to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Failed to delete 2 watermark cache directories',
                $exception->getMessage(),
            );
            $job->failed($exception);
        }

        Log::shouldHaveReceived('error')
            ->twice()
            ->withArgs(function (string $message, array $context): bool {
                return in_array($message, [
                    'Automated cleanup: watermark cache deletion failures',
                    'Queue job failed',
                ], true)
                    && ($context['failed_count'] ?? null) === 2
                    && count($context['failures'] ?? []) === 2;
            });
    }

    public function test_get_watermark_requires_management_role(): void
    {
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/watermark')
            ->assertStatus(403);
    }
}
