<?php

namespace App\Console\Commands;

use App\Services\Shifts\ShiftService;
use Illuminate\Console\Command;

/**
 * Mark ended shifts nobody checked into as missed.
 *
 * Every five minutes. It used to run only with the Sunday roster, so a shift
 * nobody attended on Monday said "Scheduled" for the rest of the week and the
 * roster still offered to reassign it.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class MarkMissedShifts extends Command
{
    protected $signature = 'atlas:mark-missed-shifts';

    protected $description = 'Mark ended shifts that nobody checked into as missed';

    public function handle(ShiftService $shifts): int
    {
        $missed = $shifts->markMissed();

        $this->info("{$missed} ".str('shift')->plural($missed).' marked as missed.');

        return self::SUCCESS;
    }
}
