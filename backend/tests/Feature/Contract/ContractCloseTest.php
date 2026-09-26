<?php

namespace Tests\Feature\Contract;

use App\Enums\Brand;
use App\Mail\ContractClosedMail;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        Mail::fake();

        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [
                ['type' => 'item', 'description' => 'Test', 'qty' => 1, 'price' => 10000, 'notes' => ''],
            ],
            'discounts' => [],
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

    public function test_close_with_priced_items_and_missing_billing_recipient_still_creates_accounting(): void
    {
        Mail::fake();

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [[
                'type' => 'item',
                'description' => 'Leistung',
                'notes' => '',
                'qty' => 1,
                'price' => 10000,
            ]],
            'discounts' => [],
            'billing_details' => null,
        ]);

        $result = app(ContractCloseService::class)->close($contract);

        $this->assertSame(ContractCloseService::RESULT_CLOSED, $result['status']);
        // The documented auto-invoicing trigger is `total_gross > 0` alone: a
        // priced contract must never close without a receivable.
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoice_snapshots', 1);
        $this->assertDatabaseHas('orders', [
            'status' => 'invoice_created',
            'total_amount' => 10000,
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'closed',
        ]);
    }

    public function test_rolled_back_close_leaves_no_queued_closure_mail_and_retry_queues_once(): void
    {
        Mail::fake();

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [[
                'type' => 'item',
                'description' => 'Leistung',
                'notes' => '',
                'qty' => 1,
                'price' => 10000,
            ]],
            'discounts' => [],
            'billing_details' => null,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'rollback-signer@example.com',
            'status' => 'signed',
        ]);

        $triggered = false;
        ContractSigner::retrieved(function () use (&$triggered): void {
            if ($triggered || DB::transactionLevel() < 1) {
                return;
            }

            $triggered = true;
            throw new \RuntimeException('simulated rollback after queueClosedMail');
        });

        try {
            app(ContractCloseService::class)->close($contract);
            $this->fail('Expected the simulated failure to abort the close transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated rollback after queueClosedMail', $exception->getMessage());
        } finally {
            Event::forget('eloquent.retrieved: '.ContractSigner::class);
        }

        $this->assertTrue($triggered);
        // The mail is dispatched after commit, so a rolled-back attempt must
        // leave no queued job behind.
        Mail::assertNothingQueued();
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoice_snapshots', 0);

        // The successful retry dispatches exactly once.
        $result = app(ContractCloseService::class)->close($contract);
        $this->assertSame(ContractCloseService::RESULT_CLOSED, $result['status']);
        Mail::assertQueued(ContractClosedMail::class, 1);
    }

    public function test_repeated_and_stale_close_callers_create_one_accounting_and_mail_path(): void
    {
        Mail::fake();

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [[
                'type' => 'item',
                'description' => 'Idempotenter Abschluss',
                'notes' => '',
                'qty' => 1,
                'price' => 10000,
            ]],
            'discounts' => [],
            'billing_details' => [
                'name' => 'Rechnung',
                'email' => 'close-idempotent@example.com',
            ],
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'close-idempotent@example.com',
            'status' => 'signed',
        ]);

        // Both callers intentionally hold a model snapshot from before the
        // first close. The second call represents the loser of a concurrent
        // close and must observe the committed conditional transition.
        $firstCaller = Contract::query()->findOrFail($contract->id);
        $secondCaller = Contract::query()->findOrFail($contract->id);
        $service = app(ContractCloseService::class);

        $first = $service->close($firstCaller);
        $second = $service->close($secondCaller);
        $retry = $service->close($firstCaller);

        $this->assertSame(ContractCloseService::RESULT_CLOSED, $first['status']);
        $this->assertSame(ContractCloseService::RESULT_ALREADY_CLOSED, $second['status']);
        $this->assertSame(ContractCloseService::RESULT_ALREADY_CLOSED, $retry['status']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoice_snapshots', 1);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'closed',
        ]);

        $snapshot = InvoiceSnapshot::query()->firstOrFail();
        $this->assertSame($contract->id, $snapshot->customer_details[InvoiceSnapshot::CONTRACT_ID_KEY]);
        Mail::assertQueued(ContractClosedMail::class, 1);
    }

    public function test_repeated_zero_value_close_does_not_requeue_mail(): void
    {
        Mail::fake();

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [],
            'discounts' => [],
            'billing_details' => null,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'zero-value-close@example.com',
            'status' => 'signed',
        ]);

        $service = app(ContractCloseService::class);
        $service->close($contract);
        $service->close($contract);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoice_snapshots', 0);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'closed',
        ]);
        Mail::assertQueued(ContractClosedMail::class, 1);
    }

    public function test_close_serializes_ordered_discounts_into_the_invoice_snapshot(): void
    {
        Mail::fake();

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [[
                'type' => 'item',
                'description' => 'Leistung',
                'notes' => '',
                'qty' => 1,
                'price' => 10000,
            ]],
            'discounts' => [
                ['type' => 'discount_percent', 'description' => '10% Rabatt', 'notes' => '', 'price' => 1000],
                ['type' => 'discount_fixed', 'description' => 'Bonus', 'notes' => '', 'price' => 500],
            ],
            'billing_details' => ['name' => 'Rechnung', 'email' => 'rechnung@example.com'],
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'status' => 'signed',
        ]);

        app(ContractCloseService::class)->close($contract);

        $snapshot = InvoiceSnapshot::query()->latest('created_at')->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(8500, $snapshot->total_gross);
        $this->assertSame([
            'item',
            'discount_percent',
            'discount_fixed',
        ], array_column($snapshot->customer_details['items'], 'type'));
        $this->assertSame([1000, 500], array_column($snapshot->customer_details['discounts'], 'price'));
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
