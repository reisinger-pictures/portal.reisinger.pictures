<?php

namespace Tests\Feature\Contract;

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ContractCloseService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MailpitAssertions;
use Tests\TestCase;

#[Group('mailpit')]
class ContractCloseTest extends TestCase
{
    use MailpitAssertions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'RP Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'RPBIC']);
    }

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user->roles()->attach($role);

        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_close_contract_sends_emails_to_signers(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'billing_details' => null,
        ]);

        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'signer1@example.com',
            'status' => 'signed',
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'signer2@example.com',
            'status' => 'joined',
        ]);

        $response = $this->withHeaders($headers)
            ->postJson("/api/management/contracts/{$contract->id}/close");

        $response->assertStatus(200);

        $this->assertMailpitSentTo('signer1@example.com');
        $this->assertMailpitSentTo('signer2@example.com');

        $this->assertMailpitAttachmentExists(
            'signer1@example.com',
            expectedMimeType: 'application/pdf',
        );
    }

    public function test_close_with_items_creates_auto_invoice(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [
                ['type' => 'item', 'description' => 'Test', 'qty' => 1, 'price' => 10000, 'notes' => ''],
            ],
            'billing_details' => [
                'name' => 'Test Kunde',
                'email' => 'kunde@example.com',
            ],
        ]);

        $response = $this->withHeaders($headers)
            ->postJson("/api/management/contracts/{$contract->id}/close");

        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'brand' => 'rp',
            'status' => 'invoice_created',
        ]);

        $this->assertDatabaseHas('invoice_snapshots', [
            'brand' => 'rp',
            'total_gross' => 10000,
        ]);
    }

    public function test_close_scopes_billing_user_resolution_and_order_ownership_to_contract_brand(): void
    {
        Mail::fake();

        $sameBrandUser = User::factory()->create([
            'email' => 'contract-same-brand@example.com',
            'brand' => Brand::B2B,
        ]);
        $foreignBrandUser = User::factory()->create([
            'email' => 'contract-foreign-brand@example.com',
            'brand' => 'srp',
        ]);
        $brandlessUser = User::factory()->create([
            'email' => 'contract-brandless@example.com',
            'brand' => null,
        ]);

        $billingCases = [
            $sameBrandUser->email => $sameBrandUser->id,
            $foreignBrandUser->email => null,
            $brandlessUser->email => null,
        ];

        foreach (array_keys($billingCases) as $email) {
            $contract = Contract::factory()->create([
                'status' => 'active',
                'brand' => Brand::B2B,
                'items' => [
                    ['type' => 'item', 'description' => 'Test', 'qty' => 1, 'price' => 10000],
                ],
                'discounts' => [],
                'billing_details' => [
                    'name' => 'Test Kunde',
                    'email' => $email,
                ],
            ]);

            app(ContractCloseService::class)->close($contract);
        }

        $orders = Order::query()->with('invoiceSnapshot')->get();
        $this->assertCount(3, $orders);

        $ordersByEmail = $orders->keyBy(function (Order $order): string {
            $this->assertInstanceOf(InvoiceSnapshot::class, $order->invoiceSnapshot);

            return (string) ($order->invoiceSnapshot->customer_details['email'] ?? '');
        });

        foreach ($billingCases as $email => $expectedUserId) {
            $order = $ordersByEmail->get($email);
            $this->assertNotNull($order);
            $this->assertSame($expectedUserId, $order->user_id);
            $this->assertSame(Brand::B2B->value, BrandRegistry::normalizeId($order->brand));
            $this->assertInstanceOf(InvoiceSnapshot::class, $order->invoiceSnapshot);
            $this->assertSame($order->id, $order->invoiceSnapshot->order_id);
            $this->assertSame(Brand::B2B->value, BrandRegistry::normalizeId($order->invoiceSnapshot->brand));
        }

        $sameBrandOrder = $ordersByEmail->get($sameBrandUser->email);
        $foreignBrandOrder = $ordersByEmail->get($foreignBrandUser->email);
        $brandlessOrder = $ordersByEmail->get($brandlessUser->email);
        $this->assertNotNull($sameBrandOrder);
        $this->assertNotNull($foreignBrandOrder);
        $this->assertNotNull($brandlessOrder);

        // Invoice and ZIP controllers both resolve orders through this owner
        // predicate; an external billing identity must not become a wildcard.
        $this->assertTrue(
            Order::query()->ownedBy($sameBrandUser)->whereKey($sameBrandOrder->id)->exists()
        );
        foreach ([$foreignBrandUser, $brandlessUser] as $user) {
            $this->assertFalse(
                Order::query()->ownedBy($user)->whereKey($sameBrandOrder->id)->exists()
            );
        }
        foreach ([$sameBrandUser, $foreignBrandUser, $brandlessUser] as $user) {
            $this->assertFalse(
                Order::query()->ownedBy($user)->whereKey($foreignBrandOrder->id)->exists()
            );
            $this->assertFalse(
                Order::query()->ownedBy($user)->whereKey($brandlessOrder->id)->exists()
            );
        }

        $this->actingAs($sameBrandUser, 'api')
            ->getJson("/api/orders/{$sameBrandOrder->id}/invoice")
            ->assertOk();
        foreach ([$foreignBrandUser, $brandlessUser] as $user) {
            $this->actingAs($user, 'api')
                ->getJson("/api/orders/{$sameBrandOrder->id}/invoice")
                ->assertNotFound();
        }
        foreach ([$sameBrandUser, $foreignBrandUser, $brandlessUser] as $user) {
            foreach ([$foreignBrandOrder, $brandlessOrder] as $order) {
                $this->actingAs($user, 'api')
                    ->getJson("/api/orders/{$order->id}/invoice")
                    ->assertNotFound();
            }
        }
    }

    public function test_close_without_items_does_not_create_invoice(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [],
            'discounts' => [],
            'billing_details' => null,
        ]);

        $response = $this->withHeaders($headers)
            ->postJson("/api/management/contracts/{$contract->id}/close");

        $response->assertStatus(200);

        $this->assertDatabaseCount('orders', 0);
    }
}
