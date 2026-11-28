<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Services\Eur\EurVatDeclarationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** EAR · Zwei Erklärungsblöcke aus den gepinnten Artefakten (EUE-088). */
class EurVatDeclarationServiceTest extends TestCase
{
    public function test_both_blocks_use_pinned_titles_and_help_text(): void
    {
        $blocks = (new EurVatDeclarationService)->blocks(2026);

        $this->assertSame(['voranmeldung', 'erklaerung'], array_keys($blocks));
        $this->assertSame('1. Umsatzsteuervoranmeldung', $blocks['voranmeldung']['title']);
        $this->assertSame('C2', $blocks['voranmeldung']['title_cell']);
        $this->assertSame('2. Umsatzsteuererklärung', $blocks['erklaerung']['title']);
        $this->assertSame('AB2', $blocks['erklaerung']['title_cell']);
        $this->assertSame('AD4', $blocks['voranmeldung']['year_cell']);
        $this->assertSame(2026, $blocks['voranmeldung']['wirtschaftsjahr']);
        $this->assertSame($blocks['voranmeldung']['text'], $blocks['erklaerung']['text']);
        $this->assertStringContainsString('EUE-088', $blocks['voranmeldung']['text']);
        $this->assertStringContainsString('identischer Kennzahlenstruktur', $blocks['voranmeldung']['text']);
        $this->assertStringContainsString('vat-requirements.md', $blocks['voranmeldung']['source']);
    }

    public function test_a_year_before_the_first_rule_year_is_rejected(): void
    {
        // 2027 hat keine eigene Basis, rechnet aber über den Fallback; erst
        // unterhalb des ältesten Regeljahres gibt es keine Regelbasis.
        $pinned = (new EurVatDeclarationService)->blocks(2026);
        $fallback = (new EurVatDeclarationService)->blocks(2027);

        $this->assertSame(2026, $pinned['voranmeldung']['wirtschaftsjahr']);
        $this->assertSame(2027, $fallback['voranmeldung']['wirtschaftsjahr']);
        $this->assertSame($pinned['voranmeldung']['text'], $fallback['voranmeldung']['text']);

        $this->expectException(InvalidArgumentException::class);

        (new EurVatDeclarationService)->blocks(2023);
    }
}
