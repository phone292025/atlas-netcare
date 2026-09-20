<?php

namespace App\Http\Controllers;

use App\Enums\SimulationScenario;
use App\Enums\UserRole;
use App\Http\Requests\Telemetry\RunSimulationRequest;
use App\Models\AccessPoint;
use App\Models\User;
use App\Services\Telemetry\NetworkMonitorService;
use App\Services\Telemetry\SimulationService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The Network monitor (FR-312 to FR-314): every access point on one screen,
 * campus activity over the last day, and — for engineers and administrators on
 * a simulated installation — the controls to take a sample or force a
 * scenario.
 *
 * One page for all three roles. It is reached through three role-prefixed
 * routes only because the sidebar's navigation names must carry the role
 * (docs/06-ui-conventions.md); an assistant sees the same page with the
 * controls left out.
 *
 * Every rule is in NetworkMonitorService and SimulationService. This class
 * picks the view, and turns a refusal into a message.
 */
class NetworkMonitorController extends Controller
{
    public function __construct(
        private readonly NetworkMonitorService $monitor,
        private readonly SimulationService $simulation,
    ) {}

    public function index(Request $request): View
    {
        $data = $this->monitor->snapshot();

        return view('monitor.index', [
            'data' => $data,
            'pollInterval' => (int) config('atlas.poll_interval'),
            'simulated' => $this->simulation->available(),
            'canSimulate' => $this->canSimulate($request->user()),
            'scenarios' => SimulationScenario::cases(),
            // The filter lists only locations that have an access point, taken
            // from the rows already loaded rather than a second query.
            'locations' => collect($data['access_points'])
                ->whereNotNull('location_id')
                ->unique('location_id')
                ->sortBy('location')
                ->mapWithKeys(fn (array $ap) => [$ap['location_id'] => $ap['location'].' — '.$ap['location_name']])
                ->all(),
        ]);
    }

    /** The polling endpoint (FR-302 applies here as on the dashboard). */
    public function data(): JsonResponse
    {
        return response()->json($this->monitor->snapshot());
    }

    /** One access point, for the detail dialog. */
    public function show(AccessPoint $accessPoint): JsonResponse
    {
        return response()->json($this->monitor->accessPoint($accessPoint));
    }

    public function sample(): RedirectResponse
    {
        try {
            $changes = $this->simulation->sample();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $changes->isEmpty()
            ? 'Sample taken. No access point changed status.'
            : 'Sample taken. '.$this->describe($changes));
    }

    public function simulate(RunSimulationRequest $request): RedirectResponse
    {
        $scenario = $request->scenario();
        $target = $request->target();

        try {
            $count = $this->simulation->apply($scenario, $target);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($count === 0) {
            return back()->with('warning', $scenario === null
                ? 'Nothing to recover: no access point is running a scenario.'
                : 'No access point is being polled, so there was nothing to apply it to.');
        }

        $where = $target?->ap_code ?? "{$count} access ".str('point')->plural($count);

        return back()->with('success', match ($scenario) {
            null => "Recovery applied to {$where}. Normal telemetry resumes from this reading.",
            SimulationScenario::Congestion => "Congestion applied to {$where}. It turns Degraded after "
                .config('atlas.thresholds.consecutive_breach').' readings in a row over threshold: '
                .'one a minute with the scheduler running, or one per Generate sample.',
            SimulationScenario::Outage => "AP outage applied to {$where}. It is Offline from this reading, "
                .'and going offline raises one ticket and alerts the engineers.',
            SimulationScenario::FeedInterruption => "Feed interruption applied to {$where}. No readings arrive, "
                .'so it shows as unknown after '.config('atlas.thresholds.offline_after_min')
                .' minutes. It is not treated as an outage.',
        });
    }

    private function canSimulate(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Engineer], true)
            && $this->simulation->available();
    }

    /** "AP-03 Online → Offline, AP-07 …" — three named, the rest counted. */
    private function describe(Collection $changes): string
    {
        $named = $changes->take(3)
            ->map(fn (array $c) => "{$c['access_point']->ap_code} {$c['from']->label()} → {$c['to']->label()}")
            ->implode(', ');

        $rest = $changes->count() - 3;

        return $named.($rest > 0 ? " and {$rest} more" : '').'.';
    }
}
