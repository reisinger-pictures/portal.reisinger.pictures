<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Reference\EurYear;
use App\Eur\Reference\ReferenceTables;
use App\Eur\Support\Share;
use App\Models\EurAuditLog;
use App\Models\EurEntry;
use App\Models\EurMonthlyReport;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * EAR · Rechnungskorrektur als Erlösminderung (Portal #71).
 *
 * Eine extern bereits ausgeführte Teilrückzahlung wird als negative Einnahme
 * dokumentiert — nicht als Betriebsausgabe und ohne Zahlungsauslösung. Die
 * Buchung bleibt an der übernommenen Rechnung über `receipt_no` und die stabile
 * externe ID erkennbar. Ein Replay desselben Vorgangs ist ein No-op: der
 * Unique-Index `(brand, external_id)` verhindert eine zweite Zeile.
 */
class EurInvoiceCorrectionService
{
    private const KIND_TYPE = 'Dienstleistung';

    private const CATEGORY = 'Rechnungskorrektur';

    private const BUSINESS_SHARE = 100;

    private const DESCRIPTION_PREFIX = 'Rechnungskorrektur: ';

    public function correct(
        string $invoiceNumber,
        int $refundCents,
        int $year,
        int $month,
        int $day,
        string $reason,
    ): EurEntry {
        $original = EurEntry::forCurrentBrand()
            ->where('external_id', 'invoice:'.$invoiceNumber)
            ->first();

        if (! $original instanceof EurEntry) {
            throw new ModelNotFoundException;
        }

        if ($original->direction !== 'income' || (int) $original->amount_cents <= 0) {
            throw new InvalidArgumentException('Die übernommene Rechnung kann nicht korrigiert werden.');
        }

        // Ein Replay ist unabhängig vom Monatsabschluss und von bereits
        // gebuchten weiteren Korrekturen ein No-op: gleiche externe ID, gleiche
        // Buchung, kein zweiter Audit-Eintrag.
        $externalId = sprintf(
            'invoice-correction:%s:%d:%d-%d-%d',
            $invoiceNumber,
            $refundCents,
            $year,
            $month,
            $day,
        );

        $existing = EurEntry::forCurrentBrand()->where('external_id', $externalId)->first();
        if ($existing instanceof EurEntry) {
            return $existing;
        }

        EurYear::assert($year);

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            throw new InvalidArgumentException('Ungültiges Buchungsdatum der Rechnungskorrektur.');
        }

        if ($refundCents < 1) {
            throw new InvalidArgumentException('Der Rückzahlungsbetrag muss mindestens 1 Cent betragen.');
        }

        if ($refundCents > (int) $original->amount_cents) {
            throw new InvalidArgumentException('Die Rückzahlung darf den Rechnungsbetrag nicht überschreiten.');
        }

        // Mehrere Teilrückzahlungen dürfen zusammen den Rechnungsbetrag nicht
        // überschreiten. Die externe ID wird in PHP geprüft statt per LIKE
        // interpoliert; Cent-Werte bleiben ganzzahlig.
        $alreadyRefundedCents = 0;
        $prefix = 'invoice-correction:'.$invoiceNumber.':';
        EurEntry::forCurrentBrand()
            ->where('category', self::CATEGORY)
            ->where('receipt_no', $invoiceNumber)
            ->get()
            ->each(function (EurEntry $correction) use ($prefix, &$alreadyRefundedCents): void {
                if (str_starts_with((string) $correction->external_id, $prefix)) {
                    $alreadyRefundedCents += -(int) $correction->amount_cents;
                }
            });

        if ($refundCents + $alreadyRefundedCents > (int) $original->amount_cents) {
            throw new InvalidArgumentException('Die Summe der Rückzahlungen darf den Rechnungsbetrag nicht überschreiten.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Bitte einen Grund für die Rechnungskorrektur angeben.');
        }

        // D-58: ein abgeschlossener Monat bleibt unveränderlich, auch für eine
        // nachträgliche Korrektur mit Datum im abgeschlossenen Monat.
        $closed = EurMonthlyReport::forCurrentBrand()
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'closed')
            ->exists();
        if ($closed) {
            throw new InvalidArgumentException('Der Monat ist abgeschlossen und kann nicht korrigiert werden.');
        }

        $entry = EurEntry::create([
            'brand' => BrandRegistry::currentId(),
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'direction' => 'income',
            'kind_type' => self::KIND_TYPE,
            'category' => self::CATEGORY,
            'e1a_code' => ReferenceTables::INCOME_FALLBACK_E1A,
            'business_share' => self::BUSINESS_SHARE,
            'amount_cents' => -$refundCents,
            'business_amount_cents' => Share::apply(-$refundCents, self::BUSINESS_SHARE),
            'receipt_no' => $invoiceNumber,
            'description' => mb_substr(self::DESCRIPTION_PREFIX.$reason, 0, 190),
            'note' => null,
            'external_id' => $externalId,
        ]);

        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => 'invoice_correction_created',
            'subject_type' => 'eur_entry',
            'subject_id' => $entry->id,
            'payload' => [
                'invoice_number' => $invoiceNumber,
                'refund_cents' => $refundCents,
                'retained_cents' => (int) $original->amount_cents - $alreadyRefundedCents - $refundCents,
                'year' => $year,
                'month' => $month,
                'business_share' => self::BUSINESS_SHARE,
            ],
        ]);

        return $entry;
    }
}
