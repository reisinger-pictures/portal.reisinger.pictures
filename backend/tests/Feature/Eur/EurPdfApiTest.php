<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurEntry;
use App\Support\BrandRegistry;

/**
 * EAR · PDF-Endpunkte: PDF-Magic-Bytes, deutsche Beschriftungen und
 * Brand-Trennung (Monatsbericht, Eigenbeleg, Anlagenverzeichnis).
 */
class EurPdfApiTest extends EurBookkeepingTestCase
{
    public function test_pdf_endpoints_require_authentication(): void
    {
        $this->get('/api/management/eur/pdf/month?year=2026&month=5')->assertUnauthorized();
        $this->get('/api/management/eur/pdf/eigenbeleg?year=2026&month=5&description=x&amount_cents=100')->assertUnauthorized();
        $this->get('/api/management/eur/pdf/assets?year=2026')->assertUnauthorized();
    }

    public function test_monthly_pdf_contains_own_entries_and_no_foreign_brand_data(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 100000, 'business_amount_cents' => 100000,
            'description' => 'MONTHLY_MARKER_XYZ',
        ]);
        EurEntry::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 999999, 'business_amount_cents' => 999999,
            'description' => 'FOREIGN_MONTHLY_XYZ',
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->get('/api/management/eur/pdf/month?year=2026&month=5');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $text = $this->pdfText($bytes);
        $this->assertStringContainsString('Monatsbericht', $text);
        $this->assertStringContainsString('Betriebliche Einnahmen', $text);
        // EUE-124: die USt-Kennzahlen erscheinen als eigene Tabelle im PDF.
        $this->assertStringContainsString('Umsatzsteuer-Kennzahlen', $text);
        $this->assertStringContainsString('016', $text);
        $this->assertStringContainsString('MONTHLY_MARKER_XYZ', $text);
        $this->assertStringNotContainsString('FOREIGN_MONTHLY_XYZ', $text);
    }

    public function test_eigenbeleg_pdf_shows_description_and_euro_amount(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->get(
            '/api/management/eur/pdf/eigenbeleg?year=2026&month=5&description=EIGENBELEG_MARKER_XYZ&amount_cents=12345',
        );

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $text = $this->pdfText($bytes);
        $this->assertStringContainsString('Eigenbeleg', $text);
        $this->assertStringContainsString('Beschreibung', $text);
        $this->assertStringContainsString('EIGENBELEG_MARKER_XYZ', $text);
        $this->assertStringContainsString('123,45', $text);
    }

    public function test_assets_pdf_contains_own_asset_and_no_foreign_asset(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        $own = EurAsset::create([
            'brand' => $brand, 'name' => 'OWN_ASSET_XYZ', 'invoice_no' => 'RE-1',
            'supplier' => 'Lieferant', 'purchase_date' => '2026-01-15', 'cost_cents' => 100000,
            'useful_life_years' => 3, 'business_share' => 100, 'method' => 'linear',
        ]);
        EurAssetYear::create([
            'brand' => $brand, 'eur_asset_id' => $own->id, 'year' => 2026, 'method' => 'linear',
            'depreciation_cents' => 20000, 'book_value_cents' => 80000,
            'private_use_percent' => 0, 'private_use_cents' => 0, 'business_use_cents' => 20000,
        ]);
        $foreign = EurAsset::create([
            'brand' => self::OTHER_BRAND, 'name' => 'FOREIGN_ASSET_XYZ', 'invoice_no' => 'RE-2',
            'supplier' => 'Lieferant', 'purchase_date' => '2026-01-15', 'cost_cents' => 500000,
            'useful_life_years' => 3, 'business_share' => 100, 'method' => 'linear',
        ]);
        EurAssetYear::create([
            'brand' => self::OTHER_BRAND, 'eur_asset_id' => $foreign->id, 'year' => 2026, 'method' => 'linear',
            'depreciation_cents' => 100000, 'book_value_cents' => 400000,
            'private_use_percent' => 0, 'private_use_cents' => 0, 'business_use_cents' => 100000,
        ]);

        $response = $this->withHeaders($this->bearer($token))->get('/api/management/eur/pdf/assets?year=2026');

        $response->assertOk();
        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $text = $this->pdfText($bytes);
        $this->assertStringContainsString('Anlagenverzeichnis', $text);
        $this->assertStringContainsString('OWN_ASSET_XYZ', $text);
        $this->assertStringNotContainsString('FOREIGN_ASSET_XYZ', $text);
    }

    /**
     * dompdf komprimiert die Seitenströme mit FlateDecode; die Textinhalte
     * liegen nach dem Inflate als Klartext vor.
     */
    private function pdfText(string $bytes): string
    {
        $text = $bytes;
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches);

        foreach ($matches[1] ?? [] as $stream) {
            $decoded = @gzuncompress($stream);

            if ($decoded !== false) {
                $text .= $decoded;
            }
        }

        return $text;
    }

    public function test_unknown_year_and_invalid_query_are_rejected(): void
    {
        $headers = $this->bearer($this->tokenFor());

        $this->withHeaders($headers)->get('/api/management/eur/pdf/month?year=2027&month=5')->assertUnprocessable();
        $this->withHeaders($headers)->get('/api/management/eur/pdf/assets?year=2023')->assertUnprocessable();
        $this->withHeaders($headers)->get('/api/management/eur/pdf/eigenbeleg?year=2026&month=13&description=x&amount_cents=1')->assertUnprocessable();
        $this->withHeaders($headers)->get('/api/management/eur/pdf/eigenbeleg?year=2026&month=5&amount_cents=1')->assertUnprocessable();
    }
}
