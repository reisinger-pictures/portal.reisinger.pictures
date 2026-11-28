<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Ea\Domain\DepreciationCalculator;
use App\Ea\Parity\RuleValues;
use App\Ea\Support\Share;
use App\Models\EaAsset;
use App\Models\EaAssetYear;
use App\Models\EaEntry;
use App\Services\Ea\EaAssetCreationService;
use App\Support\BrandRegistry;

/** #34: Anzeige statt Rechner, Kategorievorschlag, Einmal-Wechsel, Privat-AV, Jahresbuchung. */
class EaAssetPrivateInvestmentTest extends EaBookkeepingTestCase
{
    public function test_compare_returns_first_year_amounts_and_a_catalogue_category(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->getJson(
            '/api/management/ea/assets/compare?cost_cents=2000000&useful_life_years=10'
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

        $all = $this->withHeaders($this->bearer($token))->getJson('/api/management/ea/assets');
        $all->assertOk();
        $this->assertCount(2, $all->json('assets'));

        $private = $this->withHeaders($this->bearer($token))->getJson('/api/management/ea/assets?private=1');
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
            '/api/management/ea/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2027, 'reason' => 'Lineare AfA ist dauerhaft günstiger.'],
        );
        $first->assertOk();
        $this->assertNotNull($first->json('asset.switched_to_linear_at'));
        $this->assertSame('Lineare AfA ist dauerhaft günstiger.', $first->json('asset.switch_reason'));

        $second = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/ea/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2028, 'reason' => 'Noch einmal wechseln.'],
        );
        $second->assertUnprocessable();

        $this->withHeaders($this->bearer($token))->putJson('/api/management/ea/assets/'.$asset->id, [
            'method' => 'degressive',
        ])->assertMethodNotAllowed();

        $this->assertSame('linear', $asset->refresh()->method);
        $this->assertDatabaseHas('ea_audit_logs', ['action' => 'switched_to_linear', 'subject_id' => $asset->id]);
    }

    public function test_depreciation_booking_is_idempotent_and_uses_the_business_amount(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset('Privatanteil-Gut', 'linear', true, 80);

        $first = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/ea/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $first->assertCreated();
        $first->assertJsonPath('entry.business_amount_cents', 64000);
        $first->assertJsonPath('entry.e1a_code', '9130');
        $entryId = (int) $first->json('entry.id');

        $second = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/ea/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $second->assertOk();
        $second->assertJsonPath('already_booked', true);
        $second->assertJsonPath('entry.id', $entryId);

        // Neben der AfA-Buchung existiert die automatische EUE-055-Entnahme.
        $this->assertSame(1, EaEntry::forCurrentBrand()->where('kind_type', 'AfA')->count());
        $this->assertSame(1, EaEntry::forCurrentBrand()->where('kind_type', 'Entnahme')->count());
        $this->assertSame(1, EaAssetYear::forCurrentBrand()->whereNotNull('ea_entry_id')->count());
        $this->assertDatabaseHas('ea_audit_logs', ['action' => 'booked_depreciation', 'subject_id' => $asset->id]);
    }

    public function test_switch_after_booking_preserves_booked_years_and_entries(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset('Gebraucht-Gut', 'degressive');

        $booked = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/ea/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $booked->assertCreated();
        $entryId = (int) $booked->json('entry.id');

        $tooEarly = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/ea/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2026, 'reason' => 'Wechsel im gebuchten Jahr.'],
        );
        $tooEarly->assertUnprocessable();

        $switch = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/ea/assets/'.$asset->id.'/switch-to-linear',
            ['year' => 2027, 'reason' => 'Restbuchwert linear fortführen.'],
        );
        $switch->assertOk();

        $bookedYear = EaAssetYear::forCurrentBrand()->where('year', 2026)->firstOrFail();
        $this->assertSame($entryId, (int) $bookedYear->ea_entry_id);
        $this->assertSame('degressive', $bookedYear->method);
        $this->assertSame(1, EaEntry::forCurrentBrand()->count());

        foreach ([2027, 2028] as $year) {
            $row = EaAssetYear::forCurrentBrand()->where('year', $year)->firstOrFail();
            $this->assertSame('linear', $row->method);
            $this->assertNull($row->ea_entry_id);
        }

        $again = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/ea/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $again->assertOk();
        $again->assertJsonPath('already_booked', true);
        $again->assertJsonPath('entry.id', $entryId);
    }

    public function test_booking_rejects_a_rule_only_year(): void
    {
        $token = $this->tokenFor();
        $asset = $this->createAsset('Gut', 'linear');

        $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/ea/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2025],
        )->assertUnprocessable();
    }

    /**
     * D-93: die Abschreibungsmethode ist der einzige Unterschied. Ein
     * privates Gut bleibt linear (inklusive Eigenbeleg), ein Betriebsgut
     * gleicher Daten behält den degressiven Plan.
     */
    public function test_a_private_asset_is_always_linear_even_with_a_degressive_request(): void
    {
        $purchaseDate = '2026-07-15';
        $this->tokenFor();

        // Die API lehnt `degressive` bei einem Betriebsanteil unter 50 % vor
        // dem Plan ab; der Plan selbst ist die einzige Regelquelle für D-93
        // und wird hier direkt über den gemeinsamen Anlagepfad geschrieben.
        $private = EaAsset::create([
            'brand' => BrandRegistry::currentId(),
            'name' => 'Privat-Degressiv',
            'invoice_no' => 'R-Privat-Degressiv',
            'supplier' => 'Lieferant',
            'purchase_date' => $purchaseDate,
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 30,
            'scope' => 'private',
            'method' => DepreciationCalculator::METHOD_DEGRESSIVE,
            'degressive_rate' => 3000,
        ]);
        app(EaAssetCreationService::class)->writePlan(
            $private,
            new DepreciationCalculator(new RuleValues),
            7,
        );

        $business = $this->createAsset('Betrieb-Degressiv', 'degressive', false, 100, $purchaseDate);

        $this->assertSame('private', $private->scope);
        $this->assertSame('business', $business->scope);

        // 240000 ct / 3 Jahre, Anschaffung in der zweiten Jahreshälfte:
        // Halbjahresregel im ersten Jahr, Restabschreibung im letzten Jahr.
        $linear = [40000, 80000, 119999];
        $degressive = [36000, 61200, 142799];

        foreach ([0, 1, 2] as $index) {
            $year = 2026 + $index;

            $privateYear = EaAssetYear::forCurrentBrand()
                ->where('ea_asset_id', $private->id)
                ->where('year', $year)
                ->firstOrFail();
            $this->assertSame('linear', $privateYear->method);
            $this->assertSame($linear[$index], (int) $privateYear->depreciation_cents);
            // Der Eigenbeleg dokumentiert die lineare AfA des Jahres.
            $this->assertSame($linear[$index], (int) $privateYear->theoretical_depreciation_cents);
            $this->assertSame(30, (int) $privateYear->usage_percent);
            $this->assertSame(Share::apply($linear[$index], 30), (int) $privateYear->eigenbeleg_cents);
            $this->assertSame('EB-'.$private->id.'-'.$year, $privateYear->eigenbeleg_no);

            $businessYear = EaAssetYear::forCurrentBrand()
                ->where('ea_asset_id', $business->id)
                ->where('year', $year)
                ->firstOrFail();
            $this->assertSame('degressive', $businessYear->method);
            $this->assertSame($degressive[$index], (int) $businessYear->depreciation_cents);
            $this->assertNull($businessYear->eigenbeleg_no);
        }
    }

    private function createAsset(
        string $name,
        string $method,
        bool $private = false,
        int $share = 100,
        string $purchaseDate = '2026-01-15',
    ): EaAsset {
        $payload = [
            'name' => $name,
            'invoice_no' => 'R-'.$name,
            'supplier' => 'Lieferant',
            'purchase_date' => $purchaseDate,
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => $share,
            'method' => $method,
            'is_private_investment' => $private,
        ];

        if ($method === 'degressive') {
            $payload['degressive_rate'] = 3000;
        }

        $response = $this->withHeaders($this->bearer($this->tokenFor()))->postJson('/api/management/ea/assets', $payload);
        $response->assertCreated();

        return EaAsset::forCurrentBrand()->findOrFail($response->json('asset.id'));
    }
}
