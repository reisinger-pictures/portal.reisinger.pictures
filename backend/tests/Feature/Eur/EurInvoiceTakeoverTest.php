<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Enums\Brand;
use App\Mail\ContractClosedMail;
use App\Mail\InvoiceMail;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\EurAuditLog;
use App\Models\EurEntry;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Org;
use App\Models\User;
use App\Services\ContractCloseService;
use App\Services\Eur\EurInvoiceTakeoverData;
use App\Services\Eur\EurInvoiceTakeoverService;
use App\Services\InvoiceMailDispatcher;
use App\Services\InvoiceService;
use App\Support\BrandRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;

class EurInvoiceTakeoverTest extends EurBookkeepingTestCase
{
    private function data(
        string $externalId = 'invoice:P-2026-0001-0001',
        int $amountCents = 12345,
        string $brand = 'rp',
        bool $optedOut = false,
    ): EurInvoiceTakeoverData {
        return new EurInvoiceTakeoverData(
            externalId: $externalId,
            source: EurInvoiceTakeoverData::SOURCE_ORDER_INVOICE,
            invoiceNumber: substr($externalId, strlen('invoice:')),
            amountCents: $amountCents,
            year: 2026,
            month: 5,
            day: 4,
            brand: $brand,
            optedOut: $optedOut,
        );
    }

    public function test_replay_is_a_noop_and_a_conflict_is_audited_without_overwriting(): void
    {
        $service = app(EurInvoiceTakeoverService::class);
        $data = $this->data();

        $first = $service->takeover($data);
        $replay = $service->takeover($data);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $replay?->id);
        $this->assertDatabaseCount('eur_entries', 1);
        $this->assertDatabaseHas('eur_entries', [
            'id' => $first->id,
            'external_id' => 'invoice:P-2026-0001-0001',
            'receipt_no' => 'P-2026-0001-0001',
            'amount_cents' => 12345,
            'business_share' => 100,
            'business_amount_cents' => 12345,
            'direction' => 'income',
            'e1a_code' => '9040',
        ]);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_takeover_created')->count());
        $this->assertSame(0, EurAuditLog::where('action', 'invoice_takeover_conflict')->count());

        $conflict = new EurInvoiceTakeoverData(
            externalId: $data->externalId,
            source: EurInvoiceTakeoverData::SOURCE_ORDER_INVOICE,
            invoiceNumber: $data->invoiceNumber,
            amountCents: 99999,
            year: 2026,
            month: 5,
            day: 4,
            brand: 'rp',
        );
        $afterConflict = $service->takeover($conflict);

        $this->assertSame($first->id, $afterConflict?->id);
        $this->assertDatabaseCount('eur_entries', 1);
        $this->assertDatabaseHas('eur_entries', ['id' => $first->id, 'amount_cents' => 12345]);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_takeover_conflict')->count());
    }

    public function test_same_external_id_in_two_brands_creates_two_separate_entries(): void
    {
        $service = app(EurInvoiceTakeoverService::class);

        $service->takeover($this->data(amountCents: 1000, brand: 'rp'));
        $service->takeover($this->data(amountCents: 2000, brand: self::OTHER_BRAND));

        $this->assertDatabaseCount('eur_entries', 2);
        $this->assertSame(1, EurEntry::forCurrentBrand()->count());
        $this->assertDatabaseHas('eur_entries', [
            'brand' => 'rp', 'external_id' => 'invoice:P-2026-0001-0001', 'amount_cents' => 1000,
        ]);
        $this->assertDatabaseHas('eur_entries', [
            'brand' => self::OTHER_BRAND, 'external_id' => 'invoice:P-2026-0001-0001', 'amount_cents' => 2000,
        ]);
        $this->assertSame(1, EurAuditLog::where('brand', 'rp')->count());
        $this->assertSame(1, EurAuditLog::where('brand', self::OTHER_BRAND)->count());
    }

    public function test_opt_out_creates_no_entry_but_is_audited(): void
    {
        $entry = app(EurInvoiceTakeoverService::class)->takeover($this->data(optedOut: true));

        $this->assertNull($entry);
        $this->assertDatabaseCount('eur_entries', 0);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_takeover_opted_out')->count());
    }

    public function test_invoice_mail_dispatch_takes_over_once_for_order_and_collective_snapshots(): void
    {
        Mail::fake();
        $user = User::factory()->create(['brand' => 'rp']);

        foreach ([EurInvoiceTakeoverData::SOURCE_ORDER_INVOICE, EurInvoiceTakeoverData::SOURCE_COLLECTIVE_INVOICE] as $index => $source) {
            $number = 'P-2026-0001-'.sprintf('%04d', $index + 10);
            $order = Order::factory()->create([
                'user_id' => $user->id,
                'brand' => 'rp',
                'status' => 'invoice_created',
                'total_amount' => 4321,
            ]);
            InvoiceSnapshot::factory()->create([
                'order_id' => $order->id,
                'invoice_number' => $number,
                'brand' => 'rp',
                'total_net' => 4000,
                'total_gross' => 4321,
                'customer_details' => [
                    'email' => 'kunde@example.com',
                    'items' => [],
                    ...($source === EurInvoiceTakeoverData::SOURCE_COLLECTIVE_INVOICE ? ['is_collective' => true] : []),
                ],
            ]);

            $dispatcher = app(InvoiceMailDispatcher::class);
            $this->assertTrue($dispatcher->queueOnce($order, $user));
            $this->assertFalse($dispatcher->queueOnce($order, $user));
        }

        $this->assertDatabaseCount('eur_entries', 2);
        foreach (['invoice:P-2026-0001-0010', 'invoice:P-2026-0001-0011'] as $externalId) {
            $this->assertDatabaseHas('eur_entries', [
                'external_id' => $externalId,
                'brand' => 'rp',
                'amount_cents' => 4321,
                'business_amount_cents' => 4321,
                'business_share' => 100,
                'direction' => 'income',
            ]);
        }
        $this->assertSame(2, EurAuditLog::where('action', 'invoice_takeover_created')->count());
    }

    public function test_contract_close_takes_over_the_invoice_without_double_booking_on_retry(): void
    {
        Mail::fake();
        BrandRegistry::set(Brand::B2B);

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [[
                'type' => 'item',
                'description' => 'Leistung',
                'notes' => '',
                'qty' => 1,
                'price' => 8500,
            ]],
            'discounts' => [],
            'billing_details' => ['name' => 'Rechnung', 'email' => 'rechnung@example.com'],
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'rechnung@example.com',
            'status' => 'signed',
        ]);

        $service = app(ContractCloseService::class);
        $this->assertSame(ContractCloseService::RESULT_CLOSED, $service->close($contract)['status']);

        $snapshot = InvoiceSnapshot::query()->firstOrFail();
        $this->assertDatabaseHas('eur_entries', [
            'external_id' => 'invoice:'.$snapshot->invoice_number,
            'brand' => 'rp',
            'amount_cents' => 8500,
            'business_share' => 100,
            'business_amount_cents' => 8500,
            'direction' => 'income',
        ]);
        Mail::assertQueued(ContractClosedMail::class, 1);

        $this->assertSame(ContractCloseService::RESULT_ALREADY_CLOSED, $service->close($contract)['status']);
        $this->assertDatabaseCount('eur_entries', 1);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_takeover_created')->count());
    }

    public function test_delivery_note_is_not_booked_and_collective_invoice_books_exactly_once(): void
    {
        Mail::fake();
        $org = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['brand' => 'rp', 'org_id' => $org->id]);

        $order = Order::create([
            'user_id' => $user->id,
            'brand' => 'rp',
            'status' => 'delivery_note',
            'total_amount' => 2500,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'L-2026-0001-0001',
            'brand' => 'rp',
            'customer_details' => [
                'name' => $user->name,
                'email' => $user->email,
                InvoiceSnapshot::PURCHASE_ORG_ID_KEY => (string) $org->id,
                'items' => [],
            ],
            'total_net' => 2500,
            'total_gross' => 2500,
            'tax_rate' => null,
        ]);

        // The non-immediate checkout sends the L- document immediately
        // (CheckoutService::respondBasedOnPayment). It must not create revenue.
        $dispatcher = app(InvoiceMailDispatcher::class);
        $this->assertTrue($dispatcher->queueOnce($order, $user));
        $this->assertFalse($dispatcher->queueOnce($order, $user));
        $this->assertDatabaseCount('eur_entries', 0);
        $this->assertSame(2, EurAuditLog::where('action', 'invoice_takeover_skipped_non_invoice')->count());

        $result = app(InvoiceService::class)->generateForOrg($org);

        $this->assertTrue($result['success']);
        $this->assertSame('archived_in_collective', $order->fresh()->status);
        $collective = InvoiceSnapshot::query()->whereKey($result['invoice_number'])->firstOrFail();
        $this->assertStringStartsWith('P-', $collective->invoice_number);
        $this->assertSame(2500, $collective->total_gross);
        $this->assertDatabaseCount('eur_entries', 1);
        $this->assertDatabaseHas('eur_entries', [
            'external_id' => 'invoice:'.$collective->invoice_number,
            'brand' => 'rp',
            'amount_cents' => 2500,
            'business_share' => 100,
            'business_amount_cents' => 2500,
            'note' => 'Automatische Übernahme: '.EurInvoiceTakeoverData::SOURCE_COLLECTIVE_INVOICE,
        ]);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_takeover_created')->count());
        Mail::assertQueued(InvoiceMail::class, 2);
    }

    public function test_unique_index_fails_closed_when_a_competitor_bypasses_the_lookup(): void
    {
        app(EurInvoiceTakeoverService::class)->takeover($this->data());

        $collision = false;
        try {
            EurEntry::create([
                'brand' => 'rp',
                'year' => 2026,
                'month' => 5,
                'day' => 4,
                'direction' => 'income',
                'kind_type' => 'Dienstleistung',
                'business_share' => 100,
                'amount_cents' => 999,
                'business_amount_cents' => 999,
                'external_id' => 'invoice:P-2026-0001-0001',
            ]);
        } catch (QueryException) {
            $collision = true;
        }

        $this->assertTrue($collision);
        $this->assertDatabaseCount('eur_entries', 1);
        $this->assertDatabaseHas('eur_entries', [
            'external_id' => 'invoice:P-2026-0001-0001',
            'amount_cents' => 12345,
        ]);
    }

    public function test_already_claimed_dispatch_still_takes_over_the_invoice(): void
    {
        Mail::fake();
        $user = User::factory()->create(['brand' => 'rp']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => 'rp',
            'status' => 'invoice_created',
            'total_amount' => 4321,
        ]);
        $snapshot = InvoiceSnapshot::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-0001-0099',
            'brand' => 'rp',
            'total_net' => 4321,
            'total_gross' => 4321,
            'customer_details' => ['email' => 'kunde@example.com', 'items' => []],
        ]);
        $snapshot->claimInvoiceMailDispatch();

        $dispatcher = app(InvoiceMailDispatcher::class);
        $this->assertFalse($dispatcher->queueOnce($order, $user));
        $this->assertDatabaseHas('eur_entries', [
            'external_id' => 'invoice:P-2026-0001-0099',
            'amount_cents' => 4321,
            'business_share' => 100,
        ]);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_takeover_created')->count());

        $this->assertFalse($dispatcher->queueOnce($order, $user));
        $this->assertDatabaseCount('eur_entries', 1);
    }

    public function test_different_source_with_the_same_key_is_a_conflict(): void
    {
        $service = app(EurInvoiceTakeoverService::class);
        $data = $this->data();
        $first = $service->takeover($data);

        $differentSource = new EurInvoiceTakeoverData(
            externalId: $data->externalId,
            source: EurInvoiceTakeoverData::SOURCE_CONTRACT_INVOICE,
            invoiceNumber: $data->invoiceNumber,
            amountCents: $data->amountCents,
            year: $data->year,
            month: $data->month,
            day: $data->day,
            brand: $data->brand,
        );
        $after = $service->takeover($differentSource);

        $this->assertSame($first?->id, $after?->id);
        $this->assertDatabaseCount('eur_entries', 1);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_takeover_conflict')->count());
    }
}
