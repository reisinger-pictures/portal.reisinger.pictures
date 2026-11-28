<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Domain\WarehouseReconciliation;
use PHPUnit\Framework\TestCase;

/** EAR · Wareneingang ↔ Ausgabenabgleich (EUE-045 … EUE-047, EUE-129). */
class WarehouseReconciliationTest extends TestCase
{
    /** EUE-045 */
    public function test_match_by_receipt_number(): void
    {
        $result = WarehouseReconciliation::reconcile(
            [['description' => 'Ware A', 'amount_cents' => 1000, 'receipt_no' => 'R-1']],
            [['description' => 'Andere Ware', 'amount_cents' => 1000, 'receipt_no' => 'R-1']],
        );

        $this->assertSame(1000, $result['matched_cents']);
        $this->assertSame(0, $result['unmatched_cents']);
        $this->assertSame('receipt_no', $result['matched'][0]['by']);
        $this->assertTrue($result['all_ok']);
    }

    /** EUE-047 */
    public function test_match_by_description_and_amount(): void
    {
        $result = WarehouseReconciliation::reconcile(
            [['goods_description' => 'Ware A', 'amount_cents' => 1000]],
            [['description' => ' ware  a ', 'amount_cents' => 1000]],
        );

        $this->assertSame(1000, $result['matched_cents']);
        $this->assertSame(0, $result['unmatched_cents']);
        $this->assertSame('description+amount', $result['matched'][0]['by']);
    }

    /** EUE-129 — nicht zugeordnete Ware liefert Cent-Differenz und WEB-Hinweis. */
    public function test_unmatched_cents_and_web_hints(): void
    {
        $result = WarehouseReconciliation::reconcile(
            [
                ['description' => 'Ware A', 'amount_cents' => 1000, 'receipt_no' => 'R-1'],
                ['description' => 'Ware B', 'amount_cents' => 2500],
            ],
            [['description' => 'Ware A', 'amount_cents' => 1000, 'receipt_no' => 'R-1']],
        );

        $this->assertSame(1000, $result['matched_cents']);
        $this->assertSame(2500, $result['unmatched_cents']);
        $this->assertCount(1, $result['unmatched']);
        $this->assertFalse($result['all_ok']);
        $this->assertSame($result['web_hints'], $result['hints']);
        $this->assertSame('WEB', $result['web_hints'][1]['code']);
        $this->assertFalse($result['web_hints'][1]['ok']);
        $this->assertStringContainsString('⚠', $result['web_hints'][1]['message']);
        $this->assertStringContainsString('💡', $result['web_hints'][1]['hint']);
    }

    /** Keine Float-Beträge im Abgleich. */
    public function test_money_is_integer_cents(): void
    {
        $result = WarehouseReconciliation::reconcile(
            [['description' => 'Ware', 'amount_cents' => 1234]],
            [],
        );

        $this->assertIsInt($result['matched_cents']);
        $this->assertIsInt($result['unmatched_cents']);
        $this->assertIsInt($result['unmatched'][0]['cents']);
    }
}
