<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Enums\UserRole;
use App\Models\EurMonthlyReport;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Tests\Support\AuthTokenFactory;

/**
 * Portal #31 / P7 — idempotente Monatsanlage über die kanonischen Routen.
 */
class EurMonthCompletionApiTest extends EurBookkeepingTestCase
{
    public function test_store_creates_a_month_once_and_keeps_its_report_shape(): void
    {
        $token = $this->tokenFor();

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create', ['year' => 2026, 'month' => 5]);
        $first->assertOk()
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('month', 5)
            ->assertJsonPath('created', true)
            ->assertJsonPath('report.status', 'open')
            ->assertJsonPath('report.month', 5);

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create', ['year' => 2026, 'month' => 5]);
        $second->assertOk()->assertJsonPath('created', false);

        $this->assertSame($first->json('report.id'), $second->json('report.id'));
        $this->assertDatabaseCount('eur_monthly_reports', 1);
    }

    /**
     * E2E-Regressionsfall (eur-tabs.spec.ts, Issue #52): der Super-Admin aus
     * E2ESessionHelper hat `brand = null`. Die Idempotenz hängt am Host-Kontext
     * (BrandContextMiddleware), nicht am User — zwei Requests auf demselben
     * Host müssen denselben Monatsdatensatz treffen.
     */
    public function test_canonical_months_route_is_idempotent_for_super_admin_without_brand(): void
    {
        $user = User::factory()->create(['brand' => null]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
        $token = AuthTokenFactory::forUser($user);
        auth('api')->forgetUser();

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/months', ['year' => 2026, 'month' => 5]);
        $first->assertOk()->assertJsonPath('created', true);

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/months', ['year' => 2026, 'month' => 5]);
        $second->assertOk()->assertJsonPath('created', false);

        $this->assertSame($first->json('report.id'), $second->json('report.id'));
        $this->assertDatabaseCount('eur_monthly_reports', 1);
        $this->assertDatabaseHas('eur_monthly_reports', [
            'brand' => BrandRegistry::currentId(), 'year' => 2026, 'month' => 5,
        ]);
    }

    public function test_create_year_creates_twelve_months_once_and_does_not_reopen_closed_months(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        EurMonthlyReport::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5,
            'status' => 'closed', 'closed_at' => now(), 'note' => 'Abgeschlossen',
        ]);

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create-year', ['year' => 2026]);
        $first->assertOk()
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('created', 11);
        $this->assertCount(12, $first->json('months'));
        $this->assertSame(5, $first->json('months.4.month'));
        $this->assertSame('closed', $first->json('months.4.status'));
        $this->assertDatabaseCount('eur_monthly_reports', 12);

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create-year', ['year' => 2026]);
        $second->assertOk()->assertJsonPath('created', 0);
        $this->assertDatabaseCount('eur_monthly_reports', 12);
        $this->assertDatabaseHas('eur_monthly_reports', [
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'status' => 'closed',
        ]);
    }

    public function test_new_month_endpoints_reject_unknown_years_and_invalid_months(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create', ['year' => 2027, 'month' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create', ['year' => 2026, 'month' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create-year', ['year' => 2023])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $this->assertDatabaseCount('eur_monthly_reports', 0);
    }

    public function test_new_month_endpoints_are_brand_scoped(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        EurMonthlyReport::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'month' => 5,
            'status' => 'closed', 'closed_at' => now(),
        ]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create', ['year' => 2026, 'month' => 5])
            ->assertOk()
            ->assertJsonPath('created', true);
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/create-year', ['year' => 2025])
            ->assertOk()
            ->assertJsonPath('created', 12);

        $this->assertDatabaseCount('eur_monthly_reports', 14);
        $this->assertDatabaseHas('eur_monthly_reports', [
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'status' => 'open',
        ]);
        $this->assertDatabaseHas('eur_monthly_reports', [
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'month' => 5, 'status' => 'closed',
        ]);
        $this->assertDatabaseMissing('eur_monthly_reports', [
            'brand' => self::OTHER_BRAND, 'year' => 2025, 'month' => 1,
        ]);
    }
}
