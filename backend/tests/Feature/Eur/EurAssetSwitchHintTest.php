<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;

/** DEG-013: nicht blockierende Wechsel-Erinnerung an saves Anlagegut. */
class EurAssetSwitchHintTest extends EurBookkeepingTestCase
{
    public function test_a_degressive_asset_carries_a_server_derived_switch_hint(): void
    {
        $token = $this->tokenFor();
        $this->createAsset('Degressiv', 'degressive');
        $this->createAsset('Linear', 'linear');

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $response->assertOk();

        $degressive = collect($response->json('assets'))->firstWhere('name', 'Degressiv');
        $linear = collect($response->json('assets'))->firstWhere('name', 'Linear');

        // 240.000 ct / 3 Jahre, 30 % degressiv, Anschaffung Januar 2026:
        // empfohlenes Wechseljahr 2 = 2027, Vorteil 336,00 €.
        $this->assertSame([
            'year' => 2027,
            'linear_cents' => 84000,
            'degressive_cents' => 50400,
            'advantage_cents' => 33600,
        ], $degressive['switch_hint']);
        $this->assertNull($linear['switch_hint']);
    }

    public function test_the_hint_never_blocks_a_booking_and_disappears_after_the_switch(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset('Degressiv', 'degressive');

        $before = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $this->assertNotNull(collect($before->json('assets'))->firstWhere('id', $asset->id)['switch_hint']);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/assets/'.$asset->id.'/book-depreciation', ['year' => 2026])
            ->assertCreated();

        $switch = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2027, 'reason' => 'Lineare AfA ist dauerhaft günstiger.'],
        );
        $switch->assertOk();
        $this->assertNull($switch->json('asset.switch_hint'));

        $after = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $this->assertNull(collect($after->json('assets'))->firstWhere('id', $asset->id)['switch_hint']);
    }

    private function createAsset(string $name, string $method): EurAsset
    {
        $payload = [
            'name' => $name,
            'invoice_no' => 'R-'.$name,
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'method' => $method,
        ];

        if ($method === 'degressive') {
            $payload['degressive_rate'] = 3000;
        }

        $response = $this->withHeaders($this->bearer($this->tokenFor()))->postJson('/api/management/eur/assets', $payload);
        $response->assertCreated();

        return EurAsset::forCurrentBrand()->findOrFail($response->json('asset.id'));
    }
}
