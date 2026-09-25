<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Org;
use App\Models\Photo;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PurchaseTimeOrganizationInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_historical_delivery_note_stays_with_purchase_org_after_reassignment(): void
    {
        $purchaseOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $purchaseOrg->id]);
        $order = $this->makeDeliveryNote($user, 4200, true, (string) $purchaseOrg->id);

        $user->update(['org_id' => $newOrg->id]);

        $newOrgResult = app(InvoiceService::class)->generateForOrg($newOrg);
        $this->assertFalse($newOrgResult['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);

        $result = app(InvoiceService::class)->generateForOrg($purchaseOrg);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed_orders']);
        $this->assertSame('archived_in_collective', $order->fresh()->status);
        $this->assertDatabaseHas('orders', [
            'status' => 'invoice_created',
            'total_amount' => 4200,
        ]);

        $collectiveOrder = Order::query()->where('status', 'invoice_created')->firstOrFail();
        $collectiveSnapshot = InvoiceSnapshot::query()
            ->where('order_id', $collectiveOrder->id)
            ->firstOrFail();
        $this->assertSame(
            (string) $purchaseOrg->id,
            $collectiveSnapshot->customer_details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY]
        );
    }

    public function test_existing_purchase_org_snapshot_cannot_be_replaced_through_model_update(): void
    {
        $purchaseOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $replacementOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $purchaseOrg->id]);
        $order = $this->makeDeliveryNote($user, 3300, true, (string) $purchaseOrg->id);
        $snapshot = $order->invoiceSnapshot;

        $this->assertNotNull($snapshot);
        $details = $snapshot->customer_details;
        $details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY] = (string) $replacementOrg->id;

        try {
            $snapshot->update(['customer_details' => $details]);
            $this->fail('Replacing purchase-time organization attribution must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Purchase-time organization attribution in an invoice snapshot is immutable.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            (string) $purchaseOrg->id,
            $snapshot->fresh()->customer_details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY]
        );
    }

    public function test_existing_purchase_org_snapshot_cannot_be_removed_through_model_update(): void
    {
        $purchaseOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $purchaseOrg->id]);
        $order = $this->makeDeliveryNote($user, 3400, true, (string) $purchaseOrg->id);
        $snapshot = $order->invoiceSnapshot;

        $this->assertNotNull($snapshot);
        $details = $snapshot->customer_details;
        unset($details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY]);

        try {
            $snapshot->update(['customer_details' => $details]);
            $this->fail('Removing purchase-time organization attribution must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Purchase-time organization attribution in an invoice snapshot is immutable.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            (string) $purchaseOrg->id,
            $snapshot->fresh()->customer_details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY]
        );
    }

    public function test_legacy_snapshot_uses_current_membership_as_explicit_fallback(): void
    {
        $originalOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $originalOrg->id]);
        $order = $this->makeDeliveryNote($user, 3100);

        $user->update(['org_id' => $newOrg->id]);

        $result = app(InvoiceService::class)->generateForOrg($newOrg);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed_orders']);
        $this->assertSame('archived_in_collective', $order->fresh()->status);
    }

    public function test_missing_snapshot_does_not_fall_back_to_current_membership(): void
    {
        $org = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $org->id]);
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'delivery_note',
            'brand' => Brand::B2B,
            'total_amount' => 2300,
        ]);

        $service = app(InvoiceService::class);
        $this->assertSame(0, $service->countOpenDeliveryNotesForOrg($org));
        $result = $service->generateForOrg($org);

        $this->assertFalse($result['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);
    }

    public function test_malformed_customer_details_fails_closed_without_using_current_membership(): void
    {
        $org = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $org->id]);
        $order = $this->makeDeliveryNote($user, 2250);
        DB::table('invoice_snapshots')
            ->where('order_id', $order->id)
            ->update(['customer_details' => '{malformed-json']);

        $service = app(InvoiceService::class);
        $this->assertSame(0, $service->countOpenDeliveryNotesForOrg($org));
        $result = $service->generateForOrg($org);

        $this->assertFalse($result['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);
    }

    public function test_malformed_customer_details_after_reassignment_fails_closed_without_json_query_abort(): void
    {
        $purchaseOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $purchaseOrg->id]);
        $order = $this->makeDeliveryNote($user, 2350, true, (string) $purchaseOrg->id);

        $user->update(['org_id' => $newOrg->id]);

        // This low-level write intentionally simulates corrupted storage. The
        // supported Eloquent model boundary is covered by the replacement and
        // removal regressions above; this fixture must not make candidate
        // selection evaluate SQLite JSON extraction on the malformed value.
        DB::table('invoice_snapshots')
            ->where('order_id', $order->id)
            ->update(['customer_details' => '{malformed-json']);

        $service = app(InvoiceService::class);
        $this->assertSame(0, $service->countOpenDeliveryNotesForOrg($purchaseOrg));
        $this->assertSame(0, $service->countOpenDeliveryNotesForOrg($newOrg));

        $result = $service->generateForOrg($newOrg);

        $this->assertFalse($result['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);
        $this->assertDatabaseMissing('orders', ['status' => 'invoice_created']);
        Mail::assertNothingQueued();
    }

    public function test_present_but_empty_purchase_org_snapshot_fails_closed(): void
    {
        $originalOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $originalOrg->id]);
        $order = $this->makeDeliveryNote($user, 2700, true, '');

        $user->update(['org_id' => $newOrg->id]);

        $service = app(InvoiceService::class);
        $this->assertSame(0, $service->countOpenDeliveryNotesForOrg($newOrg));
        $result = $service->generateForOrg($newOrg);

        $this->assertFalse($result['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);
    }

    public function test_present_but_null_purchase_org_snapshot_fails_closed(): void
    {
        $originalOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $originalOrg->id]);
        $order = $this->makeDeliveryNote($user, 2650, true, null);

        $user->update(['org_id' => $newOrg->id]);

        $result = app(InvoiceService::class)->generateForOrg($newOrg);

        $this->assertFalse($result['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);
    }

    public function test_present_but_whitespace_padded_purchase_org_snapshot_fails_closed(): void
    {
        $originalOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $originalOrg->id]);
        $order = $this->makeDeliveryNote($user, 2625, true, '  '.$originalOrg->id.'  ');

        $user->update(['org_id' => $newOrg->id]);

        $result = app(InvoiceService::class)->generateForOrg($newOrg);

        $this->assertFalse($result['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);
    }

    public function test_present_but_non_string_purchase_org_snapshot_fails_closed(): void
    {
        $originalOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $originalOrg->id]);
        $order = $this->makeDeliveryNote($user, 2600, true, ['unexpected']);

        $user->update(['org_id' => $newOrg->id]);

        $result = app(InvoiceService::class)->generateForOrg($newOrg);

        $this->assertFalse($result['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);
    }

    public function test_purchase_org_snapshot_distinguishes_legacy_from_invalid_uuid(): void
    {
        $legacy = new InvoiceSnapshot([
            'customer_details' => ['name' => 'Legacy customer'],
        ]);
        $invalid = new InvoiceSnapshot([
            'customer_details' => [
                InvoiceSnapshot::PURCHASE_ORG_ID_KEY => 'not-a-uuid',
            ],
        ]);
        $presentNull = new InvoiceSnapshot([
            'customer_details' => [
                InvoiceSnapshot::PURCHASE_ORG_ID_KEY => null,
            ],
        ]);
        $malformed = new InvoiceSnapshot;
        $malformed->setRawAttributes(['customer_details' => '{malformed-json'], true);

        $this->assertFalse($legacy->hasPurchaseOrgSnapshot());
        $this->assertNull($legacy->purchaseOrgId());
        $this->assertTrue($invalid->hasPurchaseOrgSnapshot());
        $this->assertNull($invalid->purchaseOrgId());
        $this->assertTrue($presentNull->hasPurchaseOrgSnapshot());
        $this->assertNull($presentNull->purchaseOrgId());
        $this->assertFalse($malformed->hasPurchaseOrgSnapshot());
        $this->assertNull($malformed->purchaseOrgId());
    }

    public function test_checkout_captures_org_before_reassignment_and_generation_uses_snapshot(): void
    {
        $purchaseOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $gallery = Gallery::withoutSyncingToSearch(
            fn () => Gallery::factory()->create(['is_public' => false])
        );
        $photo = Photo::withoutSyncingToSearch(
            fn () => Photo::factory()->create(['gallery_id' => $gallery->id])
        );
        $useCase = LicenseUseCase::create([
            'name' => 'Web',
            'base_price' => 7500,
            'flatrate_tier' => 'web',
            'brand' => Brand::B2B->value,
        ]);
        $user = User::factory()->create(['org_id' => $purchaseOrg->id]);
        $user->galleries()->attach($gallery->id);

        $request = Request::create('/', 'POST', [
            'items' => [[
                'photoId' => $photo->id,
                'useCaseId' => $useCase->id,
                'tier' => 'web',
            ]],
            'billing_name' => 'Käufer',
            'billing_street' => 'Rechnungsweg 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ]);

        $checkout = new CheckoutService(new ScopeLicensingStrategy);
        $response = $checkout->processCheckout($request, $user, 'stripe');

        $this->assertSame(200, $response->status());
        $order = Order::query()->sole();
        $this->assertSame('delivery_note', $order->status);
        $snapshot = $order->invoiceSnapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(
            (string) $purchaseOrg->id,
            $snapshot->customer_details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY]
        );

        $user->update(['org_id' => $newOrg->id]);

        $this->assertSame(1, app(InvoiceService::class)->countOpenDeliveryNotesForOrg($purchaseOrg));
        $this->assertSame(0, app(InvoiceService::class)->countOpenDeliveryNotesForOrg($newOrg));

        $result = app(InvoiceService::class)->generateForOrg($purchaseOrg);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed_orders']);
    }

    private function makeDeliveryNote(
        User $user,
        int $amount,
        bool $includePurchaseOrg = false,
        mixed $purchaseOrgId = null
    ): Order {
        $details = [
            'name' => $user->name,
            'email' => $user->email,
            'items' => [[
                'photoId' => 'photo-'.$user->id,
                'price' => $amount,
                'tier' => 'web',
            ]],
            'terms' => [],
        ];
        if ($includePurchaseOrg) {
            $details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY] = $purchaseOrgId;
        }

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'delivery_note',
            'brand' => Brand::B2B,
            'total_amount' => $amount,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'brand' => Brand::B2B,
            'customer_details' => $details,
            'total_net' => $amount,
            'total_gross' => $amount,
            'tax_rate' => null,
        ]);

        return $order->fresh(['invoiceSnapshot', 'user']);
    }
}
