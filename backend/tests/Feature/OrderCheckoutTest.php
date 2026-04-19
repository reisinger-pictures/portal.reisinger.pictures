<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderCheckoutTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'company_street'], ['value' => 'Teststreet 1']);
        
        \Illuminate\Support\Facades\DB::table('license_use_cases')->insert([
            ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Tageszeitung', 'base_price' => 8000, 'flatrate_tier' => 'print']
        ]);
        \Illuminate\Support\Facades\DB::table('license_modifiers')->insert([
            ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Titelseite', 'percent_surcharge' => 100.00, 'is_included_in_flatrate' => true],
            ['id' => '33333333-3333-3333-3333-333333333333', 'name' => 'Weltweit', 'percent_surcharge' => 50.00, 'is_included_in_flatrate' => false]
        ]);
    }

    public function test_checkout_calculates_delta_pricing_with_flatrate_and_modifiers() {
        $user = User::factory()->create(['flatrate_level' => 'print']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'power_user']));
        $token = auth('api')->login($user);

        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->withHeaders(['Authorization' => "Bearer " . $token])
                         ->postJson("/api/orders/checkout", [
                             'items' => [
                                 [
                                     'photoId' => $photo->id, 
                                     'tier' => 'print',
                                     'useCaseId' => '11111111-1111-1111-1111-111111111111', 
                                     'modifierIds' => ['22222222-2222-2222-2222-222222222222', '33333333-3333-3333-3333-333333333333']
                                 ]
                             ],
                             'billing_name' => 'Test', 'billing_street' => 'Str 1', 'billing_zip' => '1234', 'billing_city' => 'City', 'withdrawal_waived' => true
                         ]);

        $response->assertStatus(200);
        $orderId = $response->json('order_id');
        
        // Berechnung:
        // Use Case = 80€ (Flatrate 'print' >= 'print', also Basis = 0€)
        // Mod 1 (Titel) = +100% (80€). Da is_included_in_flatrate = true, bleibt es 0€.
        // Mod 2 (Weltweit) = +50% (40€). is_included_in_flatrate = false, also +40€.
        // Total = 40.00
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'total_amount' => 4000]);
    }
}
