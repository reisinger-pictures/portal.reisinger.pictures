<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Enums\UserRole;
use App\Models\EurAuditLog;
use App\Models\EurEntry;
use App\Models\EurMonthlyReport;
use App\Services\Eur\EurInvoiceTakeoverData;
use App\Services\Eur\EurInvoiceTakeoverService;
use App\Support\BrandRegistry;

class EurInvoiceCorrectionTest extends EurBookkeepingTestCase
{
    private const URL = '/api/management/eur/entries/invoice-corrections';

    private const INVOICE = 'P-2026-0001-0001';

    private function bookInvoice(int $amountCents = 10000, string $brand = 'rp', string $number = self::INVOICE): EurEntry
    {
        $entry = app(EurInvoiceTakeoverService::class)->takeover(new EurInvoiceTakeoverData(
            externalId: 'invoice:'.$number,
            source: EurInvoiceTakeoverData::SOURCE_ORDER_INVOICE,
            invoiceNumber: $number,
            amountCents: $amountCents,
            year: 2026,
            month: 5,
            day: 4,
            brand: $brand,
        ));
        $this->assertNotNull($entry);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'invoice_number' => self::INVOICE,
            'refund_cents' => 7000,
            'reason' => 'Teilweise erstattet',
            'year' => 2026,
            'month' => 5,
            'day' => 4,
        ], $overrides);
    }

    public function test_partial_refund_is_booked_as_negative_income_and_reduces_net_revenue(): void
    {
        $original = $this->bookInvoice(10000);
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson(self::URL, $this->payload())
            ->assertCreated()
            ->assertJsonPath('entry.amount_cents', -7000)
            ->assertJsonPath('entry.business_amount_cents', -7000)
            ->assertJsonPath('entry.direction', 'income')
            ->assertJsonPath('entry.category', 'Rechnungskorrektur')
            ->assertJsonPath('entry.receipt_no', self::INVOICE)
            ->assertJsonPath('entry.e1a_code', '9040')
            ->assertJsonPath('entry.description', 'Rechnungskorrektur: Teilweise erstattet')
            ->assertJsonPath('entry.external_id', 'invoice-correction:'.self::INVOICE.':7000:2026-5-4')
            ->assertJsonPath('entry.year', 2026)
            ->assertJsonPath('entry.month', 5)
            ->assertJsonPath('entry.day', 4);

        $this->assertDatabaseCount('eur_entries', 2);
        $this->assertDatabaseHas('eur_entries', [
            'id' => $original->id,
            'amount_cents' => 10000,
            'business_amount_cents' => 10000,
        ]);
        $this->assertDatabaseHas('eur_entries', [
            'brand' => BrandRegistry::currentId(),
            'direction' => 'income',
            'amount_cents' => -7000,
            'business_amount_cents' => -7000,
            'business_share' => 100,
            'category' => 'Rechnungskorrektur',
            'kind_type' => 'Dienstleistung',
            'e1a_code' => '9040',
            'receipt_no' => self::INVOICE,
            'description' => 'Rechnungskorrektur: Teilweise erstattet',
            'note' => null,
            'external_id' => 'invoice-correction:'.self::INVOICE.':7000:2026-5-4',
        ]);

        $audit = EurAuditLog::where('action', 'invoice_correction_created')->firstOrFail();
        $this->assertSame('eur_entry', $audit->subject_type);
        $this->assertSame(self::INVOICE, $audit->payload['invoice_number']);
        $this->assertSame(7000, $audit->payload['refund_cents']);
        $this->assertSame(3000, $audit->payload['retained_cents']);
        $this->assertSame(2026, $audit->payload['year']);
        $this->assertSame(5, $audit->payload['month']);
        $this->assertSame(100, $audit->payload['business_share']);

        $totals = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/entries?year=2026')
            ->assertOk()
            ->json('totals');
        $this->assertSame(3000, $totals['income_cents']);
        $this->assertSame(0, $totals['expense_cents']);
    }

    public function test_replay_returns_the_same_entry_without_a_second_row_or_audit(): void
    {
        $this->bookInvoice();

        $first = $this->withHeaders($this->bearer($this->tokenFor()))
            ->postJson(self::URL, $this->payload())
            ->assertCreated()
            ->json('entry.id');
        $second = $this->withHeaders($this->bearer($this->tokenFor()))
            ->postJson(self::URL, $this->payload())
            ->assertCreated()
            ->json('entry.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('eur_entries', 2);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_correction_created')->count());
    }

    public function test_successive_partial_refunds_reduce_the_invoice_down_to_zero(): void
    {
        $this->bookInvoice(10000);
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson(self::URL, $this->payload(['refund_cents' => 7000, 'month' => 5, 'day' => 4]))
            ->assertCreated();
        $this->withHeaders($this->bearer($token))
            ->postJson(self::URL, $this->payload(['refund_cents' => 3000, 'month' => 6, 'day' => 4]))
            ->assertCreated()
            ->assertJsonPath('entry.amount_cents', -3000);

        $this->assertDatabaseCount('eur_entries', 3);
        $this->assertDatabaseHas('eur_entries', [
            'category' => 'Rechnungskorrektur',
            'direction' => 'income',
            'amount_cents' => -3000,
            'business_amount_cents' => -3000,
            'receipt_no' => self::INVOICE,
            'external_id' => 'invoice-correction:'.self::INVOICE.':3000:2026-6-4',
        ]);

        $audits = EurAuditLog::where('action', 'invoice_correction_created')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame(3000, $audits->first()->payload['retained_cents']);
        $this->assertSame(0, $audits->last()->payload['retained_cents']);

        $totals = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/entries?year=2026')
            ->assertOk()
            ->json('totals');
        $this->assertSame(0, $totals['income_cents']);
        $this->assertSame(0, $totals['expense_cents']);
    }

    public function test_refund_beyond_the_remaining_amount_is_rejected(): void
    {
        $this->bookInvoice(10000);
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson(self::URL, $this->payload(['refund_cents' => 7000, 'month' => 5, 'day' => 4]))
            ->assertCreated();
        $this->withHeaders($this->bearer($token))
            ->postJson(self::URL, $this->payload(['refund_cents' => 3000, 'month' => 6, 'day' => 4]))
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson(self::URL, $this->payload(['refund_cents' => 1, 'month' => 7, 'day' => 4]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Die Summe der Rückzahlungen darf den Rechnungsbetrag nicht überschreiten.');

        $this->assertDatabaseCount('eur_entries', 3);
        $this->assertSame(2, EurAuditLog::where('action', 'invoice_correction_created')->count());
    }

    public function test_replay_after_the_target_month_was_closed_is_still_a_noop(): void
    {
        $this->bookInvoice();
        $token = $this->tokenFor();

        $first = $this->withHeaders($this->bearer($token))
            ->postJson(self::URL, $this->payload())
            ->assertCreated()
            ->json('entry.id');

        EurMonthlyReport::create([
            'brand' => BrandRegistry::currentId(),
            'year' => 2026,
            'month' => 5,
            'status' => 'closed',
        ]);

        $second = $this->withHeaders($this->bearer($token))
            ->postJson(self::URL, $this->payload())
            ->assertCreated()
            ->json('entry.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('eur_entries', 2);
        $this->assertSame(1, EurAuditLog::where('action', 'invoice_correction_created')->count());
    }

    public function test_refund_larger_than_the_invoice_is_rejected(): void
    {
        $this->bookInvoice(10000);

        $this->withHeaders($this->bearer($this->tokenFor()))
            ->postJson(self::URL, $this->payload(['refund_cents' => 10001]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('eur_entries', 1);
        $this->assertSame(0, EurAuditLog::where('action', 'invoice_correction_created')->count());
    }

    public function test_zero_refund_fails_validation(): void
    {
        $this->bookInvoice();

        $this->withHeaders($this->bearer($this->tokenFor()))
            ->postJson(self::URL, $this->payload(['refund_cents' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('refund_cents');

        $this->assertDatabaseCount('eur_entries', 1);
    }

    public function test_unknown_invoice_number_is_not_found(): void
    {
        $this->bookInvoice();

        $this->withHeaders($this->bearer($this->tokenFor()))
            ->postJson(self::URL, $this->payload(['invoice_number' => 'P-2026-9999-9999']))
            ->assertNotFound()
            ->assertJsonPath('message', 'Rechnung nicht gefunden.');

        $this->assertDatabaseCount('eur_entries', 1);
    }

    public function test_invoice_of_another_brand_is_not_found(): void
    {
        $this->bookInvoice(10000, self::OTHER_BRAND);

        $this->withHeaders($this->bearer($this->tokenFor()))
            ->postJson(self::URL, $this->payload())
            ->assertNotFound();

        $this->assertSame(0, EurEntry::forCurrentBrand()->count());
        $this->assertDatabaseCount('eur_entries', 1);
    }

    public function test_closed_month_cannot_be_corrected(): void
    {
        $this->bookInvoice();
        EurMonthlyReport::create([
            'brand' => BrandRegistry::currentId(),
            'year' => 2026,
            'month' => 5,
            'status' => 'closed',
        ]);

        $this->withHeaders($this->bearer($this->tokenFor()))
            ->postJson(self::URL, $this->payload())
            ->assertUnprocessable();

        $this->assertDatabaseCount('eur_entries', 1);
        $this->assertSame(0, EurAuditLog::where('action', 'invoice_correction_created')->count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->bookInvoice();

        $this->postJson(self::URL, $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('eur_entries', 1);
    }

    public function test_role_without_accounting_capability_is_forbidden(): void
    {
        $this->bookInvoice();

        $this->withHeaders($this->bearer($this->tokenFor(UserRole::PHOTOGRAPHER->value)))
            ->postJson(self::URL, $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('eur_entries', 1);
    }
}
