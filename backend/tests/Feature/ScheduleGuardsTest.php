<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Regression: scheduled commands had neither withoutOverlapping() nor
 * onOneServer(), so multiple container instances could run the same
 * destructive cleanup/billing task concurrently.
 */
class ScheduleGuardsTest extends TestCase
{
    public function test_all_scheduled_commands_use_overlap_and_multi_server_guards(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $label = $event->command ?? $event->description ?? 'unknown';

            $this->assertTrue(
                $event->withoutOverlapping,
                "Scheduled event is missing withoutOverlapping(): {$label}"
            );

            $this->assertTrue(
                $event->onOneServer,
                "Scheduled event is missing onOneServer(): {$label}"
            );
        }
    }
}
