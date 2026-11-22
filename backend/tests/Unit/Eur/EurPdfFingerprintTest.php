<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Services\Eur\EurPdfFingerprintService;
use App\Support\BrandRegistry;
use Tests\TestCase;

/** EAR · Marker-Ausstellung und Inhaltsbindung ohne PDF-Signaturprüfung. */
class EurPdfFingerprintTest extends TestCase
{
    private const PDF = "%PDF-1.4 fingerprint\n%%EOF";

    private EurPdfFingerprintService $fingerprints;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fingerprints = app(EurPdfFingerprintService::class);
    }

    public function test_marker_is_appended_without_changing_the_original_bytes(): void
    {
        $marker = $this->fingerprints->issueMarker(
            BrandRegistry::currentId(),
            'monthly',
            2026,
            5,
            hash('sha256', self::PDF),
        );
        $marked = $this->fingerprints->appendMarker(self::PDF, $marker);

        $this->assertSame(self::PDF.$marker, $marked);
        $this->assertStringStartsWith("\n%EURDOC:", $marker);
        $this->assertStringEndsWith("%\n", $marker);
        $this->assertSame(self::PDF, $this->fingerprints->preMarkerBytes($marked));
        $this->assertNull($this->fingerprints->preMarkerBytes(self::PDF));
    }

    public function test_extract_returns_the_bound_claims(): void
    {
        $sha256 = hash('sha256', self::PDF);
        $marker = $this->fingerprints->issueMarker('rp', 'eigenbeleg', 2026, 5, $sha256);
        $payload = $this->fingerprints->extract($this->fingerprints->appendMarker(self::PDF, $marker));

        $this->assertIsArray($payload);
        $this->assertSame('eur_correction', $payload['type']);
        $this->assertSame('rp', $payload['brand']);
        $this->assertSame('eigenbeleg', $payload['kind']);
        $this->assertSame(2026, $payload['year']);
        $this->assertSame(5, $payload['month']);
        $this->assertSame($sha256, $payload['sha256']);
        $this->assertIsString($payload['jti']);
        $this->assertNotSame('', $payload['jti']);
    }

    public function test_verify_accepts_a_matching_marker(): void
    {
        $marked = $this->markedFor(BrandRegistry::currentId(), 'assets', 2026, 5, self::PDF);

        $this->assertTrue($this->fingerprints->verify($marked, BrandRegistry::currentId(), 2026, 5));
    }

    public function test_verify_rejects_foreign_brand_year_month_and_kind(): void
    {
        $marked = $this->markedFor('rp', 'monthly', 2026, 5, self::PDF);

        $this->assertFalse($this->fingerprints->verify($marked, 'srp', 2026, 5));
        $this->assertFalse($this->fingerprints->verify($marked, 'rp', 2025, 5));
        $this->assertFalse($this->fingerprints->verify($marked, 'rp', 2026, 6));
        $this->assertFalse($this->fingerprints->verify($marked, 'rp', 2026, null));

        $unknownKind = $this->fingerprints->appendMarker(
            self::PDF,
            $this->fingerprints->issueMarker('rp', 'unknown', 2026, 5, hash('sha256', self::PDF)),
        );
        $this->assertFalse($this->fingerprints->verify($unknownKind, 'rp', 2026, 5));
    }

    public function test_verify_rejects_a_marker_bound_to_different_bytes(): void
    {
        $otherPdf = "%PDF-1.4 other\n%%EOF";
        $marker = $this->fingerprints->issueMarker('rp', 'monthly', 2026, 5, hash('sha256', $otherPdf));

        // Frisch erzeugte Datei mit kopiertem Marker: das neue PDF ist nicht
        // das PDF, an das der JWT gebunden wurde.
        $forged = $this->fingerprints->appendMarker(self::PDF, $marker);
        $this->assertFalse($this->fingerprints->verify($forged, 'rp', 2026, 5));
    }

    public function test_verify_rejects_missing_and_tampered_markers(): void
    {
        $marked = $this->markedFor('rp', 'monthly', 2026, 5, self::PDF);

        $this->assertNull($this->fingerprints->extract(self::PDF));
        $this->assertFalse($this->fingerprints->verify(self::PDF, 'rp', 2026, 5));

        preg_match('/%EURDOC:([A-Za-z0-9_\.\-]+)%/', $marked, $matches, PREG_OFFSET_CAPTURE);
        [$token, $tokenOffset] = $matches[1];
        $last = substr($token, -1);
        $tampered = substr_replace($marked, $last === 'A' ? 'B' : 'A', $tokenOffset + strlen($token) - 1, 1);

        $this->assertNull($this->fingerprints->extract($tampered));
        $this->assertFalse($this->fingerprints->verify($tampered, 'rp', 2026, 5));
    }

    private function markedFor(string $brand, string $kind, int $year, int $month, string $pdf): string
    {
        return $this->fingerprints->appendMarker(
            $pdf,
            $this->fingerprints->issueMarker($brand, $kind, $year, $month, hash('sha256', $pdf)),
        );
    }
}
