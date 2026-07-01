<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_user_can_list_own_orders(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $order = Order::factory()->create(['user_id' => $user->id]);
        // Another user's order should not appear
        Order::factory()->create();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['id' => $order->id]);
    }

    public function test_user_order_list_includes_invoice_snapshot(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $order = Order::factory()->create(['user_id' => $user->id]);
        $snapshot = InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => ['name' => 'Test'],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonStructure([['id', 'invoice_snapshot']]);
    }

    public function test_admin_can_list_all_orders(): void
    {
        $admin = $this->createUserWithRole(UserRole::ADMIN->value);
        $token = auth('api')->login($admin);

        Order::factory()->count(3)->create();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/management/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    public function test_regular_user_cannot_list_all_orders(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/management/orders');

        $response->assertStatus(403);
    }

    public function test_photographer_cannot_list_all_orders(): void
    {
        $photographer = $this->createUserWithRole(UserRole::PHOTOGRAPHER->value);
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/management/orders');

        $response->assertStatus(403);
    }

    public function test_admin_can_update_order_status(): void
    {
        $admin = $this->createUserWithRole(UserRole::ADMIN->value);
        $token = auth('api')->login($admin);

        $order = Order::factory()->create(['status' => 'pending']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/management/orders/{$order->id}/status", [
                'status' => 'paid',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
        ]);
    }

    public function test_admin_cannot_set_invalid_status(): void
    {
        $admin = $this->createUserWithRole(UserRole::ADMIN->value);
        $token = auth('api')->login($admin);

        $order = Order::factory()->create(['status' => 'pending']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/management/orders/{$order->id}/status", [
                'status' => 'nonexistent_status',
            ]);

        $response->assertStatus(422);
    }

    public function test_regular_user_cannot_update_order_status(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $order = Order::factory()->create(['status' => 'pending']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/management/orders/{$order->id}/status", [
                'status' => 'paid',
            ]);

        $response->assertStatus(403);
    }

    public function test_order_index_is_sorted_by_date_descending(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $oldOrder = Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(2),
        ]);
        $newOrder = Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/orders');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertEquals([$newOrder->id, $oldOrder->id], $ids);
    }

    public function test_unauthenticated_user_cannot_access_orders(): void
    {
        $response = $this->getJson('/api/orders');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_access_admin_orders(): void
    {
        $response = $this->getJson('/api/management/orders');
        $response->assertStatus(401);
    }

    public function test_super_admin_can_generate_manual_invoice(): void
    {
        $superAdmin = $this->createUserWithRole(UserRole::SUPER_ADMIN->value);
        $token = auth('api')->login($superAdmin);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'BIC12345']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/management/invoices/manual', [
                'invoice_number' => 'RE-2026-0001',
                'date' => '2026-01-15',
                'due_date' => '2026-02-15',
                'type' => 'invoice',
                'customer_name' => 'Test Customer',
                'customer_street' => 'Teststr. 1',
                'customer_zip' => '1010',
                'customer_city' => 'Wien',
                'customer_country' => 'AT',
                'customer_email' => 'customer@test.at',
                'items' => [
                    [
                        'type' => 'item',
                        'description' => 'Fotografie Leistung',
                        'price' => 120000,
                        'qty' => 1,
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_non_super_admin_cannot_generate_manual_invoice(): void
    {
        $admin = $this->createUserWithRole(UserRole::ADMIN->value);
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/management/invoices/manual', [
                'invoice_number' => 'RE-2026-0002',
                'date' => '2026-01-15',
                'due_date' => '2026-02-15',
                'items' => [
                    [
                        'type' => 'item',
                        'description' => 'Test',
                        'price' => 1000,
                        'qty' => 1,
                    ],
                ],
            ]);

        $response->assertStatus(403);
    }
}
