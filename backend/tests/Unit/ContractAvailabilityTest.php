<?php

namespace Tests\Unit;

use App\Enums\Brand;
use App\Models\Contract;
use App\Support\BrandRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_legacy_instance_uses_parent_deadline_without_mutating_the_instance(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'expires_at' => now()->subMinute(),
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'expires_at' => null,
            'closes_at' => null,
            'brand' => Brand::B2B,
        ]);

        $instance->load('template');

        $this->assertSame(
            $template->expires_at->toDateTimeString(),
            $instance->effectiveExpiresAt()?->toDateTimeString()
        );
        $this->assertFalse($instance->isPubliclyAvailable());
        $this->assertDatabaseHas('contracts', [
            'id' => $instance->id,
            'expires_at' => null,
            'closes_at' => null,
        ]);
    }

    public function test_database_clock_read_uses_a_mariadb_safe_alias(): void
    {
        // SQLite accepts the old alias, so assert the portable SQL contract
        // explicitly while keeping the default PHPUnit run self-contained.
        $clockQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$clockQueries): void {
            if (str_contains(strtoupper($query->sql), 'CURRENT_TIMESTAMP')) {
                $clockQueries[] = $query->sql;
            }
        });

        $databaseNow = Contract::databaseNow();

        $this->assertTrue($databaseNow->isAfter(now()->subMinute()));
        $this->assertCount(1, $clockQueries);
        $this->assertStringContainsStringIgnoringCase(
            'AS contract_database_now',
            $clockQueries[0],
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'AS current_time',
            $clockQueries[0],
        );
    }

    public function test_instance_cannot_outlive_a_parent_with_an_earlier_deadline(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'closes_at' => now()->addHour(),
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'closes_at' => now()->addDay(),
            'brand' => Brand::B2B,
        ]);

        $instance->load('template');

        $this->assertSame(
            $template->closes_at->toDateTimeString(),
            $instance->effectiveClosesAt()?->toDateTimeString()
        );
        $this->assertTrue($instance->isPubliclyAvailable());
        $this->assertTrue($instance->isPubliclyAvailableAtDatabaseTime());
    }
}
