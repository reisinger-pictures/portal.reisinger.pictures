<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetGroup;
use App\Models\EurEntry;

/** Portal #57: Anlagegruppen als eine Abrechnungseinheit. */
class EurAssetGroupApiTest extends EurBookkeepingTestCase
{
    public function test_business_group_creates_one_entnahme_for_all_members(): void
    {
        $token = $this->tokenFor();
        $first = $this->createAsset($token, 'Objektiv', 80, 240000);
        $second = $this->createAsset($token, 'Streulichtblende', 80, 60000);
        $groupId = $this->createGroup($token, 'Objektiv-Set', 60);

        foreach ([$first, $second] as $asset) {
            $this->assign($token, $groupId, $asset);
        }

        // 300000 ct × 40 % privat = 120000 ct, genau eine Buchung.
        $entries = EurEntry::forCurrentBrand()->where('kind_type', 'Entnahme')->get();
        $this->assertCount(1, $entries);
        $this->assertSame('GR-'.$groupId, $entries->first()->receipt_no);
        $this->assertSame(120000, (int) $entries->first()->amount_cents);
        $this->assertSame('income', $entries->first()->direction);
        $this->assertSame('9040', $entries->first()->e1a_code);
        $this->assertSame(0, EurEntry::forCurrentBrand()
            ->whereIn('receipt_no', [$first->invoice_no, $second->invoice_no])
            ->where('kind_type', 'Entnahme')->count());

        $group = $this->group($token, $groupId);
        $this->assertSame('business', $group['scope']);
        $this->assertSame([$first->id, $second->id], $group['asset_ids']);
    }

    public function test_private_group_creates_one_eigenbeleg_per_year_over_all_members(): void
    {
        $token = $this->tokenFor();
        $first = $this->createAsset($token, 'Objektiv', 100, 240000, '2024-01-15');
        $second = $this->createAsset($token, 'Blende', 100, 60000, '2024-01-15');
        $groupId = $this->createGroup($token, 'Privat-Set', 30);

        foreach ([$first, $second] as $asset) {
            $this->assign($token, $groupId, $asset);
        }

        $group = EurAssetGroup::findOrFail($groupId);
        $years = $group->years;
        $this->assertCount(3, $years);
        // (240000/3 + 60000/3) × 30 % = 100000 × 30 % = 30000 ct je Jahr.
        foreach ($years as $year) {
            $this->assertSame(100000, (int) $year->depreciation_cents);
            $this->assertSame(30000, (int) $year->eigenbeleg_cents);
            $this->assertSame(30, (int) $year->usage_percent);
            $this->assertStringStartsWith('EBG-', (string) $year->eigenbeleg_no);
        }
        $this->assertSame(0, EurEntry::forCurrentBrand()->where('kind_type', 'Entnahme')->count());
        $this->assertSame(0, $first->years()->whereNotNull('eigenbeleg_no')->count());
        $this->assertSame(0, $second->years()->whereNotNull('eigenbeleg_no')->count());
        $this->assertSame('private', $this->group($token, $groupId)['scope']);
    }

    public function test_group_share_overrides_member_share_and_scope(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Gut', 100, 240000); // einzeln betrieblich
        $this->assertSame('business', $asset->fresh()->scope);

        $groupId = $this->createGroup($token, 'Privat-Gruppe', 30);
        $this->assign($token, $groupId, $asset);

        $this->assertSame('private', $asset->fresh()->scope);
        $this->assertSame(30, (int) EurAssetGroup::findOrFail($groupId)->business_share);
    }

    public function test_a_booked_asset_cannot_be_assigned(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Gebucht', 100, 240000);
        $asset->years()->first()->update(['status' => 'booked']);
        $groupId = $this->createGroup($token, 'Set', 60);

        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/asset-groups/{$groupId}/assets", ['asset_id' => $asset->id])
            ->assertUnprocessable();
        $this->assertNull($asset->fresh()->eur_asset_group_id);
    }

    public function test_unassign_restores_the_individual_entnahme(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Gut', 80, 240000);
        $groupId = $this->createGroup($token, 'Set', 60);
        $this->assign($token, $groupId, $asset);

        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/eur/asset-groups/{$groupId}/assets/{$asset->id}")
            ->assertOk();

        $this->assertNull($asset->fresh()->eur_asset_group_id);
        $this->assertSame(1, EurEntry::forCurrentBrand()
            ->where('receipt_no', $asset->invoice_no)->where('kind_type', 'Entnahme')->count());
    }

    public function test_unassign_restores_individual_eigenbelege_for_a_private_member(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Privat-Gut', 30, 240000, '2024-01-15');
        $groupId = $this->createGroup($token, 'Privat-Set', 30);
        $this->assign($token, $groupId, $asset);

        $this->assertSame(0, $asset->fresh()->years()->whereNotNull('eigenbeleg_no')->count());

        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/eur/asset-groups/{$groupId}/assets/{$asset->id}")
            ->assertOk();

        $this->assertSame('private', $asset->fresh()->scope);
        $this->assertSame(3, $asset->years()->whereNotNull('eigenbeleg_no')->count());
        $this->assertSame(24000, (int) $asset->years()->first()->eigenbeleg_cents);
    }

    public function test_duplicate_group_name_is_a_validation_error(): void
    {
        $token = $this->tokenFor();
        $this->createGroup($token, 'Doppelt', 60);

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/asset-groups', [
            'name' => 'Doppelt', 'business_share' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_foreign_brand_asset_cannot_be_assigned(): void
    {
        $token = $this->tokenFor();
        $foreign = EurAsset::create([
            'brand' => self::OTHER_BRAND,
            'name' => 'Fremd', 'invoice_no' => 'G-Fremd', 'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15', 'cost_cents' => 240000,
            'useful_life_years' => 3, 'business_share' => 100,
            'scope' => 'business', 'method' => 'linear',
        ]);
        $groupId = $this->createGroup($token, 'Set', 60);

        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/asset-groups/{$groupId}/assets", ['asset_id' => $foreign->id])
            ->assertUnprocessable();
        $this->assertNull($foreign->fresh()->eur_asset_group_id);
    }

    public function test_switching_an_asset_between_groups_restores_individual_booking(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Wechsel', 80, 240000);
        $oldGroup = $this->createGroup($token, 'Alt', 60);
        $newGroup = $this->createGroup($token, 'Neu', 70);
        $this->assign($token, $oldGroup, $asset);

        // Regressionsfall: der Controller verwies auf ein nicht mehr
        // existierendes Property und lieferte hier einen 500-Fehler.
        $this->assign($token, $newGroup, $asset);

        $this->assertSame($newGroup, (int) $asset->fresh()->eur_asset_group_id);
        $this->assertSame([$asset->id], $this->group($token, $newGroup)['asset_ids']);
        $this->assertSame([], $this->group($token, $oldGroup)['asset_ids']);
        $entnahmen = EurEntry::forCurrentBrand()->where('kind_type', 'Entnahme')->get();
        $this->assertCount(1, $entnahmen);
        $this->assertSame('GR-'.$newGroup, $entnahmen->first()->receipt_no);
    }

    private function createAsset(string $token, string $name, int $share, int $cost, string $date = '2026-01-15'): EurAsset
    {
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => $name, 'invoice_no' => 'G-'.$name, 'supplier' => 'Lieferant',
            'purchase_date' => $date, 'cost_cents' => $cost,
            'useful_life_years' => 3, 'business_share' => $share, 'method' => 'linear',
        ]);
        $response->assertCreated();

        return EurAsset::forCurrentBrand()->findOrFail((int) $response->json('asset.id'));
    }

    private function createGroup(string $token, string $name, int $share): int
    {
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/asset-groups', [
            'name' => $name, 'business_share' => $share,
        ]);
        $response->assertCreated();

        return (int) $response->json('group.id');
    }

    private function assign(string $token, int $groupId, EurAsset $asset): void
    {
        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/asset-groups/{$groupId}/assets", ['asset_id' => $asset->id])
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $token, int $id): array
    {
        $groups = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/asset-groups')->assertOk()->json('groups');

        return collect($groups)->firstWhere('id', $id);
    }
}
