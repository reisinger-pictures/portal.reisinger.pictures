<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\ManualInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ManualInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ManualInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ManualInvoiceService();
    }

    public function test_process_items_with_simple_items()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 2, 'price' => 5000], // 2 * 5000 = 10000
            ['type' => 'item', 'description' => 'Service B', 'qty' => 1, 'price' => 3000], // 1 * 3000 = 3000
        ];

        $result = $this->service->processItems($items);

        $this->assertCount(2, $result['items']);
        $this->assertEquals(13000, $result['total']); // 10000 + 3000
    }

    public function test_process_items_with_fixed_discount()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 10000],
            ['type' => 'discount_fixed', 'description' => 'Fixed Discount', 'qty' => 1, 'price' => 2000],
        ];

        $result = $this->service->processItems($items);

        $this->assertCount(2, $result['items']);
        $this->assertEquals(8000, $result['total']); // 10000 - 2000
    }

    public function test_process_items_with_percentage_discount_calculation()
    {
        // Test the formula: $discountAmt = (int) round($runningTotal * ($item['price'] / 10000));
        // With runningTotal = 10000 and discount = 1000 (10%): 10000 * (1000 / 10000) = 1000
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 10000],
            ['type' => 'discount_percent', 'description' => '10% Off', 'qty' => 1, 'price' => 1000], // 10%
        ];

        $result = $this->service->processItems($items);

        $this->assertCount(2, $result['items']);
        $this->assertEquals(9000, $result['total']); // 10000 - 1000
    }

    public function test_process_items_with_multiple_percentage_discounts()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 10000],
            ['type' => 'item', 'description' => 'Service B', 'qty' => 1, 'price' => 5000], // Running total: 15000
            ['type' => 'discount_percent', 'description' => '20% Off', 'qty' => 1, 'price' => 2000], // 20% of 15000 = 3000
        ];

        $result = $this->service->processItems($items);

        $this->assertCount(3, $result['items']);
        $this->assertEquals(12000, $result['total']); // 15000 - 3000
    }

    public function test_discount_percentage_with_rounding_edge_case()
    {
        // Test rounding behavior: 100 * (2500 / 10000) = 25 (should round to 25)
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 100], // Small amount for rounding test
            ['type' => 'discount_percent', 'description' => '25% Off', 'qty' => 1, 'price' => 2500], // 25%
        ];

        $result = $this->service->processItems($items);

        // 100 * 0.25 = 25, rounded to 25
        $this->assertEquals(75, $result['total']); // 100 - 25
    }

    public function test_discount_percentage_with_fractional_rounding_up()
    {
        // Test rounding up: 101 * (2500 / 10000) = 25.25 -> rounds to 25
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 101],
            ['type' => 'discount_percent', 'description' => '25% Off', 'qty' => 1, 'price' => 2500], // 25%
        ];

        $result = $this->service->processItems($items);

        // 101 * 0.25 = 25.25, rounds to 25
        $this->assertEquals(76, $result['total']); // 101 - 25
    }

    public function test_discount_percentage_with_fractional_rounding_down()
    {
        // Test rounding behavior: 103 * (2500 / 10000) = 25.75 -> rounds to 26
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 103],
            ['type' => 'discount_percent', 'description' => '25% Off', 'qty' => 1, 'price' => 2500], // 25%
        ];

        $result = $this->service->processItems($items);

        // 103 * 0.25 = 25.75, rounds to 26
        $this->assertEquals(77, $result['total']); // 103 - 26
    }

    public function test_process_items_with_100_percent_discount()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 10000],
            ['type' => 'discount_percent', 'description' => '100% Off', 'qty' => 1, 'price' => 10000], // 100%
        ];

        $result = $this->service->processItems($items);

        $this->assertEquals(0, $result['total']); // 10000 - 10000 = 0
    }

    public function test_process_items_never_returns_negative_total()
    {
        // Test that max(0, $runningTotal) prevents negative totals
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 100],
            ['type' => 'discount_percent', 'description' => '150% Off', 'qty' => 1, 'price' => 15000], // 150% - should result in negative but be clamped to 0
        ];

        $result = $this->service->processItems($items);

        // 100 - (100 * 1.5) = 100 - 150 = -50, but max(0, -50) = 0
        $this->assertEquals(0, $result['total']);
    }

    public function test_process_items_with_combined_discounts()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 2, 'price' => 5000], // 10000
            ['type' => 'discount_percent', 'description' => '10% Off', 'qty' => 1, 'price' => 1000], // -1000, running: 9000
            ['type' => 'discount_fixed', 'description' => 'Fixed Off', 'qty' => 1, 'price' => 500], // -500, running: 8500
        ];

        $result = $this->service->processItems($items);

        $this->assertCount(3, $result['items']);
        $this->assertEquals(8500, $result['total']); // 10000 - 1000 - 500
    }

    public function test_process_items_with_multiple_items_and_mixed_discounts()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 8000],
            ['type' => 'item', 'description' => 'Service B', 'qty' => 3, 'price' => 2000], // 6000, running: 14000
            ['type' => 'discount_percent', 'description' => '15% Off', 'qty' => 1, 'price' => 1500], // 15% of 14000 = 2100, running: 11900
            ['type' => 'item', 'description' => 'Service C', 'qty' => 1, 'price' => 4000], // running: 15900
            ['type' => 'discount_fixed', 'description' => 'Fixed Discount', 'qty' => 1, 'price' => 1000], // running: 14900
        ];

        $result = $this->service->processItems($items);

        $this->assertCount(5, $result['items']);
        $this->assertEquals(14900, $result['total']);
    }

    public function test_process_items_includes_notes_in_mapped_result()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 1000, 'notes' => 'Optional note'],
            ['type' => 'discount_percent', 'description' => '10% Off', 'qty' => 1, 'price' => 1000, 'notes' => 'Discount note'],
        ];

        $result = $this->service->processItems($items);

        $this->assertEquals('Optional note', $result['items'][0]['notes']);
        $this->assertEquals('Discount note', $result['items'][1]['notes']);
    }

    public function test_process_items_empty_array()
    {
        $result = $this->service->processItems([]);

        $this->assertEquals([], $result['items']);
        $this->assertEquals(0, $result['total']);
    }

    public function test_process_items_price_value_representation()
    {
        // Verify that price is stored as basis points (10000 = 100%, 1000 = 10%)
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 20000],
            ['type' => 'discount_percent', 'description' => '5% Off', 'qty' => 1, 'price' => 500], // 5% = 500 basis points
        ];

        $result = $this->service->processItems($items);

        // 20000 * (500 / 10000) = 20000 * 0.05 = 1000
        $this->assertEquals(19000, $result['total']); // 20000 - 1000
    }

    public function test_process_items_row_total_calculation_for_items()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 5, 'price' => 200],
        ];

        $result = $this->service->processItems($items);

        $this->assertEquals(1000, $result['items'][0]['row_total']); // 5 * 200
    }

    public function test_process_items_row_total_calculation_for_fixed_discount()
    {
        $items = [
            ['type' => 'discount_fixed', 'description' => 'Discount', 'qty' => 1, 'price' => 500],
        ];

        $result = $this->service->processItems($items);

        $this->assertEquals(-500, $result['items'][0]['row_total']);
    }

    public function test_process_items_row_total_calculation_for_percent_discount()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 1000],
            ['type' => 'discount_percent', 'description' => '20% Off', 'qty' => 1, 'price' => 2000],
        ];

        $result = $this->service->processItems($items);

        // 1000 * (2000 / 10000) = 1000 * 0.2 = 200
        $this->assertEquals(-200, $result['items'][1]['row_total']);
    }

    public function test_process_items_discount_percent_item_structure()
    {
        $items = [
            ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 10000],
            ['type' => 'discount_percent', 'description' => '25% Off', 'qty' => 1, 'price' => 2500],
        ];

        $result = $this->service->processItems($items);

        $discountItem = $result['items'][1];

        $this->assertEquals('discount_percent', $discountItem['type']);
        $this->assertEquals('25% Off', $discountItem['filename']);
        $this->assertEquals('custom', $discountItem['tier']);
        $this->assertEquals(1, $discountItem['qty']);
        $this->assertEquals(2500, $discountItem['price']);
        $this->assertEquals(-2500, $discountItem['row_total']);
        $this->assertEquals(2500, $discountItem['calculated_percentage']);
    }
}
