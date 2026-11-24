<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\ProjectAnalytics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** EUE-121 · Projekt × Monat als reine Cent-/Minuten-Aggregation. */
class ProjectAnalyticsTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $hours
     * @return array<string, mixed>
     */
    private function build(array $entries = [], array $hours = [], int $afa = 0, bool $excludeSv = true): array
    {
        return ProjectAnalytics::build(2026, $entries, $hours, $afa, $excludeSv);
    }

    /** @return list<array<string, mixed>> */
    private function twoProjectEntries(): array
    {
        return [
            ['month' => 1, 'direction' => 'income', 'project' => 'P1', 'business_amount_cents' => 10000, 'e1a_code' => '9040'],
            ['month' => 2, 'direction' => 'income', 'project' => 'P1', 'business_amount_cents' => 20000, 'e1a_code' => '9040'],
            ['month' => 1, 'direction' => 'expense', 'project' => 'P1', 'business_amount_cents' => 5000, 'e1a_code' => '9230'],
            ['month' => 2, 'direction' => 'expense', 'project' => 'P1', 'business_amount_cents' => 7000, 'e1a_code' => '9230'],
            ['month' => 1, 'direction' => 'income', 'project' => 'P2', 'business_amount_cents' => 30000, 'e1a_code' => '9040'],
            ['month' => 2, 'direction' => 'expense', 'project' => 'P2', 'business_amount_cents' => 4000, 'e1a_code' => '9230'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function twoProjectHours(): array
    {
        return [
            ['month' => 1, 'minutes' => 180, 'project' => 'P1'],
            ['month' => 2, 'minutes' => 120, 'project' => 'P1'],
            ['month' => 1, 'minutes' => 60, 'project' => 'P2'],
        ];
    }

    public function test_two_projects_and_two_months_produce_exact_cent_vectors(): void
    {
        $data = $this->build($this->twoProjectEntries(), $this->twoProjectHours());

        $this->assertSame(2026, $data['year']);
        $this->assertCount(2, $data['projects']);
        $this->assertSame(['P1', 'P2'], array_column($data['projects'], 'project'));

        $p1 = $data['projects'][0];
        $this->assertCount(12, $p1['months']);
        $this->assertSame([
            'month' => 1,
            'income_cents' => 10000,
            'expense_cents' => 5000,
            'hours_minutes' => 180,
            'result_cents' => 5000,
            'result_per_hour_cents' => 27,
        ], $p1['months'][0]);
        $this->assertSame([
            'month' => 2,
            'income_cents' => 20000,
            'expense_cents' => 7000,
            'hours_minutes' => 120,
            'result_cents' => 13000,
            'result_per_hour_cents' => 108,
        ], $p1['months'][1]);
        $this->assertSame([
            'month' => 3,
            'income_cents' => 0,
            'expense_cents' => 0,
            'hours_minutes' => 0,
            'result_cents' => 0,
            'result_per_hour_cents' => null,
        ], $p1['months'][2]);
        $this->assertSame([
            'income_cents' => 30000,
            'expense_cents' => 12000,
            'hours_minutes' => 300,
            'result_cents' => 18000,
            'result_per_hour_cents' => 60,
        ], $p1['totals']);

        $p2 = $data['projects'][1];
        $this->assertSame(30000, $p2['months'][0]['income_cents']);
        $this->assertSame(500, $p2['months'][0]['result_per_hour_cents']);
        $this->assertSame(-4000, $p2['months'][1]['result_cents']);
        $this->assertNull($p2['months'][1]['result_per_hour_cents']);
        $this->assertSame([
            'income_cents' => 30000,
            'expense_cents' => 4000,
            'hours_minutes' => 60,
            'result_cents' => 26000,
            'result_per_hour_cents' => 433,
        ], $p2['totals']);

        $this->assertSame([
            'month' => 1,
            'income_cents' => 40000,
            'expense_cents' => 5000,
            'hours_minutes' => 240,
            'result_cents' => 35000,
        ], $data['month_totals'][0]);
        $this->assertSame([
            'month' => 2,
            'income_cents' => 20000,
            'expense_cents' => 11000,
            'hours_minutes' => 120,
            'result_cents' => 9000,
        ], $data['month_totals'][1]);
        $this->assertCount(12, $data['month_totals']);
        $this->assertSame([
            'income_cents' => 60000,
            'expense_cents' => 16000,
            'hours_minutes' => 360,
            'result_cents' => 44000,
            'result_per_hour_cents' => 122,
        ], $data['totals']);
        $this->assertSame(0, $data['undistributed_hours_minutes']);
        $this->assertSame(0, $data['sv_excluded_cents']);
        $this->assertTrue($data['match']);
    }

    public function test_9225_is_excluded_only_with_the_flag_and_reported_separately(): void
    {
        $entries = [
            ['month' => 1, 'direction' => 'income', 'project' => 'P1', 'business_amount_cents' => 10000, 'e1a_code' => '9040'],
            ['month' => 1, 'direction' => 'expense', 'project' => 'P1', 'business_amount_cents' => 3000, 'e1a_code' => '9225'],
            ['month' => 1, 'direction' => 'expense', 'project' => 'P1', 'business_amount_cents' => 2000, 'e1a_code' => '9230'],
        ];

        $excluded = $this->build($entries, [], 0, true);
        $this->assertSame(3000, $excluded['sv_excluded_cents']);
        $this->assertSame(2000, $excluded['totals']['expense_cents']);
        $this->assertSame(8000, $excluded['totals']['result_cents']);
        $this->assertSame(2000, $excluded['projects'][0]['months'][0]['expense_cents']);

        $retained = $this->build($entries, [], 0, false);
        $this->assertSame(0, $retained['sv_excluded_cents']);
        $this->assertSame(5000, $retained['totals']['expense_cents']);
        $this->assertSame(5000, $retained['totals']['result_cents']);
        $this->assertSame(5000, $retained['projects'][0]['months'][0]['expense_cents']);
        $this->assertTrue($retained['match']);
    }

    public function test_hours_without_project_are_undistributed_and_named_projects_stay_separate(): void
    {
        $entries = [
            ['month' => 1, 'direction' => 'income', 'project' => null, 'business_amount_cents' => 10000, 'e1a_code' => '9040'],
        ];
        $hours = [
            ['month' => 1, 'minutes' => 120, 'project' => 'Alpha'],
            ['month' => 2, 'minutes' => 90, 'project' => null],
            ['month' => 3, 'minutes' => 30, 'project' => ''],
        ];

        $data = $this->build($entries, $hours);

        $names = array_column($data['projects'], 'project');
        $this->assertSame(['Ohne Projekt', 'Alpha'], $names);
        $this->assertSame(120, $data['undistributed_hours_minutes']);
        $this->assertSame(240, $data['totals']['hours_minutes']);

        $byName = array_column($data['projects'], null, 'project');
        $this->assertSame(10000, $byName['Ohne Projekt']['months'][0]['income_cents']);
        $this->assertSame(120, $byName['Ohne Projekt']['totals']['hours_minutes']);
        $this->assertSame(0, $byName['Alpha']['totals']['income_cents']);
        $this->assertSame(120, $byName['Alpha']['totals']['hours_minutes']);

        $hoursCheck = null;

        foreach ($data['checks'] as $check) {
            if ($check['code'] === 'P121-HOURS') {
                $hoursCheck = $check;
            }
        }

        $this->assertNotNull($hoursCheck);
        $this->assertTrue($hoursCheck['ok']);
        $this->assertTrue($data['match']);
    }

    public function test_result_per_hour_is_null_without_hours_and_integer_with_hours(): void
    {
        $entries = [
            ['month' => 5, 'direction' => 'income', 'project' => 'A', 'business_amount_cents' => 5000, 'e1a_code' => '9040'],
        ];

        $without = $this->build($entries);
        $this->assertNull($without['projects'][0]['months'][4]['result_per_hour_cents']);
        $this->assertNull($without['projects'][0]['totals']['result_per_hour_cents']);
        $this->assertNull($without['totals']['result_per_hour_cents']);

        $with = $this->build($entries, [['month' => 5, 'minutes' => 180, 'project' => 'A']]);
        $this->assertSame(27, $with['projects'][0]['months'][4]['result_per_hour_cents']);
        $this->assertSame(27, $with['projects'][0]['totals']['result_per_hour_cents']);
        $this->assertSame(27, $with['totals']['result_per_hour_cents']);
        $this->assertIsInt($with['totals']['result_per_hour_cents']);
    }

    public function test_prior_year_afa_is_only_a_top_level_figure(): void
    {
        $data = $this->build(
            [['month' => 1, 'direction' => 'income', 'project' => 'A', 'business_amount_cents' => 10000, 'e1a_code' => '9040']],
            [],
            12345,
        );

        $this->assertSame(12345, $data['prior_year_afa_cents']);
        $this->assertArrayNotHasKey('prior_year_afa_cents', $data['totals']);

        foreach ($data['projects'] as $project) {
            $this->assertArrayNotHasKey('prior_year_afa_cents', $project);
            $this->assertArrayNotHasKey('prior_year_afa_cents', $project['totals']);

            foreach ($project['months'] as $month) {
                $this->assertArrayNotHasKey('prior_year_afa_cents', $month);
            }
        }

        foreach ($data['month_totals'] as $month) {
            $this->assertArrayNotHasKey('prior_year_afa_cents', $month);
        }
    }

    public function test_rejects_non_array_rows_and_non_integer_amounts_or_minutes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProjectAnalytics::build(2026, ['not-an-array'], [], 0, true);
    }

    public function test_rejects_non_integer_cents(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProjectAnalytics::build(2026, [[
            'month' => 1, 'direction' => 'income', 'project' => 'A',
            'business_amount_cents' => '100', 'e1a_code' => '9040',
        ]], [], 0, true);
    }

    public function test_rejects_non_integer_minutes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProjectAnalytics::build(2026, [], [['month' => 1, 'minutes' => '90', 'project' => 'A']], 0, true);
    }
}
