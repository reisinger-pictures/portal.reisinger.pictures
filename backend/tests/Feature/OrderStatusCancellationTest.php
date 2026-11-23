<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTokenFactory;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * The admin status route must keep working for every order history that has no
 * open Mollie attempt — including orders paid before the Mollie migration. The
 * provider-specific cancellation guards live in OrderStatusMollieTest.
 */
class OrderStatusCancellationTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie();
    }

    public function test_order_without_a_mollie_attempt_is_canceled_without_provider_calls(): void
    {
        $orders = [
            Order::factory()->create([
                'brand' => Brand::B2B,
                'status' => 'pending_payment',
            ]),
            Order::factory()->create([
                'brand' => Brand::B2B,
                'status' => 'invoice_created',
            ]),
        ];
        $client = $this->fakeMollie([], retainRequests: true);
        $headers = $this->adminHeaders();

        foreach ($orders as $order) {
            $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'cancelled'], $headers)
                ->assertOk()
                ->assertJson(['success' => true]);

            $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        }

        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCancelledStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'invoice_created' => ['invoice_created'],
            'pending_payment' => ['pending_payment'],
            // `paid` is guarded by a transition rule and covered in OrderStatusPaidTest.
            'overdue' => ['overdue'],
            'disputed' => ['disputed'],
            'refunded' => ['refunded'],
            'delivery_note' => ['delivery_note'],
            'archived_in_collective' => ['archived_in_collective'],
        ];
    }

    #[DataProvider('nonCancelledStatusProvider')]
    public function test_non_cancelled_status_keeps_existing_behavior_without_provider_calls(string $status): void
    {
        $order = Order::factory()->create([
            'brand' => Brand::B2B,
            'status' => 'pending_payment',
        ]);
        $client = $this->fakeMollie([], retainRequests: true);

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => $status], $this->adminHeaders())
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $status]);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_customer_order_index_works_without_a_provider_credential(): void
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $order = Order::factory()->create([
            'user_id' => $admin->id,
            'brand' => Brand::B2B,
            'status' => 'paid',
        ]);

        // Degraded config: a read path must not need a provider key at all.
        $this->disablesMollie();

        $this->getJson('/api/orders', ['Authorization' => 'Bearer '.AuthTokenFactory::forUser($admin)])
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);
    }

    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return ['Authorization' => 'Bearer '.AuthTokenFactory::forUser($admin)];
    }
}
