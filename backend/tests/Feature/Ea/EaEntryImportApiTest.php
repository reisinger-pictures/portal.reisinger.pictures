<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Models\EaEntry;
use App\Support\BrandRegistry;
use Illuminate\Http\UploadedFile;

/** Portal #31 — CSV-Import mit deutscher Kopfzeile und Zeilenfehlern. */
class EaEntryImportApiTest extends EaBookkeepingTestCase
{
    /**
     * @return array<string, string>
     */
    private function uploadHeaders(string $token): array
    {
        return array_merge($this->bearer($token), ['Content-Type' => 'multipart/form-data; boundary=eurboundary']);
    }

    public function test_import_creates_only_valid_rows_and_reports_skipped_lines(): void
    {
        $token = $this->tokenFor();
        $csv = implode("\n", [
            'Datum;Art;Kategorie;Beschreibung;Betrag;Betriebsanteil;Belegnummer',
            '05.05.2026;Einnahme;Dienstleistung;Honorar;1.200,50;100;R-1',
            '06.05.2026;Ausgabe;Software;Abo;19,90;50;R-2',
            '07.05.2026;Ausgabe;Software;Falscher Anteil;10,00;33;R-3',
            '08.05.2026;Einnahme;Dienstleistung;Kein Betrag;abc;100;R-4',
        ]);

        $response = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/entries/import', [
            'file' => UploadedFile::fake()->createWithContent('buchungen.csv', $csv),
            'year' => 2026,
            'month' => 5,
        ]);

        $response->assertOk()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('skipped', 2);
        $this->assertCount(2, $response->json('errors'));
        $this->assertSame([4, 5], array_column($response->json('errors'), 'line'));
        $this->assertNotEmpty($response->json('errors.0.message'));

        $entries = EaEntry::forCurrentBrand()->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('income', $entries[0]->direction);
        $this->assertSame(120050, (int) $entries[0]->amount_cents);
        $this->assertSame(120050, (int) $entries[0]->business_amount_cents);
        $this->assertSame('expense', $entries[1]->direction);
        $this->assertSame(1990, (int) $entries[1]->amount_cents);
        $this->assertSame(995, (int) $entries[1]->business_amount_cents);
        $this->assertSame('R-2', $entries[1]->receipt_no);
        $this->assertDatabaseHas('ea_entries', [
            'id' => $entries[0]->id, 'brand' => BrandRegistry::currentId(),
        ]);
        $this->assertDatabaseCount('ea_entries', 2);
    }

    public function test_import_accepts_comma_separated_files_and_uses_request_defaults(): void
    {
        $token = $this->tokenFor();
        $csv = implode("\n", [
            'Datum,Art,Kategorie,Beschreibung,Betrag,Betriebsanteil',
            '10.04.2026,Ausgabe,Büro,Papier,"1.234,56",100',
            '11.04.2026,Einnahme,Dienstleistung,Honorar,"20,00",0',
        ]);

        $response = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/entries/import', [
            'file' => UploadedFile::fake()->createWithContent('buchungen.csv', "\xEF\xBB\xBF".$csv),
            'year' => 2026,
        ]);

        $response->assertOk()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('skipped', 0);
        $this->assertSame(123456, (int) EaEntry::forCurrentBrand()->orderBy('id')->first()->amount_cents);
        $this->assertSame(4, (int) EaEntry::forCurrentBrand()->orderBy('id')->first()->month);
        $this->assertSame(0, (int) EaEntry::forCurrentBrand()->orderByDesc('id')->first()->business_amount_cents);
    }

    public function test_import_rejects_unknown_year_and_non_csv_files(): void
    {
        $token = $this->tokenFor();
        $csv = "Datum;Art;Kategorie;Beschreibung;Betrag;Betriebsanteil\n05.05.2025;Einnahme;Art;Text;10,00;100";

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/entries/import', [
            'file' => UploadedFile::fake()->createWithContent('buchungen.csv', $csv),
            'year' => 2025,
        ])->assertUnprocessable()->assertJsonValidationErrors('year');

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/entries/import', [
            'file' => UploadedFile::fake()->create('script.pdf', 1, 'application/pdf'),
            'year' => 2026,
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('ea_entries', 0);
    }

    public function test_import_is_brand_scoped(): void
    {
        $token = $this->tokenFor();
        $csv = "Datum;Art;Kategorie;Beschreibung;Betrag;Betriebsanteil\n05.05.2026;Einnahme;Art;Text;10,00;100";

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/entries/import', [
            'file' => UploadedFile::fake()->createWithContent('buchungen.csv', $csv),
            'year' => 2026,
        ])->assertOk()->assertJsonPath('created', 1);

        $this->assertDatabaseCount('ea_entries', 1);
        $this->assertSame(1, EaEntry::forCurrentBrand()->count());
        $this->assertSame(0, EaEntry::where('brand', self::OTHER_BRAND)->count());
    }
}
