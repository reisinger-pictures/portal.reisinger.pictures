<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Services\Ea\EaOverviewService;
use App\Services\SettingResolver;
use PHPUnit\Framework\TestCase;

/** EAR · Zusatzwerte der Jahresübersicht aus gepinnten Jahrestabellen. */
class EaOverviewServiceTest extends TestCase
{
    private function service(string $mode = 'gsvg'): EaOverviewService
    {
        $settings = $this->createMock(SettingResolver::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'ea_sv_mode' ? $mode : $default,
        );

        return new EaOverviewService($settings);
    }

    public function test_sv_monthly_has_twelve_rows_with_pinned_minima(): void
    {
        $rows = $this->service()->svMonthly(2026, 0, 0);

        $this->assertCount(12, $rows);
        $this->assertSame(range(1, 12), array_column($rows, 'month'));

        $first = $rows[0];
        foreach (['kv_cents', 'pv_cents', 'svs_cents', 'uv_cents'] as $key) {
            $this->assertIsInt($first[$key]);
        }

        // EUE-110/111/112/022: 37,47 + 101,95 + 101,17 + 12,95 ≈ 253,54 €/Monat.
        $this->assertSame(3747, $first['kv_cents']);
        $this->assertSame(10195, $first['pv_cents']);
        $this->assertSame(1295, $first['uv_cents']);
        $this->assertGreaterThan(0, $first['svs_cents']);
    }

    public function test_uv_only_keeps_only_the_accident_flat_rate(): void
    {
        $first = $this->service('uv_only')->svMonthly(2026, 500000, 0)[0];

        $this->assertSame(0, $first['kv_cents']);
        $this->assertSame(0, $first['pv_cents']);
        $this->assertSame(0, $first['svs_cents']);
        $this->assertSame(1295, $first['uv_cents']);
    }

    public function test_fsvg_waives_the_provision_but_keeps_its_own_pension_minimum(): void
    {
        $first = $this->service('fsvg')->svMonthly(2026, 0, 0)[0];

        $this->assertSame(0, $first['svs_cents']);
        $this->assertSame(11022, $first['pv_cents']);
        $this->assertSame(3747, $first['kv_cents']);
    }

    public function test_other_profits_est_mode_drops_only_the_9225_surcharge(): void
    {
        $service = $this->service();

        $withBooking = $service->svMonthly(2026, 1000000, 100000)[0];
        $defaultMode = $service->svMonthly(2026, 1000000, 100000, 'total')[0];
        $otherProfits = $service->svMonthly(2026, 1000000, 100000, 'other_profits')[0];
        $withoutBooking = $service->svMonthly(2026, 1000000, 0)[0];

        // Der Default erhält das bisherige Verhalten.
        $this->assertSame($withBooking, $defaultMode);
        // EUE-115: "Nur andere Gewinne" ist ein Est-Modus, kein SV-Modus —
        // nur der Zuschlag entfällt, der Mindest-KV bleibt erhalten.
        $this->assertSame($withoutBooking['kv_cents'], $otherProfits['kv_cents']);
        $this->assertSame($withoutBooking['svs_cents'], $otherProfits['svs_cents']);
        $this->assertGreaterThan($otherProfits['kv_cents'], $withBooking['kv_cents']);
    }

    public function test_the_booked_sv_exception_is_an_sv_mode_not_an_est_mode(): void
    {
        $this->assertFalse($this->service('uv_only')->excludeBookedSvFromExpenses());
        $this->assertTrue($this->service()->excludeBookedSvFromExpenses());
    }

    public function test_the_cap_hint_survives_the_other_profits_mode(): void
    {
        $rows = $this->service()->svMonthly(2026, 9702000, 500000, 'other_profits');

        $this->assertSame(EaOverviewService::MAXIMUM_BASE_HINT, $rows[0]['base_at_cap_hint']);
    }

    public function test_insurance_blocks_and_afa_card_are_derived(): void
    {
        $service = $this->service();
        $blocks = $service->insuranceBlocks($service->svMonthly(2026, 0, 0));

        $this->assertSame(
            ['Krankenversicherung', 'Pensionsversicherung', 'Selbständigenvorsorge', 'Unfallversicherung'],
            array_column($blocks, 'label'),
        );

        $card = $service->afaCard([
            ['business_use_cents' => 20000, 'private_use_cents' => 5000, 'book_value_cents' => 80000],
            ['business_use_cents' => 10000, 'private_use_cents' => 0, 'book_value_cents' => 40000],
        ]);
        $this->assertSame(['booking_cents' => 30000, 'private_cents' => 5000, 'residual_cents' => 120000], $card);
    }

    public function test_signature_status_is_stable(): void
    {
        $service = $this->service();

        $this->assertSame('none', $service->signatureStatus([]));
        $this->assertSame('signed', $service->signatureStatus([['status' => 'signed'], ['status' => 'signed']]));
        $this->assertSame('partial', $service->signatureStatus([['status' => 'signed'], ['status' => 'pending']]));
    }
}
