<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\VolumePresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Access contract of the two license endpoints after the access split
 * (owner decisions D-27/D-28):
 *
 * - `GET /api/settings/license-terms` carries the complete price parameter set
 *   and is therefore `auth:api` + `super_admin` (anonymous → 401, wrong role →
 *   403, super admin → 200).
 * - `GET /api/settings/license-pricing` is the deliberately minimized public
 *   endpoint for the buy flow: `pricing_strategy` + `volume_pricing` only, and
 *   nothing else.
 */
class LicenseTermsAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Price parameters that must never appear on the public minimized payload.
     * They are asserted missing by name, not by shape, so a newly added internal
     * key is caught if it leaks into this response.
     */
    private const INTERNAL_PRICE_KEYS = [
        'base_price',
        'calc_base_price',
        'calc_hourly_rate',
        'srp_base_price',
        'srp_setup_fee',
        'srp_privacy_fee',
        'srp_extra_image_fee',
        'mult_commercial',
        'price_web',
        'editorial',
    ];

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    private function tokenFor(UserRole $role): string
    {
        return auth('api')->login($this->userWithRole($role));
    }

    public function test_full_license_terms_is_anonymous_401(): void
    {
        $this->getJson('/api/settings/license-terms')->assertStatus(401);
    }

    public function test_full_license_terms_is_client_403(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor(UserRole::CLIENT)])
            ->getJson('/api/settings/license-terms')
            ->assertStatus(403);
    }

    public function test_full_license_terms_is_admin_403(): void
    {
        // An admin is a management role but not a super admin: the full price
        // parameter set stays closed to it.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor(UserRole::ADMIN)])
            ->getJson('/api/settings/license-terms')
            ->assertStatus(403);
    }

    public function test_full_license_terms_is_super_admin_200(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor(UserRole::SUPER_ADMIN)])
            ->getJson('/api/settings/license-terms')
            ->assertOk()
            ->assertJsonStructure([
                'pricing_strategy', 'volume_pricing', 'base_price',
                'calc_base_price', 'calc_hourly_rate', 'srp_base_price',
                'srp_setup_fee', 'srp_privacy_fee', 'srp_extra_image_fee',
                'mult_commercial', 'price_web', 'editorial',
            ]);
    }

    public function test_public_license_pricing_is_anonymous_200_with_exactly_the_minimal_keys(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'scope_licensing']);
        Setting::updateOrCreate(['key' => 'base_price', 'brand' => 'rp'], ['value' => '8000']);
        Setting::updateOrCreate(['key' => 'calc_base_price', 'brand' => 'rp'], ['value' => '5000']);
        Setting::updateOrCreate(['key' => 'calc_hourly_rate', 'brand' => 'rp'], ['value' => '8000']);
        Setting::updateOrCreate(['key' => 'mult_commercial', 'brand' => 'rp'], ['value' => '2.0']);
        Setting::updateOrCreate(['key' => 'price_web', 'brand' => 'rp'], ['value' => '7500']);
        Setting::updateOrCreate(['key' => 'term_editorial', 'brand' => 'rp'], ['value' => 'Redaktionelle Nutzung']);

        $response = $this->getJson('/api/settings/license-pricing')->assertOk();

        // "Exactly the keys": no third top-level member, in contrast to a
        // structure assertion which would tolerate one.
        $this->assertSame(['pricing_strategy', 'volume_pricing'], array_keys($response->json()));

        foreach (self::INTERNAL_PRICE_KEYS as $key) {
            $response->assertJsonMissingPath($key);
        }
    }

    public function test_both_endpoints_resolve_the_same_gallery_to_the_same_pricing(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);

        $preset = app(VolumePresetService::class)->create('Access Test Preset', [
            ['min_quantity' => 0, 'price_cents' => 6200],
            ['min_quantity' => 5, 'price_cents' => 5000],
        ]);
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $preset->id,
        ]);

        $public = $this->getJson('/api/settings/license-pricing?gallery_id='.$gallery->id)->assertOk();
        $full = $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor(UserRole::SUPER_ADMIN)])
            ->getJson('/api/settings/license-terms?gallery_id='.$gallery->id)
            ->assertOk();

        $this->assertSame('volume_licensing', $public->json('pricing_strategy'));
        $this->assertSame($full->json('pricing_strategy'), $public->json('pricing_strategy'));
        $this->assertSame($full->json('volume_pricing'), $public->json('volume_pricing'));
        $this->assertSame($preset->id, $public->json('volume_pricing.preset_id'));
    }
}
