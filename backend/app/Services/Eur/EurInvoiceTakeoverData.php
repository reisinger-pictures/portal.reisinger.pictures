<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Models\InvoiceSnapshot;
use App\Support\BrandRegistry;
use InvalidArgumentException;

/**
 * EAR · Unveränderlicher Input der automatischen Rechnungsübernahme.
 *
 * Geld ist ausschließlich ganzzahliger Cent-Betrag aus der Buchungsquelle;
 * die externe ID ist der stabile, technische Schlüssel des Dokuments und
 * niemals ein vom Menschen gepflegtes Belegfeld.
 */
final readonly class EurInvoiceTakeoverData
{
    public const SOURCE_ORDER_INVOICE = 'order_invoice';

    public const SOURCE_COLLECTIVE_INVOICE = 'collective_invoice';

    public const SOURCE_CONTRACT_INVOICE = 'contract_invoice';

    /**
     * Nur die aus der gemeinsamen InvoiceSequence vergebene P-Nummer ist eine
     * steuerliche Rechnung. L-Nummern sind Lieferscheine ohne fiskalisches
     * Rechnungsnummer, A-Nummern sind Angebote; beide sind keine Erlösereignisse.
     */
    public const FISCAL_INVOICE_PREFIX = 'P-';

    /**
     * @param  string  $externalId  stabiler UUID-unabhängiger Dokumentschlüssel
     * @param  string  $source  eine der SOURCE_*-Konstanten
     * @param  string  $invoiceNumber  Kunden-/Belegnummer der Quelle
     * @param  int  $amountCents  Bruttobetrag der Rechnung in ganzen Cent
     * @param  int  $year  Buchungsjahr
     * @param  int  $month  Buchungsmonat 1–12
     * @param  int  $day  Buchungstag 1–31
     * @param  string  $brand  Mandant der Quelle
     * @param  bool  $optedOut  opt-out je Dokument (derzeit ohne UI-Schalter)
     */
    public function __construct(
        public string $externalId,
        public string $source,
        public string $invoiceNumber,
        public int $amountCents,
        public int $year,
        public int $month,
        public int $day,
        public string $brand,
        public bool $optedOut = false,
    ) {}

    public function isFiscalInvoice(): bool
    {
        return str_starts_with($this->invoiceNumber, self::FISCAL_INVOICE_PREFIX);
    }

    /**
     * Die invoice_snapshots-PK ist der stabile Dokumentenschlüssel: sie wird
     * von Checkout, Sammelrechnung und Vertragsabschluss aus derselben
     * InvoiceSequence vergeben und ist damit quellenübergreifend eindeutig.
     */
    public static function fromInvoiceSnapshot(
        InvoiceSnapshot $snapshot,
        string $source,
        bool $optedOut = false,
    ): self {
        $invoiceNumber = trim((string) $snapshot->invoice_number);
        if ($invoiceNumber === '') {
            throw new InvalidArgumentException('Rechnungssnapshot ohne Rechnungsnummer.');
        }

        $brand = BrandRegistry::normalizeId($snapshot->brand) ?? BrandRegistry::currentId();
        $date = $snapshot->created_at ?? now();

        return new self(
            externalId: 'invoice:'.$invoiceNumber,
            source: $source,
            invoiceNumber: $invoiceNumber,
            amountCents: (int) $snapshot->total_gross,
            year: (int) $date->year,
            month: (int) $date->month,
            day: (int) $date->day,
            brand: $brand,
            optedOut: $optedOut,
        );
    }
}
