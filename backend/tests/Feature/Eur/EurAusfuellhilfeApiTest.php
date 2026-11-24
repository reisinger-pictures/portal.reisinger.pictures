<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurEntry;
use App\Models\EurTravelTrip;
use App\Models\EurWarehouseEntry;
use App\Support\BrandRegistry;

/** D-91/#62/#63: ODS-konforme Werte inklusive PDF, brand-scoped und in Cent. */
class EurAusfuellhilfeApiTest extends EurBookkeepingTestCase
{
    private const URL = '/api/management/eur/ausfuellhilfe';

    public function test_endpoints_require_authentication(): void
    {
        $this->get(self::URL.'?year=2026')->assertUnauthorized();
        $this->get('/api/management/eur/pdf/ausfuellhilfe?year=2026')->assertUnauthorized();
    }

    public function test_payload_has_ods_section_order_sequential_orders_and_null_semantics(): void
    {
        $response = $this->withHeaders($this->bearer($this->tokenFor()))
            ->get(self::URL.'?year=2026');

        $response->assertOk()->assertJsonPath('year', 2026)
            ->assertJsonPath('units.money', 'cents')
            ->assertJsonPath('units.time', 'minutes')
            ->assertJsonPath('units.rate', 'per-10000')
            ->assertJsonPath('notice', 'Manuelle Eingabe in FinanzOnline. Keine elektronische Übermittlung.')
            ->assertJsonPath('empty_is_null', true);

        $payload = $response->json();
        $this->assertSame(['E1A', 'E1B', 'UST', 'UEBERSICHT', 'ABGLEICH'],
            array_column($payload['sections'], 'id'));

        $order = 0;

        foreach ($payload['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $this->assertSame(++$order, $field['order']);
                $this->assertArrayHasKey('value_cents', $field);
                $this->assertArrayHasKey('value_text', $field);
                $this->assertArrayHasKey('deviations', $field);
                $this->assertTrue($field['value_cents'] === null || is_int($field['value_cents']));
                $this->assertFalse($field['value_cents'] === 0);
            }
        }

        // Ohne Buchungen bleibt die Sammelkennzahl leer, nicht 0.
        $this->assertNull($this->field($payload, 'E1A', 'E11')['value_cents']);
        $this->assertNull($this->field($payload, 'E1A', 'E38')['value_cents']);
        $this->assertSame(22, count(array_filter(
            $payload['sections'][0]['fields'],
            static fn (array $field): bool => str_starts_with((string) $field['cell'], 'E')
                && (int) substr((string) $field['cell'], 1) >= 17
                && (int) substr((string) $field['cell'], 1) <= 38,
        )));
    }

    public function test_codes_and_deviations_follow_the_pinned_field_inventory(): void
    {
        $payload = $this->payload();
        $e1a = $payload['sections'][0]['fields'];

        $income = array_map(
            static fn (string $cell): array => (array) array_values(array_filter(
                $e1a,
                static fn (array $field): bool => $field['cell'] === $cell,
            ))[0],
            ['E11', 'E12', 'E13', 'E14'],
        );
        $this->assertSame(['9040', '9050', '9060', '9090'], array_column($income, 'kz'));

        $expenses = array_values(array_filter(
            $e1a,
            static fn (array $field): bool => in_array((string) $field['cell'], ['E17', 'E20', 'E24', 'E33', 'E38'], true),
        ));
        $this->assertSame(['9100', '9130', '9160', '9225', '9230'], array_column($expenses, 'kz'));

        $this->assertContains('D-86', array_column($this->field($payload, 'E1A', 'E24')['deviations'], 'code'));
        $this->assertContains('D-88', array_column($this->field($payload, 'UEBERSICHT', 'U23')['deviations'], 'code'));
        $this->assertContains('D-87', array_column($this->field($payload, 'UST', 'C2')['deviations'], 'code'));
        $this->assertSame('reference.income_tax.basic_allowance.limit_cents',
            $this->field($payload, 'E1A', 'J6')['source']);
        $this->assertSame(3300000, $this->field($payload, 'E1A', 'J6')['value_cents']);
        $this->assertSame(1500, $this->field($payload, 'E1A', 'J7')['value_text']);
    }

    public function test_bookings_from_other_sources_are_brand_scoped_and_cent_exact(): void
    {
        $brand = BrandRegistry::currentId();

        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 100000, 'business_amount_cents' => 100000,
        ]);
        EurEntry::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 999999, 'business_amount_cents' => 999999,
        ]);
        EurWarehouseEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 5,
            'description' => 'Ware', 'supplier' => 'Lieferant', 'expense_kind' => 'Wareneinkäufe',
            'amount_cents' => 12345, 'business_share' => 100, 'receipt_no' => 'WE-1',
        ]);
        EurTravelTrip::create([
            'brand' => $brand, 'year' => 2026, 'month' => 6, 'receipt_no' => 'R-1',
            'vehicle' => 'PKW', 'kilometres' => 100, 'passengers' => 0, 'country' => 'AT',
            'days' => 1, 'meals' => 0, 'with_receipts' => false, 'business_share' => 100,
            'e1a_code' => '9160', 'kilometre_cents' => 5000, 'day_cents' => 0,
            'night_cents' => 0, 'total_cents' => 5000, 'business_cents' => 5000,
        ]);
        $asset = EurAsset::create([
            'brand' => $brand, 'name' => 'Laptop', 'invoice_no' => 'RE-1',
            'supplier' => 'Lieferant', 'purchase_date' => '2026-01-15', 'cost_cents' => 100000, 'useful_life_years' => 3,
            'business_share' => 100, 'scope' => 'business', 'method' => 'linear',
        ]);
        EurAssetYear::create([
            'brand' => $brand, 'eur_asset_id' => $asset->id, 'year' => 2026, 'scope' => 'business',
            'method' => 'linear', 'depreciation_cents' => 30000, 'book_value_cents' => 70000,
            'business_use_cents' => 30000, 'private_use_cents' => 0, 'private_use_percent' => 0,
        ]);

        $payload = $this->payload();

        $this->assertSame(100000, $this->field($payload, 'E1A', 'E11')['value_cents']);
        $this->assertSame(12345, $this->field($payload, 'E1A', 'E17')['value_cents']);
        $this->assertSame(30000, $this->field($payload, 'E1A', 'E20')['value_cents']);
        $this->assertSame(5000, $this->field($payload, 'E1A', 'E24')['value_cents']);

        $all = [];

        foreach ($payload['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $all[] = $field['value_cents'];
            }
        }

        $this->assertNotContains(999999, $all);
    }

    public function test_pauschalen_come_from_request_inputs_and_are_suppressed_by_arbeitszimmer(): void
    {
        $headers = $this->bearer($this->tokenFor());

        $small = $this->withHeaders($headers)->get(self::URL.'?year=2026&e39_cents=20000&e40_cents=20000')
            ->assertOk()->json();
        $this->assertSame(20000, $this->field($small, 'E1A', 'E39')['value_cents']);
        $this->assertSame('9216', $this->field($small, 'E1A', 'C39')['value_text']);
        $this->assertSame('9215', $this->field($small, 'E1A', 'C40')['value_text']);

        $large = $this->withHeaders($headers)->get(self::URL.'?year=2026&e40_cents=50000')
            ->assertOk()->json();
        $this->assertSame('9217', $this->field($large, 'E1A', 'C40')['value_text']);

        $suppressed = $this->withHeaders($headers)->get(self::URL.'?year=2026&e39_cents=20000&e40_cents=50000&arbeitszimmer_cents=1000')
            ->assertOk()->json();
        $this->assertNull($this->field($suppressed, 'E1A', 'E39')['value_cents']);
        $this->assertNull($this->field($suppressed, 'E1A', 'C39')['value_text']);

        $this->assertStringContainsString('noch nicht gespeichert',
            $this->field($small, 'E1A', 'E39')['source']);
    }

    public function test_unsupported_year_and_invalid_cents_are_rejected(): void
    {
        $headers = $this->bearer($this->tokenFor());

        $this->withHeaders($headers)->get(self::URL.'?year=2027')->assertUnprocessable();
        $this->withHeaders($headers)->get(self::URL.'?year=2026&e39_cents=-1')->assertUnprocessable();
        $this->withHeaders($headers)->get('/api/management/eur/pdf/ausfuellhilfe?year=2027')->assertUnprocessable();
    }

    public function test_pdf_contains_sections_deviation_codes_and_foreign_data_is_absent(): void
    {
        $brand = BrandRegistry::currentId();

        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 100000000, 'business_amount_cents' => 100000000,
        ]);
        EurEntry::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 999999, 'business_amount_cents' => 999999,
        ]);

        $response = $this->withHeaders($this->bearer($this->tokenFor()))
            ->get('/api/management/eur/pdf/ausfuellhilfe?year=2026');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Ausfuellhilfe-2026.pdf',
            (string) $response->headers->get('Content-Disposition'));
        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $text = $this->pdfText($bytes);
        // dompdf codiert Umlaute im Seitenstrom als WinAnsi-Bytes.
        $this->assertStringContainsString('FinanzOnline', $text);
        $this->assertStringContainsString('E1A', $text);
        $this->assertStringContainsString('1.000.000,00', $text);
        $this->assertStringContainsString('D-88', $text);
        $this->assertStringNotContainsString('9.999,99', $text);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $payload = $this->withHeaders($this->bearer($this->tokenFor()))
            ->get(self::URL.'?year=2026')
            ->assertOk()
            ->json();

        return is_array($payload) ? $payload : [];
    }

    /** @param array<string, mixed> $payload */
    private function field(array $payload, string $sectionId, string $cell): array
    {
        foreach ($payload['sections'] ?? [] as $section) {
            if (($section['id'] ?? null) !== $sectionId) {
                continue;
            }

            foreach ($section['fields'] ?? [] as $field) {
                if (($field['cell'] ?? null) === $cell) {
                    return $field;
                }
            }
        }

        $this->fail(sprintf('Feld %s/%s fehlt.', $sectionId, $cell));
    }

    /** dompdf flate-komprimiert die Seitenströme. */
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
}
