<?php

namespace App\Console\Commands;

use App\Services\Operations\BackupHealth;
use Illuminate\Console\Command;

/**
 * Tell the administrators when backups are late, failing or not copied off
 * this machine. Scheduled every hour.
 *
 * @see App\Services\Operations\BackupHealth
 */
class CheckBackups extends Command
{
    protected $signature = 'atlas:check-backups';

    protected $description = 'Alarm the administrators if backups are late, failing or not copied';

    public function handle(BackupHealth $health): int
    {
        $problems = $health->problems();

        foreach ($problems as $problem) {
            $this->warn("  {$problem}");
        }

        if ($problems === [] && config('atlas.backups.expected')) {
            $this->info('Backups: the newest one is on time.');
        }

        if ($told = $health->alertIfUnhealthy()) {
            $this->line("Told {$told} ".str('administrator')->plural($told).'.');
        }

        return self::SUCCESS;
    }
}
