<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurEntry;
use App\Support\BrandRegistry;

/** Portal #58: nicht blockierende Erinnerung an die reale SV-Buchung. */
class EurSvReminderApiTest extends EurBookkeepingTestCase
{
    public function test_summary_reports_the_quarterly_booking_status(): void
    {
        $token = $this->tokenFor();
        $this->entry(2, 3800, '9225');

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/summary?year=2026');
        $response->assertOk();

        $reminder = $response->json('sv_reminder');
        $this->assertSame(3800, $reminder['target_per_quarter_cents']);
        $this->assertTrue($reminder['quarters'][0]['done']);
        $this->assertSame([2, 3, 4], $reminder['open_quarters']);
        $this->assertStringContainsString('Q2, Q3, Q4 2026', $reminder['message']);
        // Die bereits vorhandenen Schlüssel bleiben unverändert.
        $this->assertSame(3800, $response->json('booked_sv_cents'));
    }

    public function test_quarter_end_month_shows_a_non_blocking_check(): void
    {
        $token = $this->tokenFor();
        $this->entry(2, 3800, '9225');

        $march = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/month?year=2026&month=3');
        $march->assertOk();
        $check = collect($march->json('checks'))->firstWhere('code', 'SV-9225');
        $this->assertNotNull($check);
        $this->assertTrue($check['ok']);

        $june = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/month?year=2026&month=6');
        $june->assertOk();
        $missing = collect($june->json('checks'))->firstWhere('code', 'SV-9225');
        $this->assertNotNull($missing);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('38,00 € erwartet', $missing['message']);

        // April ist kein Quartalsmonat — keine Erinnerung.
        $april = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/month?year=2026&month=4');
        $april->assertOk();
        $this->assertNull(collect($april->json('checks'))->firstWhere('code', 'SV-9225'));
    }

    public function test_foreign_brand_bookings_do_not_count(): void
    {
        $token = $this->tokenFor();
        EurEntry::create([
            'brand' => self::OTHER_BRAND,
            'year' => 2026, 'month' => 2, 'day' => 15,
            'direction' => 'expense', 'kind_type' => 'Pflichtversicherungen',
            'e1a_code' => '9225', 'business_share' => 100,
            'amount_cents' => 3800, 'business_amount_cents' => 3800,
        ]);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/summary?year=2026');
        $response->assertOk();
        $this->assertSame([1, 2, 3, 4], $response->json('sv_reminder.open_quarters'));
    }

    private function entry(int $month, int $cents, string $code): EurEntry
    {
        return EurEntry::create([
            'brand' => BrandRegistry::currentId(),
            'year' => 2026, 'month' => $month, 'day' => 15,
            'direction' => 'expense', 'kind_type' => 'Pflichtversicherungen',
            'e1a_code' => $code, 'business_share' => 100,
            'amount_cents' => $cents, 'business_amount_cents' => $cents,
        ]);
    }
}
