<?php

declare(strict_types=1);

namespace App\Ea\Domain;

use App\Ea\Reference\ReferenceTables;
use InvalidArgumentException;

/**
 * EAR · Journal-Zeilenprüfung (EUE-032 … EUE-042).
 *
 * Reine, zustandslose Regeln: kein Datenbankzugriff, keine Uhr, kein Float.
 * Geld ist ganze Cent. Jede Prüfung liefert die vier Schlüssel
 * `code`, `ok`, `message` (deutsch, mit ⚠ bei Problemen) und `hint`
 * (deutscher Lösungsweg mit 💡). Fehlende Felder sind Hinweise, keine Fehler.
 */
final class JournalRules
{
    /** EUE-037: die zwölf Monatsnamen in Arbeitsmappen-Reihenfolge. */
    public const MONTHS = [
        'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    /** Monat!P7 — wörtlich, einschließlich der Quellschreibweise. */
    public const HEADER_HINT = '💡 Rechenaufwendige Kalkulation: Bitte nach Verwendung wieder auf OFF setzten!';

    /** EUE-032/041: Fallback-Kennzahlen aus dem gepinnten Artefakt. */
    public const INCOME_FALLBACK = ReferenceTables::INCOME_FALLBACK_E1A;

    public const EXPENSE_FALLBACK = ReferenceTables::EXPENSE_FALLBACK_E1A;

    /** EUE-039: IG-Erwerb; die Punkt-Schreibweise wird ebenfalls akzeptiert. */
    public const IG_RATES = [
        'IG-Erwerb 20 %' => [1, 5],
        'IG-Erwerb 10 %' => [1, 10],
        'IG-Erwerb 13 %' => [13, 100],
        'IG-Erwerb 4,9 %' => [49, 1000],
        'IG-Erwerb 4.9 %' => [49, 1000],
    ];

    /** EUE-040: Reverse Charge; es gibt keine 4,9-Variante. */
    public const RC_RATES = [
        'Reverse Charge 20 %' => [1, 5],
        'Reverse Charge 13 %' => [13, 100],
        'Reverse Charge 10 %' => [1, 10],
    ];

    public static function headerHint(): string
    {
        return self::HEADER_HINT;
    }

    public static function validDay(mixed $day): bool
    {
        return is_int($day) && $day >= 1 && $day <= 31;
    }

    public static function validMonth(mixed $month): bool
    {
        return is_string($month) && in_array($month, self::MONTHS, true);
    }

    /** '0' ist der gespeicherte Platzhalter für einen leer gelassenen Monat. */
    public static function blankMonth(mixed $month): bool
    {
        return $month === '' || $month === '0' || $month === null;
    }

    public static function monthFromDate(string $date): string
    {
        $month = (int) substr($date, 5, 2);

        return $month >= 1 && $month <= 12 ? self::MONTHS[$month - 1] : '';
    }

    /** EUE-030: Betriebsanteil 0–100 % in 5-%-Schritten. */
    public static function validBusinessSharePercent(int $percent): bool
    {
        return $percent >= 0 && $percent <= 100 && $percent % 5 === 0;
    }

    /** EUE-032: zugeordnete Einnahmen-Kennzahl, sonst 9040; privat → keine. */
    public static function incomeCode(string $kind, int $businessShare, ?string $mapped): string
    {
        if ($kind === '' || $businessShare === 0) {
            return '';
        }

        $code = trim((string) $mapped);

        return $code !== '' ? $code : self::INCOME_FALLBACK;
    }

    /** EUE-041: zugeordnete Ausgaben-Kennzahl, sonst 9230; privat → keine. */
    public static function expenseCode(string $kind, int $businessShare, ?string $mapped): string
    {
        if ($kind === '' || $businessShare === 0) {
            return '';
        }

        $code = trim((string) $mapped);

        return $code !== '' ? $code : self::EXPENSE_FALLBACK;
    }

    /** EUE-034: Mieteinnahmen gehören in die E1B, nie in den Betrieb. */
    public static function isRental(string $code): bool
    {
        $normalized = strtoupper(trim($code));

        return str_starts_with($normalized, 'E1B') && str_contains($normalized, '9460');
    }

    /**
     * EUE-039/040: Vorsteuer je Zeile auf den Bruttobetrag, kaufmännisch
     * (half-up) auf ganze Cent gerundet. Reine Integer-Arithmetik, kein Float.
     *
     * @return array{ig_vat_cents: int, rc_vat_cents: int}
     */
    public static function vatCents(int $amountCents, string $vatType): array
    {
        return [
            'ig_vat_cents' => self::rateCents($amountCents, self::IG_RATES[$vatType] ?? null),
            'rc_vat_cents' => self::rateCents($amountCents, self::RC_RATES[$vatType] ?? null),
        ];
    }

    /** EUE-042: die bekannten USt-Arten einschließlich der Punkt-Schreibweise. */
    public static function validVatType(string $vatType): bool
    {
        return isset(self::IG_RATES[$vatType]) || isset(self::RC_RATES[$vatType]);
    }

    /**
     * Ein einzelner Hinweis mit erzwungenen Markern: ⚠ bei Problemen,
     * immer ein 💡 mit Lösungsweg.
     *
     * @return array{code: string, ok: bool, message: string, hint: string}
     */
    public static function check(string $code, bool $ok, string $message, string $hint): array
    {
        if (! $ok && ! str_contains($message, '⚠')) {
            $message = '⚠ '.$message;
        }

        if (! str_contains($hint, '💡')) {
            $hint = '💡 '.$hint;
        }

        return ['code' => $code, 'ok' => $ok, 'message' => $message, 'hint' => $hint];
    }

    /**
     * EUE-036/042: alle Prüfungen einer Buchungszeile.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{code: string, ok: bool, message: string, hint: string}>
     */
    public static function checks(array $entry): array
    {
        $checks = [];
        $direction = self::direction($entry);
        $day = $entry['day'] ?? null;

        if ($day === null || $day === '') {
            $checks[] = self::check('DAY', false, '⚠ Tag fehlt.', 'Bitte den Tag der Buchung (1–31) ergänzen.');
        } elseif (! self::validDay($day)) {
            $checks[] = self::check(
                'DAY',
                false,
                sprintf('Ungültiger Tag: %s.', is_scalar($day) ? (string) $day : 'unbekannt'),
                'Bitte einen ganzen Tag zwischen 1 und 31 angeben.',
            );
        }

        if (trim((string) ($entry['category'] ?? '')) === '') {
            $checks[] = self::check(
                'CATEGORY',
                false,
                '⚠ Kategorie fehlt.',
                'Bitte eine Einnahmen- oder Ausgaben-Art wählen; sonst gilt der Fallback-Code.',
            );
        }

        if (trim((string) ($entry['description'] ?? '')) === '') {
            $checks[] = self::check(
                'DESCRIPTION',
                false,
                '⚠ Beschreibung fehlt.',
                'Bitte eine kurze Beschreibung ergänzen, damit der Beleg zuordenbar bleibt.',
            );
        }

        // EUE-037: das Artefakt nennt Monatsnamen, die API ganze Zahlen 1–12.
        // Beide Schreibweisen sind gültig; alles andere ist ein Hinweis.
        $month = $entry['month'] ?? null;
        $validMonth = self::validMonth($month)
            || (is_int($month) && $month >= 1 && $month <= 12);

        if (! $validMonth) {
            $checks[] = self::check(
                'MONTH',
                false,
                '⚠ Monat fehlt oder ist ungültig.',
                'Bitte einen Monat zwischen 1 und 12 angeben.',
            );
        }

        // EUE-036: Ausgaben brauchen eine Belegnummer.
        if ($direction === 'expense' && trim((string) ($entry['receipt_no'] ?? '')) === '') {
            $checks[] = self::check(
                'RECEIPT',
                false,
                '⚠ Belegnummer fehlt.',
                'Bitte eine Belegnummer vergeben, damit der Beleg zuordenbar bleibt.',
            );
        }

        $share = $entry['business_share'] ?? null;

        if ($share !== null && $share !== '' && is_int($share) && ! self::validBusinessSharePercent($share)) {
            $checks[] = self::check(
                'SHARE',
                false,
                'Betriebsanteil '.$share.' % ist kein 5-%-Schritt.',
                'Bitte einen Betriebsanteil zwischen 0 und 100 in 5-%-Schritten angeben.',
            );
        }

        $sharePercent = is_int($share) ? $share : ($share === null || $share === '' ? 100 : -1);
        $mapped = $entry['code'] ?? $entry['kennzahl'] ?? $entry['e1a_code'] ?? null;
        $code = $direction === 'income'
            ? self::incomeCode($direction, $sharePercent, is_string($mapped) ? $mapped : null)
            : self::expenseCode($direction, $sharePercent, is_string($mapped) ? $mapped : null);

        if ($code !== '') {
            $fallback = $direction === 'income' ? self::INCOME_FALLBACK : self::EXPENSE_FALLBACK;
            $checks[] = self::check(
                'CODE',
                true,
                sprintf('Kennzahl %s verwendet.', $code),
                $code === $fallback
                    ? sprintf('Ohne zugeordnete Art wird %s als Fallback verwendet; bei Bedarf die Art nachtragen.', $fallback)
                    : 'Die Kennzahl ist zugeordnet und wird für die E1A/E1B-Auswertung übernommen.',
            );
        }

        // EUE-043: KZ 9100 (Wareneingang) verlangt einen passenden WEB-Eintrag.
        if ($code === '9100') {
            $checks[] = self::check(
                'WEB',
                false,
                '⚠ Wareneingang ohne zugeordneten Lieferschein.',
                'Bitte den Wareneingang im WEB-Panel erfassen und zuordnen.',
            );
        }

        if (self::isRental($code)) {
            $checks[] = self::check(
                'RENTAL',
                true,
                'Vermietung erkannt: Kennzahl E1B 9460.',
                'Mieteinnahmen gehören in die E1B und nicht in die betrieblichen Einnahmen.',
            );
        }

        $vatKind = trim((string) ($entry['vat_kind'] ?? ''));

        if ($vatKind !== '') {
            if (! self::validVatType($vatKind)) {
                $checks[] = self::check(
                    'VAT_KIND',
                    false,
                    sprintf('Umsatzsteuer-Art "%s" existiert nicht.', $vatKind),
                    'Bitte eine der bekannten IG-Erwerb- oder Reverse-Charge-Arten auswählen.',
                );
            } else {
                $gross = (int) ($entry['amount_cents'] ?? 0);
                $vat = self::vatCents($gross, $vatKind);
                $value = $vat['ig_vat_cents'] !== 0 ? $vat['ig_vat_cents'] : $vat['rc_vat_cents'];
                $checks[] = self::check(
                    'VAT',
                    true,
                    sprintf('Vorsteuer %d ct aus %d ct Brutto (%s).', $value, $gross, $vatKind),
                    'Die Vorsteuer wird je Zeile aus dem Bruttobetrag kaufmännisch auf ganze Cent gerundet.',
                );
            }
        }

        return $checks;
    }

    /**
     * @param  ?array{0: int, 1: int}  $fraction
     */
    private static function rateCents(int $amountCents, ?array $fraction): int
    {
        if ($fraction === null || $amountCents <= 0) {
            return 0;
        }

        [$numerator, $denominator] = $fraction;

        if ($denominator <= 0) {
            throw new InvalidArgumentException('Steuersatz-Nenner muss positiv sein.');
        }

        return intdiv($amountCents * $numerator + intdiv($denominator, 2), $denominator);
    }

    /** @param array<string, mixed> $entry */
    private static function direction(array $entry): string
    {
        $raw = strtolower(trim((string) ($entry['kind'] ?? $entry['type'] ?? '')));

        return match ($raw) {
            'income', 'e', 'einnahme', 'einnahmen' => 'income',
            'expense', 'a', 'ausgabe', 'ausgaben' => 'expense',
            default => 'expense',
        };
    }
}
