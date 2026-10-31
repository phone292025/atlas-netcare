<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduler's locks. withoutOverlapping() defaults to a 24-hour lock in
 * the database cache, so a scheduler killed mid-run stopped readings and
 * alarms until the next day.
 */
class ScheduleTest extends TestCase
{
    public function test_no_overlap_lock_outlives_its_command_by_hours(): void
    {
        $locked = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => $event->withoutOverlapping);

        $this->assertNotEmpty($locked);

        foreach ($locked as $event) {
            $this->assertLessThanOrEqual(60, $event->expiresAt, "{$event->command} keeps its lock for {$event->expiresAt} minutes.");
        }
    }
}
