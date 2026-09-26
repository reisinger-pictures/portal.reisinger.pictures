<?php

namespace Tests\Unit\Services;

use App\Services\ContractPricingService;
use App\Support\PersistedMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContractPricingServiceTest extends TestCase
{
    private ContractPricingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ContractPricingService;
    }

    public function test_percentage_metadata_uses_exact_display_percentage_units(): void
    {
        $processed = $this->service->processLines([
            $this->item(10000),
            $this->discount('discount_percent', 1000),
            $this->discount('discount_percent', 1),
        ]);

        $this->assertSame(10, $processed['items'][1]['calculated_percentage']);
        $this->assertSame('0.01', $processed['items'][2]['calculated_percentage']);
        $this->assertIsInt($processed['items'][1]['row_total']);
        $this->assertIsInt($processed['items'][2]['row_total']);
        $this->assertSame(8999, $processed['total']);
    }

    public function test_new_writes_accept_each_input_at_the_safe_integer_boundary(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;

        $snapshot = $this->service->normalizeForWrite(
            [$this->item($maximum, $maximum)],
            [],
        );

        $this->assertSame($maximum, $snapshot['items'][0]['price']);
        $this->assertSame($maximum, $snapshot['items'][0]['qty']);
    }

    public function test_write_preflight_rejects_product_running_total_and_percentage_overflow(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;

        $this->expectException(InvalidArgumentException::class);
        $this->service->preflightForWrite(
            [$this->item($maximum, 2)],
            [],
        );
    }

    public function test_write_preflight_rejects_running_total_overflow(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;

        $this->expectException(InvalidArgumentException::class);
        $this->service->preflightForWrite(
            [$this->item($maximum), $this->item(1)],
            [],
        );
    }

    public function test_write_preflight_rejects_percentage_overflow(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;

        $this->expectException(InvalidArgumentException::class);
        $this->service->preflightForWrite(
            [$this->item($maximum)],
            [$this->discount('discount_percent', $maximum)],
        );
    }

    public function test_write_preflight_enforces_the_persisted_money_ceiling(): void
    {
        $boundary = PersistedMoney::MAX_CENTS;

        $snapshot = $this->service->preflightForWrite([$this->item($boundary)], []);
        $this->assertSame($boundary, $snapshot['items'][0]['price']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->preflightForWrite([$this->item($boundary + 1)], []);
    }

    public function test_max_safe_wire_values_round_trip_for_contract_item_and_discounts(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;
        $snapshot = $this->service->normalizeForWrite(
            [$this->item($maximum)],
            [
                $this->discount('discount_fixed', $maximum),
                $this->discount('discount_percent', $maximum),
            ],
        );

        $roundTripped = $this->service->normalizeSnapshot($snapshot['items'], $snapshot['discounts']);

        $this->assertSame($maximum, $roundTripped['items'][0]['price']);
        $this->assertSame($maximum, $roundTripped['discounts'][0]['price']);
        $this->assertSame($maximum, $roundTripped['discounts'][1]['price']);
    }

    public function test_max_safe_manual_quantity_round_trips_as_fixed_point_units(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;
        $processed = $this->service->processManualInvoiceLines([[
            'type' => ContractPricingService::ITEM_TYPE,
            'description' => 'Leistung',
            'notes' => '',
            'qty' => $maximum,
            'quantity_scale' => ContractPricingService::MANUAL_QUANTITY_SCALE,
            'price' => 100,
        ]]);

        $this->assertSame('90071992547409.91', $processed['items'][0]['qty']);
        $this->assertSame($maximum, $processed['items'][0]['row_total']);
    }

    #[DataProvider('nonCanonicalWriteValueProvider')]
    public function test_new_writes_reject_non_canonical_or_out_of_range_numbers(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalizeForWrite([$this->item($value)], []);
    }

    public static function nonCanonicalWriteValueProvider(): array
    {
        return [
            'integer-like string' => ['1000'],
            'integral float' => [1000.0],
            'above safe maximum' => [ContractPricingService::MAX_SAFE_INTEGER + 1],
        ];
    }

    public function test_legacy_reads_normalize_bounded_integer_strings_and_integral_floats(): void
    {
        $snapshot = $this->service->normalizeSnapshot(
            [[
                'type' => 'item',
                'description' => 'Legacy',
                'notes' => '',
                'qty' => '2',
                'price' => '0010000',
            ]],
            [[
                'type' => 'discount_percent',
                'description' => '10%',
                'price' => 1000.0,
            ]],
        );

        $this->assertSame(10000, $snapshot['items'][0]['price']);
        $this->assertSame(2, $snapshot['items'][0]['qty']);
        $this->assertSame(1000, $snapshot['discounts'][0]['price']);

        $largestRepresentableFloat = (float) ContractPricingService::MAX_SAFE_INTEGER;
        $maximumSnapshot = $this->service->normalizeSnapshot(
            [$this->item($largestRepresentableFloat, 1.0)],
            [],
        );
        $this->assertSame(ContractPricingService::MAX_SAFE_INTEGER, $maximumSnapshot['items'][0]['price']);
        $this->assertSame(1, $maximumSnapshot['items'][0]['qty']);
    }

    #[DataProvider('invalidLegacyValueProvider')]
    public function test_legacy_reads_reject_non_integer_or_out_of_range_values(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalizeSnapshot([$this->item($value)], []);
    }

    public static function invalidLegacyValueProvider(): array
    {
        return [
            'decimal string' => ['1.0'],
            'exponent string' => ['1e3'],
            'out-of-range string' => ['9007199254740992'],
            'fractional float' => [1.5],
            'first unsafe float' => [9007199254740992.0],
        ];
    }

    public function test_ordered_percentage_discounts_keep_half_up_integer_parity(): void
    {
        $processed = $this->service->processLines([
            $this->item(101),
            $this->discount('discount_percent', 2500),
            $this->item(2),
            $this->discount('discount_percent', 2500),
        ]);

        $this->assertSame(58, $processed['total']);
        $this->assertSame(-25, $processed['items'][1]['row_total']);
        $this->assertSame(-20, $processed['items'][3]['row_total']);
    }

    public function test_percentage_discount_can_round_exactly_at_the_safe_boundary(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;
        $processed = $this->service->processLines([
            $this->item($maximum),
            $this->discount('discount_percent', 10000),
        ]);

        $this->assertSame(0, $processed['total']);
        $this->assertSame(-$maximum, $processed['items'][1]['row_total']);
        $this->assertSame(100, $processed['items'][1]['calculated_percentage']);
    }

    public function test_line_product_overflow_is_rejected_before_multiplication(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->processLines([
            $this->item(ContractPricingService::MAX_SAFE_INTEGER, 2),
        ]);
    }

    public function test_running_total_overflow_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->processLines([
            $this->item(ContractPricingService::MAX_SAFE_INTEGER),
            $this->item(1),
        ]);
    }

    public function test_percentage_discount_overflow_is_rejected_without_float_arithmetic(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->processLines([
            $this->item(ContractPricingService::MAX_SAFE_INTEGER),
            $this->discount('discount_percent', ContractPricingService::MAX_SAFE_INTEGER),
        ]);
    }

    public function test_legacy_row_total_matches_integer_arithmetic(): void
    {
        $this->assertSame(10000, ContractPricingService::legacyRowTotal(5000, 2));
        $this->assertSame(10000, ContractPricingService::legacyRowTotal('5000', '2'));
        $this->assertSame(10000, ContractPricingService::legacyRowTotal(5000.0, 2.0));
        $this->assertSame(0, ContractPricingService::legacyRowTotal(0, 5));
    }

    public function test_legacy_row_total_fails_closed_on_a_fractional_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 1.5 must never be silently truncated to 1: the rendered subtotal
        // would then disagree with the authoritative total.
        ContractPricingService::legacyRowTotal(5000, 1.5);
    }

    public function test_legacy_row_total_fails_closed_on_a_fractional_price(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContractPricingService::legacyRowTotal(10.5, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(mixed $price, mixed $qty = 1): array
    {
        return [
            'type' => ContractPricingService::ITEM_TYPE,
            'description' => 'Leistung',
            'notes' => '',
            'qty' => $qty,
            'price' => $price,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function discount(string $type, mixed $price): array
    {
        return [
            'type' => $type,
            'description' => 'Rabatt',
            'notes' => '',
            'price' => $price,
        ];
    }
}
