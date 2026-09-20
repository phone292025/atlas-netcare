<?php

namespace App\Services\Telemetry;

use App\Enums\SimulationScenario;
use App\Models\AccessPoint;
use App\Support\CampusClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Telemetry invented, but not invented carelessly.
 *
 * ADR-0002 is blunt about the risk: if latency is random noise uncorrelated
 * with client count, M7's predictive scoring has nothing real to detect and a
 * sharp marker will notice. So the numbers here are generated from a model
 * rather than from rand():
 *
 *   1. every access point gets a FIXED personality, derived from its own id —
 *      the same AP is consistently busier, or consistently worse, run after
 *      run. Without this, "which AP is the problem?" has no stable answer and
 *      the risk ranking is meaningless.
 *   2. a daily curve — quiet overnight, climbing from 08:00, peaks late
 *      morning and mid-afternoon, with a dip at lunch.
 *   3. weekends at roughly a quarter of weekday load.
 *   4. latency and packet loss rise SUPERLINEARLY with saturation, because
 *      that is how congestion actually behaves: fine until it is not.
 *   5. rare, sticky outages rather than single missing samples.
 *
 * The result is data a person can read a story from: Level 6 IT Lab is busy at
 * 11am on a Tuesday, and the AP with the weakest baseline is the one that goes
 * degraded first.
 *
 * @see docs/decisions/ADR-0002-telemetry-source-driver.md
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class SimulatedTelemetrySource implements TelemetrySource
{
    public function sourceLabel(): string
    {
        return 'simulated';
    }

    /**
     * Everything not under maintenance, except an access point whose feed is
     * simulated as interrupted.
     *
     * The simulator can invent a reading for any access point, so it polls
     * every one the system is watching. A FEED INTERRUPTION is the one case it
     * deliberately does not: the path to the device is what failed, so nobody
     * is listening. Leaving it out here is what stops markStaleOffline()
     * turning "we cannot see it" into "it is down" and raising a ticket about
     * hardware that may be fine. The Network monitor shows it as unknown.
     *
     * @return Builder<AccessPoint>
     */
    public function pollable(): Builder
    {
        return AccessPoint::query()
            ->collectable()
            ->where(fn (Builder $query) => $query
                ->whereNull('simulation_scenario')
                ->orWhere('simulation_scenario', '!=', SimulationScenario::FeedInterruption->value));
    }

    /** @return Collection<int, ReadingData> */
    public function collect(): Collection
    {
        return $this->pollable()
            ->get()
            ->map(fn (AccessPoint $ap) => $this->reading($ap, now()));
    }

    /**
     * One reading for one access point at one moment.
     *
     * Public so the backfill command can generate history by walking a clock
     * backwards through the same model — historical data and live data are
     * then indistinguishable in shape, which is the point.
     */
    public function reading(AccessPoint $ap, CarbonInterface $at): ReadingData
    {
        $scenario = $this->scenarioAt($ap, $at);

        if ($scenario === SimulationScenario::Outage || $this->isOutage($ap, $at)) {
            return ReadingData::unreachable($ap, $at, $this->sourceLabel());
        }

        $capacity = max(1, (int) $ap->client_capacity);
        $clients = $scenario === SimulationScenario::Congestion
            ? $this->congestedClientCount($ap, $at, $capacity)
            : $this->clientCount($ap, $at, $capacity);
        $saturation = min(1.2, $clients / $capacity);

        return new ReadingData(
            accessPointId: $ap->id,
            recordedAt: Carbon::instance($at->toDateTime()),
            latencyMs: $this->latency($ap, $saturation),
            clientCount: $clients,
            packetLossPct: $this->packetLoss($saturation),
            // Floored at zero: the jitter is ±6 points, and without the floor a
            // quiet overnight access point reported negative utilisation — in
            // about one reading in seven before 2026-09-19.
            channelUtilPct: round(max(0.0, min(99.0, $saturation * 78 + $this->jitter($ap, $at, 6))), 2),
            throughputMbps: $this->throughput($clients, $saturation),
            source: $this->sourceLabel(),
        );
    }

    /**
     * How many people are on this access point right now.
     *
     * Baseline popularity × time-of-day × weekday factor, plus noise.
     */
    private function clientCount(AccessPoint $ap, CarbonInterface $at, int $capacity): int
    {
        $popularity = $this->personality($ap, 'popularity', 0.45, 1.05);

        $expected = $capacity * $popularity * $this->dailyCurve($at) * $this->weekFactor($at);

        $withNoise = $expected + $this->jitter($ap, $at, max(2.0, $expected * 0.12));

        // Can exceed capacity — an overloaded AP is exactly the interesting
        // case, and clamping at capacity would hide it.
        return (int) max(0, round($withNoise));
    }

    /**
     * The scenario forced on this access point at this moment, if any.
     *
     * Only from the moment it was set. The backfill walks this same model
     * back through the past, and a congestion started a minute ago must not
     * rewrite two weeks of history as congested.
     */
    private function scenarioAt(AccessPoint $ap, CarbonInterface $at): ?SimulationScenario
    {
        $scenario = $ap->simulation_scenario;

        if ($scenario === null || $ap->simulation_started_at === null) {
            return null;
        }

        return $at->greaterThanOrEqualTo($ap->simulation_started_at) ? $scenario : null;
    }

    /**
     * Clients during a simulated congestion: over capacity, whatever the hour.
     *
     * Eight to twenty-two per cent over capacity, fixed per access point, so
     * latency and loss rise through the same curves as a real overload. The access point is then classified by the ordinary rule —
     * Degraded after `consecutive_breach` readings in a row — not forced.
     */
    private function congestedClientCount(AccessPoint $ap, CarbonInterface $at, int $capacity): int
    {
        $overload = 1.08 + $this->personality($ap, 'overload', 0.0, 0.14);

        return (int) max(1, round($capacity * $overload + $this->jitter($ap, $at, 2)));
    }

    /**
     * Campus rhythm, 0..1.
     *
     * Two humps rather than one: a late-morning peak and a mid-afternoon peak
     * with a lunch dip between them. A single smooth bell would be visibly
     * synthetic on a 24-hour chart.
     *
     * ON THE CAMPUS CLOCK. The application runs in UTC, and reading `$at->hour`
     * straight off a UTC moment put the campus's busiest hours at 7 to 11 pm
     * local time — invisible until a chart labelled its axis in campus time,
     * which every chart here now does.
     */
    private function dailyCurve(CarbonInterface $at): float
    {
        $local = $at->copy()->setTimezone(CampusClock::timezone());
        $hour = $local->hour + $local->minute / 60;

        if ($hour < 6.5 || $hour > 22) {
            // Overnight: a handful of devices left connected, not zero.
            return 0.06;
        }

        $morning = exp(-(($hour - 11.0) ** 2) / 5.5);
        $afternoon = exp(-(($hour - 14.5) ** 2) / 6.0);
        $lunchDip = 1 - 0.35 * exp(-(($hour - 12.75) ** 2) / 0.8);

        return min(1.0, max(0.08, ($morning + 0.92 * $afternoon) * $lunchDip));
    }

    /** Weekends are much quieter, but the library is never empty. On campus time, like the daily curve. */
    private function weekFactor(CarbonInterface $at): float
    {
        return $at->copy()->setTimezone(CampusClock::timezone())->isWeekend() ? 0.28 : 1.0;
    }

    /**
     * Latency in milliseconds.
     *
     * Flat and healthy until saturation climbs, then rising sharply — the
     * cubic term is what makes a congested AP look congested rather than
     * merely slightly worse. This is the correlation M7 is meant to find, so
     * it has to genuinely be here.
     */
    private function latency(AccessPoint $ap, float $saturation): int
    {
        $base = $this->personality($ap, 'latency', 12, 34);
        $congestion = 210 * ($saturation ** 3);
        $noise = $this->jitter($ap, now(), 6);

        return (int) max(1, min(65535, round($base + $congestion + $noise)));
    }

    /** Loss stays near zero until an AP is genuinely overloaded. */
    private function packetLoss(float $saturation): float
    {
        if ($saturation < 0.6) {
            return round(max(0, mt_rand(0, 40) / 100), 2);
        }

        return round(min(35.0, 14 * (($saturation - 0.6) ** 2) * 10), 2);
    }

    private function throughput(int $clients, float $saturation): float
    {
        // Total goodput rises with users, then falls back as contention bites.
        $efficiency = max(0.25, 1 - ($saturation ** 2) * 0.55);

        return round(max(0.1, $clients * 1.9 * $efficiency), 2);
    }

    /**
     * Is this access point in an outage right now?
     *
     * STICKY, not per-sample. A real outage lasts minutes — an AP that dropped
     * one reading in sixty would never trip the offline rule, and the
     * dashboard would never show an outage at all. This buckets time into
     * ~20-minute windows and knocks out a window at a time, so consecutive
     * readings agree with each other.
     */
    private function isOutage(AccessPoint $ap, CarbonInterface $at): bool
    {
        $window = (int) floor($at->getTimestamp() / 1200);
        $reliability = $this->personality($ap, 'reliability', 0.0, 1.0);

        // The least reliable access points fail a few times a week; the best
        // essentially never do.
        $chance = 0.004 + (1 - $reliability) * 0.02;

        return $this->hash($ap->id.':outage:'.$window) < $chance;
    }

    /**
     * A stable per-access-point trait in [$min, $max].
     *
     * Derived from the id, so it never changes between runs or between the
     * backfill and live collection. This is what makes one AP consistently
     * the worst one — which is the entire basis of a risk ranking that means
     * anything.
     */
    private function personality(AccessPoint $ap, string $trait, float $min, float $max): float
    {
        return $min + $this->hash($ap->id.':'.$trait) * ($max - $min);
    }

    /** Deterministic 0..1 from a string. */
    private function hash(string $seed): float
    {
        return (hexdec(substr(md5($seed), 0, 8)) % 100000) / 100000;
    }

    /** Small random variation, so two identical minutes are not identical. */
    private function jitter(AccessPoint $ap, CarbonInterface $at, float $scale): float
    {
        return (mt_rand(-100, 100) / 100) * $scale;
    }
}
