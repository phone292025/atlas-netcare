<?php

namespace App\Console\Commands;

use App\Services\Operations\QueueHealth;
use Illuminate\Console\Command;

/**
 * Tell the administrators when the queue worker has stopped or keeps
 * failing. Scheduled every five minutes.
 *
 * @see App\Services\Operations\QueueHealth
 */
class CheckQueue extends Command
{
    protected $signature = 'atlas:check-queue';

    protected $description = 'Alarm the administrators if background jobs are stuck or failing';

    public function handle(QueueHealth $health): int
    {
        foreach ($health->problems() as $problem) {
            $this->warn("  {$problem}");
        }

        if ($told = $health->alertIfUnhealthy()) {
            $this->line("Told {$told} ".str('administrator')->plural($told).'.');
        }

        return self::SUCCESS;
    }
}
