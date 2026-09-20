<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetryCollector;
use Illuminate\Console\Command;

/**
 * Take one reading from every access point and store it.
 *
 * Runs every minute on the schedule. Enters at the SERVICE layer and skips
 * controllers entirely, which is the point of putting the rules there — this
 * command and the Network monitor's "Generate sample" button run exactly the
 * same TelemetryCollector::tick(), tickets and alarms included.
 *
 * @see App\Services\Telemetry\TelemetryCollector
 * @see docs/modules/M5-telemetry-and-dashboard.md
 * @see docs/03-architecture.md
 */
class CollectTelemetry extends Command
{
    protected $signature = 'atlas:collect-telemetry {--quiet-summary : Print only the counts}';

    protected $description = 'Poll every access point once and store the readings';

    public function handle(TelemetryCollector $collector): int
    {
        $transitions = $collector->tick(function (string $level, string $message): void {
            $level === 'info' ? $this->info($message) : $this->warn($message);
        });

        $this->info('Telemetry collected. '.$transitions->count().' status '
            .str('change')->plural($transitions->count()).'.');

        return self::SUCCESS;
    }
}
