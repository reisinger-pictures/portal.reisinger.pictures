<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurDocument;
use App\Models\EurEntry;
use App\Services\Eur\EurEntnahmeService;
use App\Support\BrandRegistry;

/** EUE-055: automatische Entnahme-Einnahmebuchung bei Anschaffung. */
class EurEntnahmeApiTest extends EurBookkeepingTestCase
{
    public function test_a_private_share_creates_an_income_entry_under_the_asset_receipt_no(): void
    {
        $token = $this->tokenFor();
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Teilprivates Gut',
            'invoice_no' => 'ENT-1',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 30,
            'method' => 'linear',
        ]);
        $response->assertCreated();

        $entry = EurEntry::forCurrentBrand()->where('receipt_no', 'ENT-1')->firstOrFail();
        $this->assertSame('income', $entry->direction);
        $this->assertSame('Entnahme', $entry->kind_type);
        $this->assertSame('9040', $entry->e1a_code);
        $this->assertSame(2026, (int) $entry->year);
        $this->assertSame(1, (int) $entry->month);
        $this->assertSame(15, (int) $entry->day);
        $this->assertSame(100, (int) $entry->business_share);
        // 240000 ct × 70 % privat = 168000 ct, ganzzahlige Cent.
        $this->assertSame(168000, (int) $entry->amount_cents);
        $this->assertSame(168000, (int) $entry->business_amount_cents);
        $this->assertSame(1, EurEntry::forCurrentBrand()->count());

        $response->assertJsonPath('asset.entnahme.done', true);
        $response->assertJsonPath('asset.entnahme.entry_id', $entry->id);
        $response->assertJsonPath('asset.entnahme.amount_cents', 168000);
    }

    public function test_a_fully_business_asset_gets_no_entnahme_entry(): void
    {
        $token = $this->tokenFor();
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Betriebliches Gut',
            'invoice_no' => 'ENT-2',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'method' => 'linear',
        ]);
        $response->assertCreated();

        $this->assertSame(0, EurEntry::forCurrentBrand()->count());
        $response->assertJsonPath('asset.entnahme.done', false);
        $response->assertJsonPath('asset.entnahme.entry_id', null);
    }

    public function test_a_legacy_asset_without_entnahme_raises_eue_055_but_is_not_blocked(): void
    {
        $asset = EurAsset::create([
            'brand' => BrandRegistry::currentId(),
            'name' => 'Altgut',
            'invoice_no' => 'ENT-3',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 30,
            'scope' => 'private',
            'method' => 'linear',
        ]);

        $token = $this->tokenFor();
        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $response->assertOk();
        $row = collect($response->json('assets'))->firstWhere('id', $asset->id);
        $this->assertContains('EUE-055', array_column($row['warnings'], 'code'));
        $this->assertFalse($row['entnahme']['done']);
        $this->assertSame(0, EurEntry::forCurrentBrand()->count());
    }

    public function test_a_deleted_split_entnahme_is_missing_only_for_its_own_position(): void
    {
        $token = $this->tokenFor();
        $document = EurDocument::create([
            'brand' => BrandRegistry::currentId(),
            'year' => 2026, 'month' => 5,
            'receipt_no' => 'ENT-SPLIT',
            'original_name' => 'ENT-SPLIT.pdf',
            'storage_path' => 'eur/'.BrandRegistry::currentId().'/ENT-SPLIT.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', 'ENT-SPLIT'),
            'total_cents' => 300000,
            'status' => 'uploaded',
        ]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/documents/'.$document->id.'/positions', [
                'positions' => [
                    [
                        'name' => 'Gut A', 'label' => 'A Position', 'amount_cents' => 200000,
                        'business_share' => 0, 'useful_life_years' => 3, 'method' => 'linear',
                    ],
                    [
                        'name' => 'Gut B', 'label' => 'B Position', 'amount_cents' => 100000,
                        'business_share' => 0, 'useful_life_years' => 3, 'method' => 'linear',
                    ],
                ],
            ])->assertCreated();

        $assets = EurAsset::forCurrentBrand()->orderBy('id')->get()->keyBy('name');
        $service = app(EurEntnahmeService::class);
        $entryA = EurEntry::forCurrentBrand()
            ->where('receipt_no', 'ENT-SPLIT')
            ->where('description', 'like', '%Gut A%')
            ->firstOrFail();
        $this->assertSame((int) $entryA->id, $service->status($assets['Gut A'])['entry_id']);
        $entryA->delete();

        // Beide Positionen teilen dieselbe Belegnummer; nur die gelöschte
        // Entnahme darf EUE-055 auslösen.
        $this->assertFalse($service->status($assets['Gut A'])['done']);
        $this->assertTrue($service->status($assets['Gut B'])['done']);
        $this->assertSame(100000, $service->status($assets['Gut B'])['amount_cents']);

        $rows = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/assets')->assertOk()->json('assets');
        $rowA = collect($rows)->firstWhere('id', $assets['Gut A']->id);
        $rowB = collect($rows)->firstWhere('id', $assets['Gut B']->id);
        $this->assertContains('EUE-055', array_column($rowA['warnings'], 'code'));
        $this->assertNotContains('EUE-055', array_column($rowB['warnings'], 'code'));
    }

    public function test_foreign_brand_receipt_numbers_do_not_count_as_entnahme(): void
    {
        EurAsset::create([
            'brand' => self::OTHER_BRAND,
            'name' => 'Fremdgut',
            'invoice_no' => 'ENT-4',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 30,
            'scope' => 'private',
            'method' => 'linear',
        ]);
        EurEntry::create([
            'brand' => self::OTHER_BRAND,
            'year' => 2026, 'month' => 1, 'day' => 15,
            'direction' => 'income', 'kind_type' => 'Entnahme',
            'business_share' => 100, 'amount_cents' => 168000,
            'business_amount_cents' => 168000, 'receipt_no' => 'ENT-4',
        ]);

        $token = $this->tokenFor();
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Eigenes Gut',
            'invoice_no' => 'ENT-4',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 30,
            'method' => 'linear',
        ]);
        $response->assertCreated();
        $response->assertJsonPath('asset.entnahme.done', true);
        $this->assertSame(1, EurEntry::forCurrentBrand()->count());
    }
}
