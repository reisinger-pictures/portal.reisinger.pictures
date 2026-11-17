<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\ActorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mollie\Api\Fake\MockMollieClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * `paid` is download-eligible (PurchaseService::DOWNLOAD_ELIGIBLE_STATUSES), so
 * a manual admin write must not be able to grant paid access for a payment that
 * never happened (finding PAY-3).
 */
class OrderStatusPaidTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie();
    }

    public function test_pending_order_cannot_be_marked_paid(): void
    {
        $order = $this->orderWithStatus('pending');
        $client = $this->fakeMollieWithoutCalls();

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);
        $this->assertStringContainsString('Rechnungskette', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_pending_payment_order_without_a_mollie_attempt_cannot_be_marked_paid(): void
    {
        $order = $this->orderWithStatus('pending_payment');
        $client = $this->fakeMollieWithoutCalls();

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);
        $this->assertStringContainsString('bestätigten Zahlung', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invoiceChainStatusProvider(): array
    {
        return [
            'invoice_created' => ['invoice_created'],
            'overdue' => ['overdue'],
            'delivery_note' => ['delivery_note'],
        ];
    }

    #[DataProvider('invoiceChainStatusProvider')]
    public function test_invoice_chain_status_can_be_marked_paid(string $status): void
    {
        $order = $this->orderWithStatus($status);
        $client = $this->fakeMollieWithoutCalls();

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_paid_order_can_be_rewritten_idempotently(): void
    {
        $order = $this->orderWithStatus('paid');
        $client = $this->fakeMollieWithoutCalls();

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalStatusProvider(): array
    {
        return [
            'refunded' => ['refunded'],
            'disputed' => ['disputed'],
        ];
    }

    /**
     * A refunded or charged-back order proves that the money is no longer
     * there, so these states are rejected before any provider lookup.
     */
    #[DataProvider('terminalStatusProvider')]
    public function test_terminal_status_cannot_be_marked_paid(string $status): void
    {
        $order = $this->orderWithStatus($status);
        $client = $this->fakeMollieWithoutCalls();

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);
        $this->assertStringContainsString('wieder auf paid', $response->json('error'));
        $this->assertStringContainsString('neue Zahlung', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $status]);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_rejected_transition_keeps_download_access_denied(): void
    {
        $order = $this->orderWithStatus('pending');
        $this->fakeMollieWithoutCalls();

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);

        // The finding is about access, not the status string.
        $this->assertFalse(app(PurchaseService::class)->isOrderDownloadEligible($order->fresh()));
    }

    public function test_rejected_transition_does_not_clear_the_purchase_cache(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'pending',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'PAID-GUARD-'.$order->getKey(),
            'brand' => Brand::B2B,
            'customer_details' => [
                'items' => [[
                    'photoId' => 'photo-paid-guard',
                    'tier' => 'original',
                    'price' => 1000,
                ]],
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);
        $this->fakeMollieWithoutCalls();
        $cacheKey = ActorIdentity::purchaseCacheKeyForActor($user, 'photo-paid-guard', 'original');
        $this->assertNotNull($cacheKey);
        Cache::put($cacheKey, true, 3600);

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);

        // Nothing was written, so the snapshot-derived cache must survive; the
        // order's items are the only source the clear would have touched.
        $this->assertTrue(Cache::has($cacheKey));
    }

    private function orderWithStatus(string $status): Order
    {
        return Order::factory()->create([
            'brand' => Brand::B2B,
            'status' => $status,
        ]);
    }

    private function fakeMollieWithoutCalls(): MockMollieClient
    {
        return $this->fakeMollie([], retainRequests: true);
    }

    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return ['Authorization' => 'Bearer '.auth('api')->login($admin)];
    }
}
