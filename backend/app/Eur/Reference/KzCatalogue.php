<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use RuntimeException;

/**
 * EAR · Kontenplan (EUE-023, EUE-024).
 *
 * Einzige Quelle ist die gepinnte `DOMAIN.md`: die Spalte „Werte" der beiden
 * Regelzeilen enthält die KZ-Vektoren, die Belegspalte die E1B-Zuordnung. Es
 * gibt keine zweite, im Code gepflegte Kennzahlliste. Die Ausfüllhilfen
 * (`E1aHelpTexts`) sind ein anderer Scope (Einnahmen- und Pauschal-KZ ohne
 * E1B) und werden deshalb nicht als Kontenplan verwendet.
 */
final class KzCatalogue
{
    private const PATH = __DIR__.'/../../../../features/eur/cleanroom/DOMAIN.md';

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
        return $this->codes('EUE-023');
    }

    /** @return list<string> die fünf Einnahmen-Kennzahlen */
    public function incomeCodes(): array
    {
        return $this->codes('EUE-024');
    }

    /**
     * E1B-Kennzahlen inklusive der geteilten 9135 — aus der Belegspalte des
     * gepinnten Artefakts abgeleitet, nicht aus einer zweiten Liste im Code.
     *
     * @return list<string>
     */
    public function e1bCodes(): array
    {
        $evidence = $this->row('EUE-023')[9] ?? '';

        if (preg_match('/E1B-Kennzahlen\s+([0-9\/]+)/u', $evidence, $matches) !== 1) {
            throw new RuntimeException('EUE-023: E1B-Zuordnung fehlt im gepinnten Artefakt.');
        }

        return array_values(array_filter(explode('/', $matches[1]), static fn (string $code): bool => $code !== ''));
    }

    /** @return list<string> */
    private function codes(string $ruleId): array
    {
        $value = $this->row($ruleId)[6] ?? '';

        if (preg_match_all('/\b\d{4}\b/', $value, $matches) === 0) {
            throw new RuntimeException(sprintf('%s: Kennzahlen fehlen im gepinnten Artefakt.', $ruleId));
        }

        return array_values(array_unique($matches[0]));
    }

    /** @return list<string> */
    private function row(string $ruleId): array
    {
        $content = file_get_contents(self::PATH);

        if ($content === false) {
            throw new RuntimeException('Gepinntes Artefakt nicht lesbar: '.self::PATH);
        }

        foreach (explode("\n", $content) as $line) {
            if (! str_starts_with($line, '| '.$ruleId.' ')) {
                continue;
            }

            return array_map('trim', explode('|', $line));
        }

        throw new RuntimeException(sprintf('%s fehlt im gepinnten Artefakt.', $ruleId));
    }
}
