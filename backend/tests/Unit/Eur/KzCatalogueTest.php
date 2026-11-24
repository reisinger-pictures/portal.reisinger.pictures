<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Reference\KzCatalogue;
use PHPUnit\Framework\TestCase;

final class KzCatalogueTest extends TestCase
{
    public function test_expense_codes_match_the_pinned_chart_of_accounts(): void
    {
        $codes = (new KzCatalogue)->expenseCodes();

        $this->assertCount(27, $codes);
        $this->assertSame([
            '9100', '9110', '9120', '9130', '9134', '9135', '9150', '9160',
            '9165', '9170', '9180', '9190', '9200', '9210', '9220', '9275',
            '9225', '9243', '9244', '9245', '9246', '9230', '9500', '9510',
            '9520', '9521', '9530',
        ], $codes);
        $this->assertContains('9225', $codes);
        $this->assertContains('9275', $codes);
    }

    public function test_income_codes_match_the_pinned_chart_of_accounts(): void
    {
        $this->assertSame(
            ['9040', '9050', '9060', '9090', '9460'],
            (new KzCatalogue)->incomeCodes(),
        );
    }

    public function test_9135_is_shared_between_e1a_and_e1b(): void
    {
        $catalogue = new KzCatalogue;
        $e1b = $catalogue->e1bCodes();

        $this->assertSame(['9500', '9135', '9510', '9520', '9521', '9530'], $e1b);
        $this->assertContains('9135', $e1b);
        $this->assertContains('9135', $catalogue->expenseCodes());
        $this->assertNotContains('9460', $catalogue->expenseCodes());
    }
}
