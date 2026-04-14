<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'company_street'], ['value' => 'Teststreet 1']);
        \App\Models\Setting::updateOrCreate(['key' => 'base_price'], ['value' => '35.00']);
    }

    public function test_checkout_calculates_delta_pricing()
    {
        $user = User::factory()->create(['flatrate_level' => 'print']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'power_user']));
        $token = auth('api')->login($user);

        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->withHeaders(['Authorization' => "Bearer " . $token])
                         ->postJson("/api/orders/checkout", [
                             'items' => [
                                 ['photoId' => $photo->id, 'tier' => 'original', 'usage' => 'editorial', 'duration' => '1_year']
                             ],
                             'billing_name' => 'Test Name',
                             'billing_street' => 'Street 1',
                             'billing_zip' => '1234',
                             'billing_city' => 'City',
                             'withdrawal_waived' => true
                         ]);

        $response->assertStatus(200);
        $orderId = $response->json('order_id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'total_amount' => 70.00, // 35 * 4 (original) - 35 * 2 (print)
            'status' => 'pending_payment'
        ]);
    }

    public function test_checkout_creates_delivery_note_for_tenant_with_collective_invoice()
    {
        $user = User::factory()->create(['flatrate_level' => 'none']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'power_user']));
        
        $tenant = Tenant::create(['name' => 'B2B', 'invoice_frequency' => 'monthly']);
        $user->tenants()->attach($tenant);

        $token = auth('api')->login($user);

        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->withHeaders(['Authorization' => "Bearer " . $token])
                         ->postJson("/api/orders/checkout", [
                             'items' => [
                                 ['photoId' => $photo->id, 'tier' => 'web', 'usage' => 'editorial', 'duration' => '1_year']
                             ],
                             'billing_name' => 'Test Name',
                             'billing_street' => 'Street 1',
                             'billing_zip' => '1234',
                             'billing_city' => 'City',
                             'withdrawal_waived' => true
                         ]);

        $response->assertStatus(200);
        $orderId = $response->json('order_id');
        
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'delivery_note'
        ]);
        
        $this->assertStringStartsWith('L-', $response->json('invoice_number'));
    }
}
