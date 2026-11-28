<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Reference\E1aHelpTexts;
use PHPUnit\Framework\TestCase;

/** Ausfüllhilfe ist keine Regelquelle: Text wird unverändert aus dem Artefakt gelesen. */
class E1aHelpTextsTest extends TestCase
{
    public function test_all_rows_come_from_the_pinned_artifact_without_cell_column(): void
    {
        $rows = E1aHelpTexts::all();

        $this->assertGreaterThanOrEqual(28, count($rows));

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^\d{4}$/', $row['kz']);
            $this->assertNotSame('', $row['bezeichnung']);
            $this->assertArrayNotHasKey('zelle', $row);
            $this->assertArrayNotHasKey('cell', $row);
        }
    }

    public function test_d39_and_d40_codes_and_verbatim_text_are_available(): void
    {
        $ergonomics = E1aHelpTexts::forCode('9216');
        $workplace = E1aHelpTexts::forCode('9215');

        $this->assertNotNull($ergonomics);
        $this->assertSame('Ergonomisches Mobiliar', $ergonomics['bezeichnung']);
        $this->assertStringContainsString('höchstens 300 Euro', $ergonomics['hilfetext']);

        $this->assertNotNull($workplace);
        $this->assertSame('Arbeitsplatzpauschale', $workplace['bezeichnung']);
        $this->assertStringContainsString('12.816 Euro (ändert sich jedes Jahr)', $workplace['hilfetext']);
        $this->assertNotNull(E1aHelpTexts::forCode('9217'));
    }

    public function test_unknown_code_has_no_help_text(): void
    {
        $this->assertNull(E1aHelpTexts::forCode('9999'));
    }
}
