<?php

declare(strict_types=1);

namespace App\Services\Eur;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * EAR · beratende OCR-Hinweise aus Belegbildern (D-82).
 *
 * Die Erkennung läuft lokal über Tesseract mit deutschen Sprachdaten im
 * Deployment-Image. Das Ergebnis ist ausschließlich ein Vorschlag: es wird
 * nie in Buchungen übernommen und überschreibt keine serverseitigen Werte.
 * Ist Tesseract in einer Umgebung nicht vorhanden, liefert `recognise()` null
 * und der Endpunkt meldet OCR als nicht verfügbar — ein Upload darf daran
 * nicht scheitern.
 */
final class EurReceiptOcrService
{
    private const TIMEOUT_SECONDS = 20;

    /**
     * @param  (Closure(string): string|null)|null  $runner  nur für Tests; ohne Runner wird Tesseract aufgerufen
     */
    public function __construct(private readonly ?Closure $runner = null) {}

    public function recognise(string $bytes): ?string
    {
        if ($this->runner !== null) {
            return ($this->runner)($bytes);
        }

        $process = new Process(['tesseract', 'stdin', 'stdout', '-l', 'deu']);
        $process->setInput($bytes);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (Throwable) {
            return null;
        }

        $text = trim($process->getOutput());

        return $text === '' ? null : $text;
    }

    /**
     * Gepinnte Muster für Belegnummer, Datum und Betrag. Alle Werte sind
     * Vorschläge; die Buchung bleibt Sache des Benutzers.
     *
     * @return array{receipt_no: string|null, date: string|null, amount_cents: int|null}
     */
    public function hints(string $text): array
    {
        $receiptNo = null;

        if (preg_match('/(?:Beleg|Rechnung|Rg)[-\s]?(?:Nr\.?|Nummer)\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\/]{2,31})/iu', $text, $match) === 1) {
            $receiptNo = strtoupper(trim($match[1]));
        }

        $date = null;

        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $text, $match) === 1
            && checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            $date = sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
        }

        $amountCents = null;

        if (preg_match_all('/(\d{1,3}(?:[.\s]\d{3})*|\d+)[,.](\d{2})\s*€/u', $text, $matches, PREG_SET_ORDER) > 0) {
            $last = end($matches);
            $euros = (int) str_replace(['.', ' '], '', $last[1]);
            $amountCents = $euros * 100 + (int) $last[2];
        }

        return [
            'receipt_no' => $receiptNo,
            'date' => $date,
            'amount_cents' => $amountCents,
        ];
    }
}
