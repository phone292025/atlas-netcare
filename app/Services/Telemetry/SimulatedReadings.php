<?php

namespace App\Services\Telemetry;

use App\Enums\SimulationScenario;
use App\Models\AccessPoint;
use App\Models\NetworkDevice;
use App\Support\CampusClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Telemetry invented, but invented from a model rather than rand().
 *
 * If latency were noise uncorrelated with load, M7's risk scoring would have
 * nothing real to find (ADR-0002). So:
 *
 *   1. every access point has a FIXED personality derived from its id, so the
 *      same one is consistently busier or worse, run after run, and "which
 *      access point is the problem?" has a stable answer
 *   2. a daily curve: quiet overnight, peaks late morning and mid-afternoon,
 *      a dip at lunch
 *   3. weekends at roughly a quarter of weekday load
 *   4. latency and loss rise SUPERLINEARLY with saturation, as congestion
 *      does: fine until it is not
 *   5. rare, sticky outages rather than single missing samples
 *
 * The Simulator, Atlas's virtual agent, asks this for each reading it sends
 * (ADR-0010). Not final, so a test can replace it with readings of its own.
 *
 * @see App\Services\Telemetry\Simulator
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class SimulatedReadings
{
    public const SOURCE = 'simulated';

    /**
     * One reading for one device at one moment. The backfill walks a clock
     * backwards through the same model, so history and live data are the
     * same shape.
     */
    public function reading(NetworkDevice $ap, CarbonInterface $at): ReadingData
    {
        $scenario = $this->scenarioAt($ap, $at);

        if ($scenario === SimulationScenario::Outage || $this->isOutage($ap, $at)) {
            return ReadingData::unreachable($ap, $at, self::SOURCE);
        }

        if (! $ap instanceof AccessPoint) {
            return $this->wiredReading($ap, $at);
        }

        $capacity = max(1, (int) $ap->client_capacity);
        $clients = $scenario === SimulationScenario::Congestion
            ? $this->congestedClientCount($ap, $capacity)
            : $this->clientCount($ap, $at, $capacity);
        $saturation = min(1.2, $clients / $capacity);

        return new ReadingData(
            deviceId: $ap->id,
            recordedAt: Carbon::instance($at->toDateTime()),
            latencyMs: $this->latency($ap, $saturation),
            clientCount: $clients,
            packetLossPct: $this->packetLoss($saturation),
            // Floored at zero: with ±6 points of jitter, a quiet access point
            // otherwise reports negative utilisation.
            channelUtilPct: round(max(0.0, min(99.0, $saturation * 78 + $this->jitter(6))), 2),
            throughputMbps: $this->throughput($clients, $saturation),
            source: self::SOURCE,
        );
    }

    /**
     * A switch, router or server, watched by ping: up, a few milliseconds
     * away, and no clients. Steadier than radio, so it has its own range.
     */
    private function wiredReading(NetworkDevice $device, CarbonInterface $at): ReadingData
    {
        return new ReadingData(
            deviceId: $device->id,
            recordedAt: Carbon::instance($at->toDateTime()),
            latencyMs: (int) max(1, round($this->personality($device, 'latency', 1, 4) + $this->jitter(0.8))),
            clientCount: 0,
            packetLossPct: 0.0,
            channelUtilPct: 0.0,
            throughputMbps: 0.0,
            source: self::SOURCE,
        );
    }

    /** Baseline popularity × time of day × weekday factor, plus noise. */
    private function clientCount(NetworkDevice $ap, CarbonInterface $at, int $capacity): int
    {
        $popularity = $this->personality($ap, 'popularity', 0.45, 1.05);

        $expected = $capacity * $popularity * $this->dailyCurve($at) * $this->weekFactor($at);

        $withNoise = $expected + $this->jitter(max(2.0, $expected * 0.12));

        // Not clamped at capacity: an overloaded access point is exactly the
        // interesting case.
        return (int) max(0, round($withNoise));
    }

    /**
     * The scenario forced on this access point at this moment, if any. Only
     * from the moment it was set, so a backfill does not rewrite two weeks of
     * history as congested.
     */
    private function scenarioAt(NetworkDevice $ap, CarbonInterface $at): ?SimulationScenario
    {
        $scenario = $ap->simulation_scenario;

        if ($scenario === null || $ap->simulation_started_at === null) {
            return null;
        }

        return $at->greaterThanOrEqualTo($ap->simulation_started_at) ? $scenario : null;
    }

    /**
     * Clients during a simulated congestion: 8 to 22% over capacity, fixed per
     * access point, so latency and loss rise through the same curves as a
     * real overload and the ordinary rule turns it Degraded.
     */
    private function congestedClientCount(NetworkDevice $ap, int $capacity): int
    {
        $overload = 1.08 + $this->personality($ap, 'overload', 0.0, 0.14);

        return (int) max(1, round($capacity * $overload + $this->jitter(2)));
    }

    /**
     * Campus rhythm, 0..1: a late-morning and a mid-afternoon peak with a
     * lunch dip, since one smooth bell looks synthetic on a 24-hour chart.
     * Read on the campus clock; the hour of a UTC moment is eight hours out.
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

    /** Weekends are much quieter, but the library is never empty. */
    private function weekFactor(CarbonInterface $at): float
    {
        return $at->copy()->setTimezone(CampusClock::timezone())->isWeekend() ? 0.28 : 1.0;
    }

    /**
     * Latency in milliseconds: flat until saturation climbs, then sharply up.
     * The cubic term is the correlation M7 is meant to find.
     */
    private function latency(NetworkDevice $ap, float $saturation): int
    {
        $base = $this->personality($ap, 'latency', 12, 34);
        $congestion = 210 * ($saturation ** 3);
        $noise = $this->jitter(6);

        return (int) max(1, min(65535, round($base + $congestion + $noise)));
    }

    /** Loss stays near zero until an access point is genuinely overloaded. */
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
     * Whether this access point is in an outage. Sticky: time is bucketed into
     * 20-minute windows and a whole window is knocked out, because a real
     * outage lasts minutes and one missing sample would never trip the
     * offline rule.
     */
    private function isOutage(NetworkDevice $ap, CarbonInterface $at): bool
    {
        $window = (int) floor($at->getTimestamp() / 1200);
        $reliability = $this->personality($ap, 'reliability', 0.0, 1.0);

        // The least reliable fail a few times a week; the best almost never.
        $chance = 0.004 + (1 - $reliability) * 0.02;

        return $this->hash($ap->id.':outage:'.$window) < $chance;
    }

    /**
     * A stable per-access-point trait in [$min, $max], derived from the id so
     * it never changes between runs, or between the backfill and live
     * collection. It is what makes one access point consistently the worst.
     */
    private function personality(NetworkDevice $ap, string $trait, float $min, float $max): float
    {
        return $min + $this->hash($ap->id.':'.$trait) * ($max - $min);
    }

    /** Deterministic 0..1 from a string. */
    private function hash(string $seed): float
    {
        return (hexdec(substr(md5($seed), 0, 8)) % 100000) / 100000;
    }

    /**
     * Small random variation, so two identical minutes are not identical.
     * Genuinely random, unlike personality(): tests assert on shape, never on
     * exact values.
     */
    private function jitter(float $scale): float
    {
        return (mt_rand(-100, 100) / 100) * $scale;
    }
}
