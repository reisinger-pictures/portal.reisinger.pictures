<?php

declare(strict_types=1);

namespace Tests\Unit\Ea\Data;

use App\Ea\Data\DegressiveRulesData;
use App\Ea\Data\EaDataBuilder;
use App\Ea\Data\HelpTextsData;
use App\Ea\Data\KzCatalogueData;
use App\Ea\Data\ReferenceTablesData;
use App\Ea\Data\RuleCatalogue;
use App\Ea\Data\VatRequirements;
use PHPUnit\Framework\TestCase;

/**
 * EAR · Issue #74 — die committeten Datenklassen müssen den gepinnten
 * Artefakten entsprechen.
 *
 * Framework-/DB-frei. Der Test baut die Zielstrukturen mit DEMSELBEN Builder
 * neu auf, den auch der Generator benutzt; zwei Implementierungen würden nur
 * eine Kopie prüfen. `features/` liegt lokal und in CI vor: fehlt ein Artefakt,
 * ist das ein Fehler (kein Skip) und die Meldung nennt Datei und den Befehl
 * `php artisan ea:export-data`, mit dem die Klassen neu entstehen.
 *
 * Der Test ist der Ersatz dafür, dass eine Regel zur Laufzeit aus dem Artefakt
 * gelesen wird: eine Regeländerung im Artefakt ohne Neugenerierung macht ihn
 * rot.
 */
class CleanroomDataParityTest extends TestCase
{
    public function test_rule_catalogue_matches_the_pinned_catalog_in_both_directions(): void
    {
        $expected = $this->builder()->ruleCatalogue();
        $actual = RuleCatalogue::RULES;

        $this->assertSame(
            array_keys($expected),
            array_keys($actual),
            'Regel-IDs der Datenklasse weichen vom gepinnten Katalog ab — `php artisan ea:export-data` laufen lassen.',
        );

        foreach ($expected as $id => $rule) {
            $this->assertArrayHasKey($id, $actual);
            $this->assertSame($rule['logik'], $actual[$id]['logik'], $id.': logik');
            $this->assertSame($rule['parameter'], $actual[$id]['parameter'], $id.': parameter');
        }

        $this->assertSame($expected, $actual);
    }

    public function test_reference_tables_match_the_pinned_tables(): void
    {
        $this->assertSame($this->builder()->referenceTables(), ReferenceTablesData::TABLES);
    }

    public function test_kz_catalogue_matches_the_pinned_domain_rules(): void
    {
        $expected = $this->builder()->kzCatalogue();

        $this->assertSame($expected['expense_codes'], KzCatalogueData::EXPENSE_CODES);
        $this->assertSame($expected['income_codes'], KzCatalogueData::INCOME_CODES);
        $this->assertSame($expected['e1b_codes'], KzCatalogueData::E1B_CODES);
    }

    public function test_help_texts_match_the_pinned_fill_in_help(): void
    {
        $this->assertSame($this->builder()->helpTexts(), HelpTextsData::ROWS);
    }

    public function test_degressive_rules_match_the_pinned_business_rules(): void
    {
        $this->assertSame(
            $this->builder()->degressiveRules(),
            DegressiveRulesData::ALLOWED_IMMATERIAL_EXCEPTIONS,
        );
    }

    public function test_vat_requirements_match_the_pinned_text(): void
    {
        $this->assertSame($this->builder()->vatRequirements(), VatRequirements::TEXT);
    }

    private function builder(): EaDataBuilder
    {
        return new EaDataBuilder(dirname(__DIR__, 5).'/features/eur/cleanroom');
    }
}
