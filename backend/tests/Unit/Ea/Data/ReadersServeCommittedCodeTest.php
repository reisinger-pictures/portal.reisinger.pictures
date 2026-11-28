<?php

declare(strict_types=1);

namespace Tests\Unit\Ea\Data;

use App\Ea\Parity\RuleValues;
use App\Ea\Reference\DegressiveRules;
use App\Ea\Reference\E1aHelpTexts;
use App\Ea\Reference\KzCatalogue;
use App\Ea\Reference\ReferenceTables;
use PHPUnit\Framework\TestCase;

/**
 * EAR · Issue #74 — die Leser liefern Werte, ohne den Artefaktbaum zu öffnen.
 *
 * Der Test instanziiert alle fünf ehemaligen Artefakt-Leser und prüft einzelne
 * gepinnte Werte. Weil es keinen Pfad zu `features/` mehr gibt (siehe
 * `NoRuntimeCleanroomReadsTest`), können diese Werte nur aus den committeten
 * Datenklassen unter `App\Ea\Data\*` kommen.
 *
 * Framework-/DB-frei wie `CleanRoomParityTest`.
 */
class ReadersServeCommittedCodeTest extends TestCase
{
    public function test_reference_tables_serve_pinned_values(): void
    {
        $tables = ReferenceTables::load();

        $this->assertSame(['day_cents' => 3000, 'night_cents' => 1700], $tables->domesticFlatRates());
        $this->assertSame(3000, $tables->travel()->for('Österreich', 2026)['day_cents']);
        $this->assertSame(808500, $tables->socialInsurance()->maximumMonthlyBaseCents());
        $this->assertSame(1353900, $tables->incomeTax()->taxFreeThresholdCents());
        $this->assertCount(34, $tables->expenseKinds());
    }

    public function test_rule_values_serve_the_pinned_catalogue(): void
    {
        $values = new RuleValues;

        $this->assertSame(808500, $values->canonicalFor('EUE-014', 2026, 'cents'));
        $this->assertNotSame('', $values->ruleLogic('EUE-088'));
        $this->assertContains('USt-Sätze', $values->parameterNames('EUE-086'));
    }

    public function test_kz_catalogue_serves_the_pinned_chart_of_accounts(): void
    {
        $catalogue = (new KzCatalogue)->all();

        $this->assertCount(27, $catalogue['expense_codes']);
        $this->assertSame(27, $catalogue['distinct_expense_count']);
        $this->assertCount(5, $catalogue['income_codes']);
        $this->assertContains('9135', $catalogue['e1b_codes']);
    }

    public function test_help_texts_serve_the_pinned_fill_in_help(): void
    {
        $this->assertGreaterThanOrEqual(28, count(E1aHelpTexts::all()));

        $row = E1aHelpTexts::forCode('9040');

        $this->assertNotNull($row);
        $this->assertSame('Betriebseinnahmen', $row['bezeichnung']);
    }

    public function test_degressive_rules_serve_the_pinned_exceptions(): void
    {
        $this->assertSame(
            ['digitalisation', 'eco', 'health'],
            (new DegressiveRules)->allowedImmaterialExceptions(),
        );
    }
}
