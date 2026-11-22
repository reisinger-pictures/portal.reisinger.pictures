<?php

declare(strict_types=1);

namespace App\Eur\Parity;

/**
 * EAR · Parität — minimale Wertekette für Summen und Auswertungen.
 *
 * Eingaben und Ausgaben sind ausschließlich ganze Cent. Die Klasse ist
 * framework- und datenbankfrei und speichert unveränderliche Werte.
 */
final class BookkeepingTotals
{
    private function __construct(
        private readonly int $expensesCents,
        private readonly int $profitCents,
    ) {}

    public static function fromEntries(int $incomeCents, int $expensesCents): self
    {
        return new self($expensesCents, $incomeCents - $expensesCents);
    }

    public function expensesCents(): int
    {
        return $this->expensesCents;
    }

    public function profitCents(): int
    {
        return $this->profitCents;
    }

    public function e1aResultCents(): int
    {
        return $this->profitCents;
    }

    public function analyticsExpenseCents(): int
    {
        return $this->expensesCents;
    }

    public function analyticsPaymentCents(): int
    {
        return $this->expensesCents;
    }
}
