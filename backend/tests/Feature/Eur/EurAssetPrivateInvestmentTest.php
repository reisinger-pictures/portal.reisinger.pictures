<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurEntry;

/** #34: Anzeige statt Rechner, Kategorievorschlag, Einmal-Wechsel, Privat-AV, Jahresbuchung. */
class EurAssetPrivateInvestmentTest extends EurBookkeepingTestCase
{
    public function test_compare_returns_first_year_amounts_and_a_catalogue_category(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->getJson(
            '/api/management/eur/assets/compare?cost_cents=2000000&useful_life_years=10'
            .'&degressive_rate=3000&acquisition_month=1&business_share=80&name=Firmen-Laptop'
        );

        $response->assertOk();
        $response->assertJsonPath('first_year.linear_cents', 200000);
        $response->assertJsonPath('first_year.degressive_cents', 600000);
        $response->assertJsonPath('first_year.linear_business_cents', 160000);
        $response->assertJsonPath('first_year.degressive_business_cents', 480000);
        $response->assertJsonPath('suggested_category.type', 'Laptop 40% privat');
        $this->assertStringContainsString('9130', (string) $response->json('suggested_category.e1a'));
    }

    public function test_a_private_investment_asset_is_listed_by_the_private_filter(): void
    {
        $token = $this->tokenFor();
        $this->createAsset('Privat-Laptop', 'linear', true);
        $this->createAsset('Firmen-Kamera', 'linear', false);

        $all = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $all->assertOk();
        $this->assertCount(2, $all->json('assets'));

        $private = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets?private=1');
        $private->assertOk();
        $this->assertCount(1, $private->json('assets'));
        $this->assertSame('Privat-Laptop', $private->json('assets.0.name'));
        $this->assertTrue($private->json('assets.0.is_private_investment'));
    }

    public function test_switch_to_linear_is_permanent_and_never_reversible(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset('Degressives Gut', 'degressive');

        $first = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2027, 'reason' => 'Lineare AfA ist dauerhaft günstiger.'],
        );
        $first->assertOk();
        $this->assertNotNull($first->json('asset.switched_to_linear_at'));
        $this->assertSame('Lineare AfA ist dauerhaft günstiger.', $first->json('asset.switch_reason'));

        $second = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2028, 'reason' => 'Noch einmal wechseln.'],
        );
        $second->assertUnprocessable();

        $this->withHeaders($this->bearer($token))->putJson('/api/management/eur/assets/'.$asset->id, [
            'method' => 'degressive',
        ])->assertMethodNotAllowed();

        $this->assertSame('linear', $asset->refresh()->method);
        $this->assertDatabaseHas('eur_audit_logs', ['action' => 'switched_to_linear', 'subject_id' => $asset->id]);
    }

    public function test_depreciation_booking_is_idempotent_and_uses_the_business_amount(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset('Privatanteil-Gut', 'linear', true, 80);

        $first = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $first->assertCreated();
        $first->assertJsonPath('entry.business_amount_cents', 64000);
        $first->assertJsonPath('entry.e1a_code', '9130');
        $entryId = (int) $first->json('entry.id');

        $second = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $second->assertOk();
        $second->assertJsonPath('already_booked', true);
        $second->assertJsonPath('entry.id', $entryId);

        // Neben der AfA-Buchung existiert die automatische EUE-055-Entnahme.
        $this->assertSame(1, EurEntry::forCurrentBrand()->where('kind_type', 'AfA')->count());
        $this->assertSame(1, EurEntry::forCurrentBrand()->where('kind_type', 'Entnahme')->count());
        $this->assertSame(1, EurAssetYear::forCurrentBrand()->whereNotNull('eur_entry_id')->count());
        $this->assertDatabaseHas('eur_audit_logs', ['action' => 'booked_depreciation', 'subject_id' => $asset->id]);
    }

    public function test_switch_after_booking_preserves_booked_years_and_entries(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset('Gebraucht-Gut', 'degressive');

        $booked = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $booked->assertCreated();
        $entryId = (int) $booked->json('entry.id');

        $tooEarly = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2026, 'reason' => 'Wechsel im gebuchten Jahr.'],
        );
        $tooEarly->assertUnprocessable();

        $switch = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2027, 'reason' => 'Restbuchwert linear fortführen.'],
        );
        $switch->assertOk();

        $bookedYear = EurAssetYear::forCurrentBrand()->where('year', 2026)->firstOrFail();
        $this->assertSame($entryId, (int) $bookedYear->eur_entry_id);
        $this->assertSame('degressive', $bookedYear->method);
        $this->assertSame(1, EurEntry::forCurrentBrand()->count());

        foreach ([2027, 2028] as $year) {
            $row = EurAssetYear::forCurrentBrand()->where('year', $year)->firstOrFail();
            $this->assertSame('linear', $row->method);
            $this->assertNull($row->eur_entry_id);
        }

        $again = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $again->assertOk();
        $again->assertJsonPath('already_booked', true);
        $again->assertJsonPath('entry.id', $entryId);
    }

    public function test_booking_rejects_an_unsupported_year(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset('Gut', 'linear');

        $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2027],
        )->assertUnprocessable();
    }

    private function createAsset(string $name, string $method, bool $private = false, int $share = 100): EurAsset
    {
        $payload = [
            'name' => $name,
            'invoice_no' => 'R-'.$name,
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => $share,
            'method' => $method,
            'is_private_investment' => $private,
        ];

        if ($method === 'degressive') {
            $payload['degressive_rate'] = 3000;
        }

        $response = $this->withHeaders($this->bearer($this->tokenFor()))->postJson('/api/management/eur/assets', $payload);
        $response->assertCreated();

        return EurAsset::forCurrentBrand()->findOrFail($response->json('asset.id'));
    }
}
