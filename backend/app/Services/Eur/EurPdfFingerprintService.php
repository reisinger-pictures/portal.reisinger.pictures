<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Services\OfferTokenService;
use Illuminate\Support\Str;

/**
 * EAR · Inhaltsbindung für extern zu signierende Berichtigungs-PDFs.
 *
 * Der Marker wird NACH den unveränderten PDF-Bytes angehängt und trägt in einem
 * signierten JWT einen Hash genau dieser Ursprungsbytes. Beim Wiedereingang
 * wird geprüft, dass die hochgeladenen Bytes mit den gespeicherten Bytes
 * übereinstimmen und dass der JWT-Hash die vollständigen Ursprungsbytes
 * bindet. Damit ist belegt, dass die extern signierte Datei auf demselben
 * Inhalt beruht — mehr nicht.
 *
 * WICHTIG: Das ist CONTENT-IDENTITY, keine qualifizierte elektronische
 * Signatur. Es gibt keine QES/CMS-/PAdES-Validierung, keine Vertrauensprüfung
 * und keine Signaturkette; `pdfsig` ist im Deployment-Image nicht installiert
 * und wird bewusst nicht aufgerufen. Ein gültiger Marker besagt ausschließlich,
 * dass der geprüfte Inhalt mit dem aus dem Portal heruntergeladenen Inhalt
 * identisch ist.
 */
final class EurPdfFingerprintService
{
    /** @var list<string> */
    private const KINDS = ['monthly', 'eigenbeleg', 'assets'];

    /** Gleiche Technik wie ManualInvoiceService::extractOfferFromPdf(). */
    private const MARKER_PATTERN = '/%EURDOC:([A-Za-z0-9_\.\-]+)%/';

    private const MARKER_PREFIX = "\n%EURDOC:";

    private const MARKER_SUFFIX = "%\n";

    private const TYPE = 'eur_correction';

    public function __construct(private readonly OfferTokenService $tokens) {}

    /**
     * Marker für die Ursprungsbytes erzeugen.
     *
     * Die Gültigkeit übersteigt bewusst die 14-Tage-Voreinstellung des
     * OfferTokenService: der Marker ist ein dauerhafter Inhaltsfingerabdruck,
     * kein kurzlebiger Download-Link.
     */
    public function issueMarker(
        string $brand,
        string $kind,
        int $year,
        ?int $month,
        string $sha256,
    ): string {
        $token = $this->tokens->issue([
            'type' => self::TYPE,
            'brand' => $brand,
            'kind' => $kind,
            'year' => $year,
            'month' => $month,
            'sha256' => $sha256,
            'jti' => (string) Str::uuid(),
        ], now()->addYears(10));

        return self::MARKER_PREFIX.$token.self::MARKER_SUFFIX;
    }

    /** Marker exakt hinter die ursprünglichen PDF-Bytes hängen. */
    public function appendMarker(string $pdfBytes, string $marker): string
    {
        return $pdfBytes.$marker;
    }

    /**
     * Die Bytes vor dem Marker rekonstruieren.
     *
     * Der Marker ist ein Bestandteil der gespeicherten Klartextdatei; alles vor
     * seinem vorangestellten Zeilenumbruch sind die ursprünglichen PDF-Bytes.
     */
    public function preMarkerBytes(string $markedBytes): ?string
    {
        $offset = $this->markerOffset($markedBytes);

        return $offset === null || $offset < 1 ? null : substr($markedBytes, 0, $offset - 1);
    }

    /**
     * JWT aus dem Marker lesen und kryptografisch verifizieren.
     *
     * @return array<string, mixed>|null
     */
    public function extract(string $bytes): ?array
    {
        if (! preg_match(self::MARKER_PATTERN, $bytes, $matches)) {
            return null;
        }

        return $this->tokens->verify($matches[1]);
    }

    /**
     * Inhaltsbindung und fachliche Claims vollständig prüfen.
     *
     * `brand`, `kind`, `year`, `month` und der Hash der Ursprungsbytes müssen
     * übereinstimmen; `type` muss der hier ausgestellte Typ sein.
     */
    public function verify(string $markedBytes, string $brand, int $year, ?int $month): bool
    {
        $payload = $this->extract($markedBytes);
        $originalBytes = $this->preMarkerBytes($markedBytes);

        if ($payload === null || $originalBytes === null) {
            return false;
        }

        $type = $payload['type'] ?? null;
        $markerBrand = $payload['brand'] ?? null;
        $kind = $payload['kind'] ?? null;
        $markerYear = $payload['year'] ?? null;
        $markerMonth = $payload['month'] ?? null;
        $sha256 = $payload['sha256'] ?? null;

        if (! is_string($type) || ! hash_equals(self::TYPE, $type)) {
            return false;
        }
        if (! is_string($markerBrand) || ! hash_equals($brand, $markerBrand)) {
            return false;
        }
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            return false;
        }
        if ($markerYear !== $year || $markerMonth !== $month) {
            return false;
        }

        return is_string($sha256) && hash_equals(hash('sha256', $originalBytes), $sha256);
    }

    private function markerOffset(string $bytes): ?int
    {
        if (! preg_match(self::MARKER_PATTERN, $bytes, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return (int) $matches[0][1];
    }
}
