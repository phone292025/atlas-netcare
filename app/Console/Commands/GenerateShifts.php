<?php

namespace App\Console\Commands;

use App\Services\Shifts\ShiftService;
use App\Support\CampusClock;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Turn the weekly templates into dated shifts (Q4).
 *
 * Scheduled weekly, one week ahead, so the roster is always populated before
 * anybody needs to look at it. Idempotent — running it twice adds nothing.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class GenerateShifts extends Command
{
    protected $signature = 'atlas:generate-shifts
                            {--week= : Monday of the week to generate (Y-m-d). Next week by default.}';

    protected $description = 'Generate dated shifts for a week from the active shift templates';

    public function handle(ShiftService $shifts): int
    {
        // "Next week" is a campus-calendar question.
        $week = $this->option('week')
            ? Carbon::parse($this->option('week'), CampusClock::timezone())->startOfWeek()
            : CampusClock::now()->addWeek()->startOfWeek();

        try {
            $created = $shifts->generateWeek($week);
        } catch (DomainException $e) {
            // A real state on a fresh install: templates exist but nobody has
            // been given the assistant role yet. Say which, rather than
            // failing with a stack trace.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Week of {$week->toDateString()}: {$created->count()} "
            .str('shift')->plural($created->count()).' created.');

        return self::SUCCESS;
    }
}
