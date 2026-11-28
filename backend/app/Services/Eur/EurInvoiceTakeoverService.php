<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Reference\EurYear;
use App\Eur\Reference\ReferenceTables;
use App\Eur\Support\Share;
use App\Models\EurAuditLog;
use App\Models\EurEntry;
use App\Support\PersistedMoney;
use InvalidArgumentException;

/**
 * EAR · Automatische Übernahme versandter Rechnungen in die Buchhaltung.
 *
 * Ein Dokument wird über `(brand, external_id)` identifiziert — nicht über
 * `receipt_no`, das Freitext ist und auch von Handbuchungen benutzt wird.
 * Der Unique-Index der Datenbank ist die letzte Instanz gegen Doppelbuchung:
 * seine Verletzung bricht den Sendepfad ab (fail closed), statt still eine
 * zweite Zeile zu schreiben. Die eigentliche Absicherung gegen parallele
 * Schreiber sind die Row-Locks der Aufrufer auf Snapshot bzw. Vertrag. Ein
 * Replay liefert denselben Eintrag
 * zurück, ein abweichender Payload erzeugt einen Audit-Eintrag und wird nie
 * still überschrieben.
 *
 * Kategorie-Mapping: automatische Rechnungen sind betriebliche
 * Dienstleistungserlöse (`Dienstleistung`, E1A 9040 aus dem Referenz-Fallback
 * EUE-008). Eine davon abweichende fachliche Zuordnung braucht eine neue,
 * dokumentierte Entscheidung statt einer stillen Umcodierung alter Zeilen.
 */
class EurInvoiceTakeoverService
{
    private const KIND_TYPE = 'Dienstleistung';

    private const CATEGORY = 'Rechnung';

    private const BUSINESS_SHARE = 100;

    private const NOTE_PREFIX = 'Automatische Übernahme: ';

    public function takeover(EurInvoiceTakeoverData $data): ?EurEntry
    {
        // Ein Replay eines Snapshots aus einem Regeljahr (Altbestellung
        // 2024/2025) ist keine Buchung: der Sendepfad der Mail läuft weiter,
        // es entsteht keine `eur_entries`-Zeile, und der Skip ist auditierbar.
        // Der Assert in {@see self::validate()} bleibt für den direkten Aufruf.
        if (! EurYear::accepts($data->year)) {
            $this->audit('invoice_takeover_skipped_booking_year', null, $data);

            return null;
        }

        $this->validate($data);

        // Lieferscheine (L-) und Angebote (A-) sind keine Erlösereignisse. Ihr
        // Bruttobetrag wird erst in der P-Sammelrechnung realisiert; dort liegt
        // die einzige Buchung. Ein Skip ist auditierbar, aber keine Buchung.
        if (! $data->isFiscalInvoice()) {
            $this->audit('invoice_takeover_skipped_non_invoice', null, $data);

            return null;
        }

        if ($data->optedOut) {
            $this->audit('invoice_takeover_opted_out', null, $data);

            return null;
        }

        $existing = $this->findExisting($data);
        if ($existing instanceof EurEntry) {
            $this->compareOrAuditConflict($existing, $data);

            return $existing;
        }

        $entry = EurEntry::create([
            'brand' => $data->brand,
            'year' => $data->year,
            'month' => $data->month,
            'day' => $data->day,
            'direction' => 'income',
            'kind_type' => self::KIND_TYPE,
            'category' => self::CATEGORY,
            'e1a_code' => ReferenceTables::INCOME_FALLBACK_E1A,
            'business_share' => self::BUSINESS_SHARE,
            'amount_cents' => $data->amountCents,
            'business_amount_cents' => Share::apply($data->amountCents, self::BUSINESS_SHARE),
            'receipt_no' => $data->invoiceNumber,
            'note' => self::NOTE_PREFIX.$data->source,
            'external_id' => $data->externalId,
        ]);

        $this->audit('invoice_takeover_created', $entry, $data);

        return $entry;
    }

    /**
     * Brand ist Teil des Schlüssels: gleiche Rechnungsnummern verschiedener
     * Mandanten dürfen sich nicht gegenseitig als Replay maskieren.
     */
    private function findExisting(EurInvoiceTakeoverData $data): ?EurEntry
    {
        return EurEntry::query()
            ->where('brand', $data->brand)
            ->where('external_id', $data->externalId)
            ->first();
    }

    /**
     * Ein Replay mit identischem Payload ist ein No-op. Weicht der Payload ab,
     * bleibt die bestehende Buchung unangetastet und der Konflikt wird auditiert.
     */
    private function compareOrAuditConflict(EurEntry $entry, EurInvoiceTakeoverData $data): void
    {
        $same = (int) $entry->amount_cents === $data->amountCents
            && (int) $entry->year === $data->year
            && (int) $entry->month === $data->month
            && (int) $entry->day === $data->day
            && $entry->direction === 'income'
            && (int) $entry->business_amount_cents === $data->amountCents
            && $entry->receipt_no === $data->invoiceNumber
            && $entry->note === self::NOTE_PREFIX.$data->source;

        if (! $same) {
            $this->audit('invoice_takeover_conflict', $entry, $data);
        }
    }

    private function validate(EurInvoiceTakeoverData $data): void
    {
        // Die Übernahme erzeugt eine Buchung; 2024/2025 sind reine Regeljahre.
        EurYear::assertBookable($data->year);
        PersistedMoney::assertFitsCents($data->amountCents, 'invoice.total_gross');

        if ($data->month < 1 || $data->month > 12 || $data->day < 1 || $data->day > 31) {
            throw new InvalidArgumentException('Ungültiges Buchungsdatum der Rechnungsübernahme.');
        }
        if (trim($data->externalId) === '' || mb_strlen($data->externalId) > 190) {
            throw new InvalidArgumentException('Ungültige externe ID der Rechnungsübernahme.');
        }
        if (trim($data->brand) === '') {
            throw new InvalidArgumentException('Rechnungsübernahme ohne Brand.');
        }
        if ($data->invoiceNumber === '' || mb_strlen($data->invoiceNumber) > 64) {
            throw new InvalidArgumentException('Ungültige Rechnungsnummer der Rechnungsübernahme.');
        }
        if (! in_array($data->source, [
            EurInvoiceTakeoverData::SOURCE_ORDER_INVOICE,
            EurInvoiceTakeoverData::SOURCE_COLLECTIVE_INVOICE,
            EurInvoiceTakeoverData::SOURCE_CONTRACT_INVOICE,
        ], true)) {
            throw new InvalidArgumentException('Unbekannte Quelle der Rechnungsübernahme.');
        }
    }

    private function audit(string $action, ?EurEntry $entry, EurInvoiceTakeoverData $data): void
    {
        EurAuditLog::create([
            'brand' => $data->brand,
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'eur_entry',
            'subject_id' => $entry?->id,
            'payload' => [
                'source' => $data->source,
                'external_id' => $data->externalId,
                'invoice_number' => $data->invoiceNumber,
                'year' => $data->year,
                'amount_cents' => $data->amountCents,
                'business_share' => self::BUSINESS_SHARE,
            ],
        ]);
    }
}
