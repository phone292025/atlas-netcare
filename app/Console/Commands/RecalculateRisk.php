<?php

namespace App\Console\Commands;

use App\Enums\RiskBand;
use App\Models\RiskAssessment;
use App\Services\Risk\RiskScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Score every asset and keep the result (FR-505).
 *
 * Scheduled daily at 02:00 campus time, and safe to run again by hand: a
 * second run adds a second history row rather than overwriting one.
 *
 * Change a weight in config/atlas.php, run this, and the maintenance ranking
 * reorders. That transparency is why ADR-0007 chose weights over a trained
 * model.
 *
 *   php artisan atlas:recalculate-risk
 *   php artisan atlas:recalculate-risk --show=15
 *
 * @see App\Services\Risk\RiskScoringService
 * @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
 */
class RecalculateRisk extends Command
{
    protected $signature = 'atlas:recalculate-risk
                            {--show=10 : How many of the highest-risk assets to print}';

    protected $description = 'Score every asset for predictive maintenance risk, keeping history';

    public function handle(RiskScoringService $risk): int
    {
        $started = microtime(true);

        $this->line('');
        $this->line('  Scoring every asset in service.');

        $counts = $risk->recalculateAll();
        $total = $counts['lab_pcs'] + $counts['access_points'];

        if ($total === 0) {
            $this->warn('  Nothing to score. Seed some assets first.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '  %d lab PCs and %d access points in %.2fs.',
            $counts['lab_pcs'],
            $counts['access_points'],
            microtime(true) - $started,
        ));

        $this->line('');
        $this->spread($counts['at']);
        $this->worst((int) $this->option('show'), $counts['at']);

        return self::SUCCESS;
    }

    /**
     * How this run's scores fell across the bands.
     *
     * The headline analytics figure, and the fastest way to tell that a weight
     * change did something: if every asset is Low, the weights or the ceilings
     * are wrong, and that is far easier to see here than on a screen.
     */
    private function spread(Carbon $at): void
    {
        $counts = RiskAssessment::query()
            ->where('calculated_at', $at)
            ->selectRaw('band, COUNT(*) as total')
            ->groupBy('band')
            ->pluck('total', 'band');

        $this->line('  <options=bold>Assets by risk band</>');

        foreach (RiskBand::cases() as $band) {
            $n = (int) ($counts[$band->value] ?? 0);

            // A bar, because a column of numbers does not show a distribution
            // and this command exists to make a distribution obvious.
            $this->line(sprintf('  %-9s %3d  %s', $band->value, $n, str_repeat('#', min(40, $n))));
        }

        $this->line('');
    }

    /**
     * The top of the maintenance queue, with the reason for each.
     *
     * The reason is the whole point. "AP-LIB-03 scored 81" is a number;
     * "81 — latency trend, offline events" is something somebody can act on
     * without opening the application.
     */
    private function worst(int $limit, Carbon $at): void
    {
        if ($limit < 1) {
            return;
        }

        $rows = RiskAssessment::query()
            ->with('assessable')
            ->where('calculated_at', $at)
            ->orderByDesc('score')
            ->limit($limit)
            ->get();

        $this->line("  <options=bold>Highest risk ({$rows->count()})</>");

        foreach ($rows as $row) {
            $asset = $row->assessable;

            $this->line(sprintf(
                '  %5.1f  %-10s %-14s %s',
                $row->score,
                $row->band->value,
                $asset?->asset_tag ?? $asset?->code ?? '—',
                implode(', ', array_map(
                    fn ($k) => str_replace('_', ' ', $k),
                    array_keys($row->topFactors(2)),
                )) ?: 'no measurable factors',
            ));
        }

        $this->line('');
    }
}
