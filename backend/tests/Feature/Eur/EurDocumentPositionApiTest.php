<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetDocument;
use App\Models\EurAssetGroup;
use App\Models\EurDocument;
use App\Models\EurEntry;
use App\Services\Eur\EurAssetGroupService;
use App\Support\BrandRegistry;
use Illuminate\Testing\TestResponse;

/** Portal #59/D-90: 1 Beleg → N Items und N Belege → 1 Gruppe. */
class EurDocumentPositionApiTest extends EurBookkeepingTestCase
{
    public function test_one_document_splits_into_two_independently_billed_assets(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-SPLIT-1', 320000);

        $this->split($token, $document, [
            $this->position('Objektiv', 240000),
            $this->position('Blende', 60000),
        ])->assertCreated()
            ->assertJsonPath('document.allocated_cents', 300000)
            ->assertJsonPath('document.remaining_cents', 20000);

        $assets = EurAsset::forCurrentBrand()->orderBy('id')->get();
        $this->assertCount(2, $assets);
        $this->assertSame([240000, 60000], $assets->pluck('cost_cents')->map(static fn (mixed $c): int => (int) $c)->all());

        foreach ($assets as $asset) {
            $this->assertSame('R-SPLIT-1', $asset->invoice_no);
            $this->assertNull($asset->eur_asset_group_id);
            $this->assertCount(3, $asset->years);
            // Jedes Gut hat seinen eigenen privaten Eigenbeleg.
            $this->assertSame(3, $asset->years()->whereNotNull('eigenbeleg_no')->count());
        }

        // Zwei Positionen mit gemeinsamer Belegnummer → zwei eigene Entnahmen.
        $entnahmen = EurEntry::forCurrentBrand()
            ->where('receipt_no', 'R-SPLIT-1')
            ->where('kind_type', 'Entnahme')
            ->get();
        $this->assertCount(2, $entnahmen);
        $amounts = $entnahmen->pluck('amount_cents')->map(static fn (mixed $c): int => (int) $c)->sort()->values()->all();
        $this->assertSame([60000, 240000], $amounts);

        $listed = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/documents')
            ->assertOk()
            ->json('documents.0');
        $this->assertSame(320000, $listed['total_cents']);
        $this->assertSame(300000, $listed['allocated_cents']);
        $this->assertSame(20000, $listed['remaining_cents']);
        $this->assertCount(2, $listed['positions']);
        $this->assertArrayNotHasKey('storage_path', $listed);
        $this->assertSame(
            $entnahmen->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all(),
            $listed['linked_entry_ids'],
        );
    }

    public function test_over_allocation_is_refused(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-OVER', 100000);

        $this->split($token, $document, [
            $this->position('Erste', 60000),
            $this->position('Zweite', 40001),
        ])->assertUnprocessable()->assertJsonPath('error', 'Die Summe der Positionen darf den Belegbetrag nicht überschreiten.');

        $this->assertSame(0, EurAsset::forCurrentBrand()->count());
        $this->assertSame(0, EurAssetDocument::forCurrentBrand()->count());
        $this->assertSame(100000, (int) $document->fresh()->total_cents);
    }

    public function test_a_second_split_of_the_same_document_is_refused(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-DOUBLE', 100000);

        $this->split($token, $document, [$this->position('Erste', 60000)])->assertCreated();
        $this->split($token, $document, [$this->position('Zweite', 40000)])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Dieser Beleg wurde bereits aufgeteilt. Eine zweite Aufteilung ist nicht zulässig.');

        $this->assertSame(1, EurAsset::forCurrentBrand()->count());
    }

    public function test_deleting_a_position_removes_only_the_link(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-DELETE', 320000);
        $this->split($token, $document, [
            $this->position('Objektiv', 240000),
            $this->position('Blende', 60000),
        ])->assertCreated();

        $pivot = EurAssetDocument::forCurrentBrand()->where('amount_cents', 240000)->firstOrFail();
        $assetId = (int) $pivot->eur_asset_id;

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/management/eur/documents/positions/'.$pivot->id)
            ->assertOk();

        $this->assertNull(EurAssetDocument::find($pivot->id));
        $asset = EurAsset::forCurrentBrand()->findOrFail($assetId);
        $this->assertSame(240000, (int) $asset->cost_cents);
        $this->assertSame(3, $asset->years()->whereNotNull('eigenbeleg_no')->count());
        $this->assertSame(2, EurEntry::forCurrentBrand()
            ->where('receipt_no', 'R-DELETE')->where('kind_type', 'Entnahme')->count());

        $positions = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/documents/'.$document->id.'/positions')
            ->assertOk()
            ->json('document');
        $this->assertCount(1, $positions['positions']);
        $this->assertSame(60000, $positions['allocated_cents']);
        $this->assertSame(260000, $positions['remaining_cents']);
    }

    public function test_two_documents_merge_into_one_business_group_with_a_single_withdrawal(): void
    {
        $token = $this->tokenFor();
        $first = $this->document('R-MERGE-1', 240000);
        $second = $this->document('R-MERGE-2', 60000);

        $this->split($token, $first, [$this->position('Objektiv', 240000, 100)])->assertCreated();
        $this->split($token, $second, [$this->position('Blende', 60000, 100)])->assertCreated();

        $groupId = $this->createGroup($token, 'Merge-Set', 60);
        $this->assignGroup($token, $first, $groupId)->assertOk();
        $response = $this->assignGroup($token, $second, $groupId)->assertOk();

        $this->assertSame(
            [$first->id, $second->id],
            array_map(static fn (mixed $id): int => (int) $id, $response->json('group.document_ids')),
        );
        $this->assertSame(0, EurAsset::forCurrentBrand()->whereNull('eur_asset_group_id')->count());

        $entnahmen = EurEntry::forCurrentBrand()->where('kind_type', 'Entnahme')->get();
        $this->assertCount(1, $entnahmen);
        $this->assertSame('GR-'.$groupId, $entnahmen->first()->receipt_no);
        $this->assertSame(120000, (int) $entnahmen->first()->amount_cents);
        $this->assertSame(0, EurEntry::forCurrentBrand()
            ->whereIn('receipt_no', ['R-MERGE-1', 'R-MERGE-2'])
            ->where('kind_type', 'Entnahme')->count());
    }

    public function test_two_documents_merge_into_one_private_group_with_one_eigenbeleg_per_year(): void
    {
        $this->travelTo('2026-03-01 09:00:00');
        $token = $this->tokenFor();
        $first = $this->document('R-PRIV-1', 240000, 2024);
        $second = $this->document('R-PRIV-2', 60000, 2024);

        $this->split($token, $first, [$this->position('Objektiv', 240000, 100)])->assertCreated();
        $this->split($token, $second, [$this->position('Blende', 60000, 100)])->assertCreated();

        $groupId = $this->createGroup($token, 'Privat-Merge', 30);
        $this->assignGroup($token, $first, $groupId)->assertOk();
        $this->assignGroup($token, $second, $groupId)->assertOk();

        $group = EurAssetGroup::findOrFail($groupId);
        $this->assertCount(3, $group->years);
        foreach ($group->years as $year) {
            $this->assertStringStartsWith('EBG-', (string) $year->eigenbeleg_no);
            $this->assertSame(30000, (int) $year->eigenbeleg_cents);
        }

        foreach (EurAsset::forCurrentBrand()->get() as $asset) {
            $this->assertSame(0, $asset->years()->whereNotNull('eigenbeleg_no')->count());
        }
        $this->assertSame(0, EurEntry::forCurrentBrand()->where('kind_type', 'Entnahme')->count());
    }

    public function test_a_booked_year_refuses_group_assignment(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-BOOKED', 100000);
        $this->split($token, $document, [$this->position('Gebucht', 100000, 100)])->assertCreated();

        $asset = EurAsset::forCurrentBrand()->firstOrFail();
        $asset->years()->first()->update(['status' => 'booked']);
        $groupId = $this->createGroup($token, 'Sperr-Set', 60);

        $this->assignGroup($token, $document, $groupId)->assertUnprocessable();
        $this->assertNull($asset->fresh()->eur_asset_group_id);
    }

    public function test_switching_from_a_group_with_a_booked_member_is_refused(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-BOOKED-SWITCH', 240000, 2024);
        $this->split($token, $document, [$this->position('Gebucht', 240000)])->assertCreated();
        $asset = EurAsset::forCurrentBrand()->firstOrFail();
        $oldGroup = $this->createGroup($token, 'Alt', 60);
        $this->assignGroup($token, $document, $oldGroup)->assertOk();
        $asset->years()->first()->update(['status' => 'booked']);
        $newGroup = $this->createGroup($token, 'Neu', 70);

        $this->assignGroup($token, $document, $newGroup)
            ->assertUnprocessable()
            ->assertJsonPath('error', EurAssetGroupService::BOOKED_GROUP_MESSAGE);

        $this->assertSame($oldGroup, (int) $asset->fresh()->eur_asset_group_id);
        $this->assertSame(
            [(int) $asset->id],
            array_map('intval', EurAssetGroup::findOrFail($oldGroup)->assets()->pluck('id')->all()),
        );
    }

    public function test_detaching_a_group_with_a_booked_member_is_refused(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-BOOKED-DETACH', 240000, 2024);
        $this->split($token, $document, [$this->position('Gebucht', 240000)])->assertCreated();
        $asset = EurAsset::forCurrentBrand()->firstOrFail();
        $groupId = $this->createGroup($token, 'Gesperrt', 60);
        $this->assignGroup($token, $document, $groupId)->assertOk();
        $asset->years()->first()->update(['status' => 'booked']);

        $this->assignGroup($token, $document, null)
            ->assertUnprocessable()
            ->assertJsonPath('error', EurAssetGroupService::BOOKED_GROUP_MESSAGE);

        $this->assertSame($groupId, (int) $asset->fresh()->eur_asset_group_id);
        $this->assertSame(0, EurEntry::forCurrentBrand()
            ->where('receipt_no', 'R-BOOKED-DETACH')->where('kind_type', 'Entnahme')->count());
    }

    public function test_foreign_brand_document_and_group_are_not_found(): void
    {
        $token = $this->tokenFor();
        $foreignDocument = $this->document('R-FOREIGN', 100000, 2026, self::OTHER_BRAND);
        $foreignGroup = EurAssetGroup::create([
            'brand' => self::OTHER_BRAND,
            'name' => 'Fremdgruppe',
            'business_share' => 60,
        ]);
        $ownDocument = $this->document('R-OWN', 100000);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/documents/'.$foreignDocument->id.'/positions')
            ->assertNotFound();
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/documents/'.$foreignDocument->id.'/positions', [
                'positions' => [$this->position('Fremd', 100000)],
            ])->assertNotFound();
        $this->assignGroup($token, $foreignDocument, null)->assertNotFound();
        $this->assignGroup($token, $ownDocument, (int) $foreignGroup->id)->assertNotFound();

        $this->assertSame(0, EurAsset::forCurrentBrand()->count());
    }

    public function test_detaching_a_group_restores_individual_bookkeeping(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-DETACH', 300000, 2024);
        $this->split($token, $document, [
            $this->position('Objektiv', 240000),
            $this->position('Blende', 60000),
        ])->assertCreated();

        $groupId = $this->createGroup($token, 'Detach-Set', 30);
        $this->assignGroup($token, $document, $groupId)->assertOk();

        foreach (EurAsset::forCurrentBrand()->get() as $asset) {
            $this->assertSame(0, $asset->years()->whereNotNull('eigenbeleg_no')->count());
        }
        $this->assertSame(0, EurEntry::forCurrentBrand()
            ->where('receipt_no', 'R-DETACH')->where('kind_type', 'Entnahme')->count());

        $this->assignGroup($token, $document, null)->assertOk();

        foreach (EurAsset::forCurrentBrand()->get() as $asset) {
            $this->assertNull($asset->fresh()->eur_asset_group_id);
            $this->assertSame(3, $asset->years()->whereNotNull('eigenbeleg_no')->count());
        }
        $entnahmen = EurEntry::forCurrentBrand()
            ->where('receipt_no', 'R-DETACH')->where('kind_type', 'Entnahme')->get();
        $this->assertCount(2, $entnahmen);
        $this->assertSame([60000, 240000], $entnahmen->pluck('amount_cents')
            ->map(static fn (mixed $c): int => (int) $c)->sort()->values()->all());
    }

    private function document(string $receiptNo, int $totalCents, int $year = 2026, ?string $brand = null): EurDocument
    {
        return EurDocument::create([
            'brand' => $brand ?? BrandRegistry::currentId(),
            'year' => $year,
            'month' => 5,
            'receipt_no' => $receiptNo,
            'original_name' => $receiptNo.'.pdf',
            'storage_path' => 'eur/'.($brand ?? BrandRegistry::currentId()).'/'.$receiptNo.'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', $receiptNo),
            'total_cents' => $totalCents,
            'status' => 'uploaded',
        ]);
    }

    /**
     * @return array{name: string, label: string, amount_cents: int, business_share: int, useful_life_years: int, method: string}
     */
    private function position(string $name, int $cents, int $businessShare = 0): array
    {
        return [
            'name' => $name,
            'label' => $name.' Position',
            'amount_cents' => $cents,
            'business_share' => $businessShare,
            'useful_life_years' => 3,
            'method' => 'linear',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     */
    private function split(string $token, EurDocument $document, array $positions, ?int $total = null): TestResponse
    {
        return $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/documents/'.$document->id.'/positions',
            ['total_cents' => $total, 'positions' => $positions],
        );
    }

    public function test_grouping_one_split_asset_keeps_its_siblings_individual_entnahme(): void
    {
        $token = $this->tokenFor();
        $document = $this->document('R-GROUP-SPLIT', 300000);
        $this->split($token, $document, [
            $this->position('Gut A', 200000, 90),
            $this->position('Gut B', 100000, 90),
        ])->assertCreated();

        $assets = EurAsset::forCurrentBrand()->orderBy('id')->get();
        $this->assertCount(2, $assets);
        $groupId = $this->createGroup($token, 'Nur-eine-Position', 60);
        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/asset-groups/{$groupId}/assets", ['asset_id' => $assets->first()->id])
            ->assertOk();

        // Regressionsfall: ohne Positionsschlüssel löschte das Gruppieren
        // die Entnahme des ungruppierten Geschwisters mit derselben Nummer.
        $remaining = EurEntry::forCurrentBrand()
            ->where('receipt_no', 'R-GROUP-SPLIT')
            ->where('kind_type', 'Entnahme')
            ->get();
        $this->assertCount(1, $remaining);
        $this->assertStringContainsString('Gut B', (string) $remaining->first()->description);
        $this->assertNull($assets->last()->fresh()->eur_asset_group_id);
    }

    private function createGroup(string $token, string $name, int $share): int
    {
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/asset-groups', [
            'name' => $name,
            'business_share' => $share,
        ]);
        $response->assertCreated();

        return (int) $response->json('group.id');
    }

    private function assignGroup(string $token, EurDocument $document, ?int $groupId): TestResponse
    {
        return $this->withHeaders($this->bearer($token))->putJson(
            '/api/management/eur/documents/'.$document->id.'/group',
            ['group_id' => $groupId],
        );
    }
}
