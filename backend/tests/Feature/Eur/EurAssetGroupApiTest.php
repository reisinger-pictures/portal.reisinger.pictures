<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetGroup;
use App\Models\EurEntry;
use App\Services\Eur\EurAssetCreationService;
use App\Services\Eur\EurAssetGroupService;

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

    public function test_group_share_drives_plan_and_booked_afa_for_a_member_with_a_different_share(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Set-Gut', 80, 240000, '2024-01-15');

        // Ohne Gruppe gilt der eigene Anteil: 80 % betrieblich / 20 % privat.
        $this->assertSame(20, (int) $asset->years()->first()->private_use_percent);
        $this->assertSame(16000, (int) $asset->years()->first()->private_use_cents);
        $this->assertSame(64000, (int) $asset->years()->first()->business_use_cents);

        $groupId = $this->createGroup($token, '60er-Set', 60);
        $this->assign($token, $groupId, $asset);

        // D-89: 60 % betrieblich / 40 % privat — nicht 80/20 des Guts.
        $fresh = $asset->fresh();
        $this->assertSame(80, (int) $fresh->business_share);
        $this->assertSame(60, $fresh->effectiveBusinessShare());
        $years = $fresh->years()->orderBy('year')->get();
        $this->assertCount(3, $years);
        foreach ($years as $year) {
            $this->assertSame(40, (int) $year->private_use_percent);
            $this->assertSame(
                (int) $year->depreciation_cents - (int) $year->private_use_cents,
                (int) $year->business_use_cents,
            );
        }
        $this->assertSame(32000, (int) $years[0]->private_use_cents);
        $this->assertSame(48000, (int) $years[0]->business_use_cents);
        $this->assertSame(32000, (int) $years[1]->private_use_cents);
        $this->assertSame(48000, (int) $years[1]->business_use_cents);
        $this->assertSame(32000, (int) $years[2]->private_use_cents);
        $this->assertSame(47999, (int) $years[2]->business_use_cents);

        $book = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2024],
        );
        $book->assertCreated();
        $book->assertJsonPath('entry.amount_cents', 48000);
        $book->assertJsonPath('entry.business_amount_cents', 48000);
        $this->assertSame(
            48000,
            (int) EurEntry::forCurrentBrand()->where('kind_type', 'AfA')->firstOrFail()->amount_cents,
        );
    }

    public function test_ungrouped_asset_keeps_its_own_share_for_plan_and_booked_afa(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Einzel-Gut', 80, 240000, '2024-01-15');

        $this->assertSame(80, $asset->effectiveBusinessShare());
        $year = $asset->years()->first();
        $this->assertSame(20, (int) $year->private_use_percent);
        $this->assertSame(16000, (int) $year->private_use_cents);
        $this->assertSame(64000, (int) $year->business_use_cents);

        $book = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2024],
        );
        $book->assertCreated();
        $book->assertJsonPath('entry.amount_cents', 64000);
        $book->assertJsonPath('entry.business_amount_cents', 64000);
    }

    public function test_unassign_restores_the_members_share_for_open_years(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Löse-Gut', 80, 240000, '2024-01-15');
        $groupId = $this->createGroup($token, 'Set', 60);
        $this->assign($token, $groupId, $asset);

        $this->assertSame(40, (int) $asset->years()->first()->private_use_percent);
        $this->assertSame(32000, (int) $asset->years()->first()->private_use_cents);

        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/eur/asset-groups/{$groupId}/assets/{$asset->id}")
            ->assertOk();

        $fresh = $asset->fresh();
        $this->assertNull($fresh->eur_asset_group_id);
        $this->assertSame(80, (int) $fresh->business_share);
        $this->assertSame(80, $fresh->effectiveBusinessShare());
        foreach ($fresh->years()->orderBy('year')->get() as $year) {
            $this->assertSame(20, (int) $year->private_use_percent);
        }
        $this->assertSame(16000, (int) $fresh->years()->first()->private_use_cents);
        $this->assertSame(64000, (int) $fresh->years()->first()->business_use_cents);

        $book = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2024],
        );
        $book->assertCreated();
        $book->assertJsonPath('entry.amount_cents', 64000);
    }

    public function test_recalculation_leaves_a_booked_year_untouched(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Gebucht', 80, 240000, '2024-01-15');
        $groupId = $this->createGroup($token, 'Set', 60);
        $this->assign($token, $groupId, $asset);

        $booked = $asset->years()->orderBy('year')->first();
        $booked->forceFill([
            'status' => 'booked',
            'private_use_percent' => 40,
            'private_use_cents' => 32000,
            'business_use_cents' => 48000,
        ])->save();

        // Das Trennen ist mit gebuchtem Jahr gesperrt; der Guard der
        // Neuberechnung selbst wird hier direkt geprüft.
        $detached = $asset->fresh();
        $detached->forceFill(['eur_asset_group_id' => null])->save();
        $updated = $this->app->make(EurAssetCreationService::class)->recalculateUnbookedPlanShares($detached);

        $this->assertSame(2, $updated);
        $bookedRow = $detached->years()->where('year', 2024)->firstOrFail();
        $this->assertSame(40, (int) $bookedRow->private_use_percent);
        $this->assertSame(32000, (int) $bookedRow->private_use_cents);
        $this->assertSame(48000, (int) $bookedRow->business_use_cents);
        $open = $detached->years()->where('year', 2025)->firstOrFail();
        $this->assertSame(20, (int) $open->private_use_percent);
        $this->assertSame(16000, (int) $open->private_use_cents);
        $this->assertSame(64000, (int) $open->business_use_cents);
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

    public function test_changing_a_group_share_with_a_booked_member_is_refused(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Gebucht', 80, 240000);
        $groupId = $this->createGroup($token, 'Gesperrt', 60);
        $this->assign($token, $groupId, $asset);
        $asset->years()->first()->update(['status' => 'booked']);

        $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/eur/asset-groups/{$groupId}", [
                'name' => 'Gesperrt', 'business_share' => 70,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error', EurAssetGroupService::BOOKED_GROUP_MESSAGE);

        $this->assertSame(60, (int) EurAssetGroup::findOrFail($groupId)->business_share);
    }

    public function test_unassigning_a_booked_member_is_refused(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Gebucht', 80, 240000);
        $groupId = $this->createGroup($token, 'Gesperrt', 60);
        $this->assign($token, $groupId, $asset);
        $asset->years()->first()->update(['status' => 'booked']);

        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/eur/asset-groups/{$groupId}/assets/{$asset->id}")
            ->assertUnprocessable()
            ->assertJsonPath('error', EurAssetGroupService::BOOKED_GROUP_MESSAGE);

        $this->assertSame($groupId, (int) $asset->fresh()->eur_asset_group_id);
        $this->assertSame([$asset->id], $this->group($token, $groupId)['asset_ids']);
    }

    public function test_deleting_a_group_with_a_booked_member_is_refused(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Gebucht', 80, 240000);
        $groupId = $this->createGroup($token, 'Gesperrt', 60);
        $this->assign($token, $groupId, $asset);
        $asset->years()->first()->update(['status' => 'booked']);

        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/eur/asset-groups/{$groupId}")
            ->assertUnprocessable()
            ->assertJsonPath('error', EurAssetGroupService::BOOKED_GROUP_MESSAGE);

        $this->assertNotNull(EurAssetGroup::find($groupId));
        $this->assertSame($groupId, (int) $asset->fresh()->eur_asset_group_id);
    }

    public function test_assigning_into_a_group_with_a_booked_member_is_refused(): void
    {
        $token = $this->tokenFor();
        $booked = $this->createAsset($token, 'Gebucht', 80, 240000);
        $groupId = $this->createGroup($token, 'Gesperrt', 60);
        $this->assign($token, $groupId, $booked);
        $booked->years()->first()->update(['status' => 'booked']);
        $new = $this->createAsset($token, 'Neu', 80, 60000);

        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/asset-groups/{$groupId}/assets", ['asset_id' => $new->id])
            ->assertUnprocessable()
            ->assertJsonPath('error', EurAssetGroupService::BOOKED_GROUP_MESSAGE);
        $this->assertNull($new->fresh()->eur_asset_group_id);

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Direkt', 'invoice_no' => 'G-Direkt', 'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15', 'cost_cents' => 60000,
            'useful_life_years' => 3, 'business_share' => 80, 'method' => 'linear',
            'eur_asset_group_id' => $groupId,
        ])->assertUnprocessable()->assertJsonPath('error', EurAssetGroupService::BOOKED_GROUP_MESSAGE);
        $this->assertSame([$booked->id], $this->group($token, $groupId)['asset_ids']);
    }

    public function test_an_unbooked_group_still_allows_share_changes_and_unassign(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Frei', 80, 240000);
        $groupId = $this->createGroup($token, 'Offen', 60);
        $this->assign($token, $groupId, $asset);

        $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/eur/asset-groups/{$groupId}", [
                'name' => 'Offen', 'business_share' => 70,
            ])->assertOk();

        $this->assertSame(70, (int) EurAssetGroup::findOrFail($groupId)->business_share);
        // 240000 ct × 30 % privat = 72000 ct, neu berechnet aus dem Gruppenanteil.
        $this->assertSame(
            72000,
            (int) EurEntry::forCurrentBrand()->where('receipt_no', 'GR-'.$groupId)->firstOrFail()->amount_cents,
        );

        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/eur/asset-groups/{$groupId}/assets/{$asset->id}")
            ->assertOk();
        $this->assertNull($asset->fresh()->eur_asset_group_id);
    }

    public function test_changing_the_group_share_updates_every_members_scope_to_the_effective_share(): void
    {
        $token = $this->tokenFor();
        $first = $this->createAsset($token, 'Erstes', 80, 240000);
        $second = $this->createAsset($token, 'Zweites', 80, 60000);
        $groupId = $this->createGroup($token, 'Grenz-Set', 60);
        $this->assign($token, $groupId, $first);
        $this->assign($token, $groupId, $second);
        $this->assertSame('business', $first->fresh()->scope);
        $this->assertSame('business', $second->fresh()->scope);

        // 60 % → 40 %: der Gruppenanteil fällt unter 50 %, beide Mitglieder
        // wechseln in das private Verzeichnis, obwohl ihr eigener Anteil 80 % ist.
        $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/eur/asset-groups/{$groupId}", [
                'name' => 'Grenz-Set', 'business_share' => 40,
            ])->assertOk();

        foreach ([$first, $second] as $member) {
            $fresh = $member->fresh();
            $this->assertSame(40, $fresh->effectiveBusinessShare());
            $this->assertSame('private', $fresh->scope);
            $this->assertSame($groupId, (int) $fresh->eur_asset_group_id);
        }

        // 40 % → 50 %: genau 50 % ist betrieblich (D-89: "< 50" → privat).
        $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/eur/asset-groups/{$groupId}", [
                'name' => 'Grenz-Set', 'business_share' => 50,
            ])->assertOk();

        $this->assertSame('business', $first->fresh()->scope);
        $this->assertSame('business', $second->fresh()->scope);
    }

    public function test_a_booked_member_prevents_both_share_and_scope_changes(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset($token, 'Gebucht', 80, 240000);
        $groupId = $this->createGroup($token, 'Gesperrt', 60);
        $this->assign($token, $groupId, $asset);
        $this->assertSame('business', $asset->fresh()->scope);
        $asset->years()->first()->update(['status' => 'booked']);

        $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/eur/asset-groups/{$groupId}", [
                'name' => 'Gesperrt', 'business_share' => 30,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error', EurAssetGroupService::BOOKED_GROUP_MESSAGE);

        $this->assertSame(60, (int) EurAssetGroup::findOrFail($groupId)->business_share);
        $fresh = $asset->fresh();
        $this->assertSame(60, $fresh->effectiveBusinessShare());
        $this->assertSame('business', $fresh->scope);
        $this->assertSame($groupId, (int) $fresh->eur_asset_group_id);
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
