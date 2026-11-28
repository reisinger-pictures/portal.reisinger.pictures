<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use App\Eur\Data\KzCatalogueData;

/**
 * EAR · Kennzahlen-Katalog (EUE-023, EUE-024) aus dem gepinnten Artefakt.
 *
 * Die Listen stehen als Programmcode in `App\Eur\Data\KzCatalogueData`
 * (generiert vom Generator `php artisan eur:export-data`, Issue #74). Der Katalog
 * wird zur Laufzeit nicht gelesen; die Ableitung aus den Regelzellen steckt
 * im Generator und wird vom Paritätstest gegen das Artefakt geprüft.
 */
final class KzCatalogue
{
    /**
     * @return array{
     *     expense_codes: list<string>,
     *     income_codes: list<string>,
     *     e1b_codes: list<string>,
     *     distinct_expense_count: int
     * }
     */
    public function all(): array
    {
        $expense = $this->expenseCodes();

        return [
            'expense_codes' => $expense,
            'income_codes' => $this->incomeCodes(),
            'e1b_codes' => $this->e1bCodes(),
            'distinct_expense_count' => count($expense),
        ];
    }

    /** @return list<string> die 27 unterschiedlichen Ausgaben-Kennzahlen */
    public function expenseCodes(): array
    {
        return KzCatalogueData::EXPENSE_CODES;
    }

    /** @return list<string> die fünf Einnahmen-Kennzahlen */
    public function incomeCodes(): array
    {
        return KzCatalogueData::INCOME_CODES;
    }

    /**
     * E1B-Kennzahlen inklusive der geteilten 9135 — aus der Belegspalte des
     * gepinnten Artefakts abgeleitet, nicht aus einer zweiten Liste im Code.
     *
     * @return list<string>
     */
    public function e1bCodes(): array
    {
        return KzCatalogueData::E1B_CODES;
    }
}
