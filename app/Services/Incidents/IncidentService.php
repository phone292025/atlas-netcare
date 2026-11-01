<?php

namespace App\Services\Incidents;

use App\Enums\IncidentRule;
use App\Enums\IncidentStatus;
use App\Enums\NetworkStatus;
use App\Models\Agent;
use App\Models\DeviceStatusChange;
use App\Models\Incident;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Support\TableLock;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning status changes into incidents, and incidents into tickets and
 * alerts (ADR-0014, roadmap 2.2 to 2.5).
 *
 * Two ways in, with the same rules. The listener passes each batch of status
 * changes as Telemetry publishes it. The sweep, every minute, compares the
 * incidents with the devices' current statuses and fixes any difference, so
 * an event lost to a crashed worker delays an incident by a minute and never
 * loses it.
 *
 * Every write takes a lock on `incidents` first. Grouping reads several
 * incidents and devices before it decides, and two workers deciding at once
 * could each open an incident for the same switch's failure. Incidents are
 * written a few times an hour, so the queue is never long.
 *
 * @see docs/modules/M8-incidents.md
 */
class IncidentService
{
    public function __construct(
        private readonly IncidentTickets $tickets,
        private readonly OnCall $onCall,
        private readonly IncidentLog $timeline,
    ) {}

    // ----------------------------------------------------------- status changes

    /**
     * React to a batch of status changes, highest devices first, so a switch
     * that failed with its access points opens the one incident they join.
     *
     * @param  list<array{device_id: int, from: string, to: string}>  $changes
     */
    public function statusesChanged(array $changes, Carbon $at): void
    {
        DB::transaction(function () use ($changes, $at) {
            TableLock::forWriting('incidents');

            $devices = NetworkDevice::query()->whereIn('id', array_column($changes, 'device_id'))->get()->keyBy('id');

            DeviceStatusChange::query()->insert(array_map(fn (array $c) => [
                'network_device_id' => $c['device_id'],
                'from_status' => $c['from'],
                'to_status' => $c['to'],
                'changed_at' => $at,
            ], array_values(array_filter($changes, fn (array $c) => $devices->has($c['device_id'])))));

            $ordered = collect($changes)
                ->filter(fn (array $c) => $devices->has($c['device_id']))
                ->sortBy(fn (array $c) => $this->depth($devices[$c['device_id']]));

            foreach ($ordered as $change) {
                $device = $devices[$change['device_id']];
                $to = NetworkStatus::from($change['to']);

                // A retried or late event about a status the device has since
                // left. Acting on it would open an outage on a device that is
                // up. The event that moved it on brings its own change, and
                // the sweep covers any that was lost.
                if ($device->status !== $to) {
                    continue;
                }

                $this->apply($device, NetworkStatus::from($change['from']), $to, $at);
            }
        });
    }

    private function apply(NetworkDevice $device, NetworkStatus $from, NetworkStatus $to, Carbon $at): void
    {
        if ($to === NetworkStatus::Offline) {
            $this->deviceDown($device, $at);
        } elseif ($from === NetworkStatus::Offline && $this->isBack($to)) {
            $this->deviceUp($device, $at);
        }

        /*
         * Degraded only from Online, or from Unknown, which nobody was told
         * the truth behind. A device coming back from an outage passes
         * through Degraded, because its unreachable readings count as 100%
         * packet loss: that is recovery, not a new fault.
         */
        if ($to === NetworkStatus::Degraded && in_array($from, [NetworkStatus::Online, NetworkStatus::Unknown], true)) {
            $this->openDegraded($device, $at);
        } elseif ($to === NetworkStatus::Online) {
            $this->resolveOn($device, IncidentRule::Degraded, 'recovered', $at);
        }

        $this->checkFlapping($device, $at);
    }

    /** Answering again, or taken out of service on purpose. Unknown is neither. */
    private function isBack(NetworkStatus $status): bool
    {
        return in_array($status, [NetworkStatus::Online, NetworkStatus::Degraded, NetworkStatus::Maintenance], true);
    }

    // ----------------------------------------------------------- offline and grouping

    /**
     * A device stopped answering. It joins the open incident of a failed
     * device above it, or opens its own, which takes in the incidents of any
     * failed devices below it (roadmap 2.3).
     */
    private function deviceDown(NetworkDevice $device, Carbon $at): void
    {
        if ($this->openOfflineCovering($device)) {
            return;
        }

        foreach ($this->ancestors($device) as $ancestor) {
            if ($incident = $this->openOn($ancestor, IncidentRule::Offline)) {
                $this->join($incident, $device, $at);

                return;
            }
        }

        $incident = $this->open($device, IncidentRule::Offline, "{$device->code} is not responding", $at);
        $this->join($incident, $device, $at, quiet: true);

        $below = $this->descendantIds($device);
        $absorbed = Incident::query()->unresolved()
            ->where('rule', IncidentRule::Offline)
            ->whereIn('network_device_id', $below)
            ->with('devices')
            ->get();

        foreach ($absorbed as $child) {
            foreach ($child->devices->whereNull('pivot.recovered_at') as $member) {
                $this->join($incident, $member, $at);
            }

            $this->resolve($child, 'grouped', $at, groupedInto: $incident);
        }

        $this->tickets->raiseFor($incident, null, $absorbed->count());
        $this->onCall->start($incident);
    }

    /**
     * A device answers again. Its incident resolves, and any device in it
     * that is still down starts again on its own: it was behind this one,
     * and is now a fault of its own.
     */
    private function deviceUp(NetworkDevice $device, Carbon $at): void
    {
        DB::table('incident_devices')
            ->where('network_device_id', $device->id)
            ->whereNull('recovered_at')
            ->whereIn('incident_id', Incident::query()->unresolved()->where('rule', IncidentRule::Offline)->select('id'))
            ->update(['recovered_at' => $at, 'updated_at' => now()]);

        $incident = $this->openOn($device, IncidentRule::Offline);

        if (! $incident) {
            return;
        }

        $stillDown = $incident->devices()->wherePivotNull('recovered_at')->get()
            ->filter(fn (NetworkDevice $member) => $member->status === NetworkStatus::Offline);

        $this->resolve($incident, 'recovered', $at);

        foreach ($stillDown->sortBy(fn (NetworkDevice $member) => $this->depth($member)) as $member) {
            $this->deviceDown($member, $at);
        }
    }

    private function join(Incident $incident, NetworkDevice $device, Carbon $at, bool $quiet = false): void
    {
        $joined = DB::table('incident_devices')->insertOrIgnore([
            'incident_id' => $incident->id,
            'network_device_id' => $device->id,
            'joined_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Down again after coming back while the incident was still open.
        $joined = $joined ?: DB::table('incident_devices')
            ->where('incident_id', $incident->id)
            ->where('network_device_id', $device->id)
            ->whereNotNull('recovered_at')
            ->update(['recovered_at' => null, 'updated_at' => now()]);

        if ($joined && ! $quiet) {
            $this->log($incident, 'device_joined', "added {$device->code}, down behind ".$incident->subjectLabel().'.', at: $at);
        }
    }

    // ----------------------------------------------------------- degraded and flapping

    private function openDegraded(NetworkDevice $device, Carbon $at): void
    {
        if ($this->openOn($device, IncidentRule::Degraded)) {
            return;
        }

        // At most one per cooldown: at peak a device hovering near a limit
        // turns degraded every few minutes, and that is one problem.
        $recently = Incident::query()
            ->where('network_device_id', $device->id)
            ->where('rule', IncidentRule::Degraded)
            ->where('opened_at', '>=', $at->copy()->subMinutes((int) config('atlas.thresholds.degraded_alert_cooldown_min')))
            ->exists();

        if ($recently) {
            return;
        }

        $incident = $this->open($device, IncidentRule::Degraded, "{$device->code} is degraded", $at);
        $this->onCall->start($incident);
    }

    private function checkFlapping(NetworkDevice $device, Carbon $at): void
    {
        if ($this->openOn($device, IncidentRule::Flapping)) {
            return;
        }

        $changes = DeviceStatusChange::query()
            ->where('network_device_id', $device->id)
            ->where('changed_at', '>=', $at->copy()->subMinutes((int) config('atlas.incidents.flapping_window_min')))
            ->count();

        if ($changes < (int) config('atlas.incidents.flapping_changes')) {
            return;
        }

        $incident = $this->open($device, IncidentRule::Flapping, "{$device->code} keeps changing status", $at);
        $this->log($incident, 'note', "counted {$changes} status changes in ".config('atlas.incidents.flapping_window_min').' minutes.', at: $at);
        $this->onCall->start($incident);
    }

    // ----------------------------------------------------------- silent agents

    public function agentSilenceChanged(int $agentId, bool $silent, Carbon $at): void
    {
        DB::transaction(function () use ($agentId, $silent, $at) {
            TableLock::forWriting('incidents');

            $agent = Agent::query()->find($agentId);

            if (! $agent) {
                return;
            }

            $silent ? $this->agentSilent($agent, $at) : $this->agentBack($agent, $at);
        });
    }

    private function agentSilent(Agent $agent, Carbon $at): void
    {
        if (Incident::query()->unresolved()->where('agent_id', $agent->id)->where('rule', IncidentRule::Silent)->exists()) {
            return;
        }

        $incident = new Incident;
        $incident->forceFill([
            'rule' => IncidentRule::Silent,
            'agent_id' => $agent->id,
            'title' => "{$agent->name} has stopped reporting",
            'opened_at' => $at,
        ])->save();

        $this->log($incident, 'opened', 'opened it. Last heard from at '.($agent->last_seen_at?->campus()->format('j M, H:i') ?? 'never')
            .'. Its devices show as unknown until it reports again.', at: $at);
        $this->onCall->start($incident);
    }

    private function agentBack(Agent $agent, Carbon $at): void
    {
        Incident::query()->unresolved()->where('agent_id', $agent->id)->where('rule', IncidentRule::Silent)->get()
            ->each(fn (Incident $incident) => $this->resolve($incident, 'recovered', $at));
    }

    // ----------------------------------------------------------- the sweep

    /**
     * Compare the incidents with the devices' and agents' current state, and
     * fix every difference. Returns how many incidents it opened and
     * resolved.
     *
     * @return array{opened: int, resolved: int}
     */
    public function sweep(?Carbon $at = null): array
    {
        $at ??= now();

        return DB::transaction(function () use ($at) {
            TableLock::forWriting('incidents');
            $before = Incident::query()->unresolved()->pluck('id');

            // An outage whose event never arrived.
            NetworkDevice::query()->where('status', NetworkStatus::Offline)->get()
                ->sortBy(fn (NetworkDevice $device) => $this->depth($device))
                ->each(fn (NetworkDevice $device) => $this->deviceDown($device, $at));

            // A recovery whose event never arrived, or a device since retired.
            Incident::query()->unresolved()->where('rule', IncidentRule::Offline)->with('device')->get()
                ->each(function (Incident $incident) use ($at) {
                    $device = $incident->device;

                    if (! $device || $device->trashed()) {
                        $this->resolve($incident, 'retired', $at);
                    } elseif ($this->isBack($device->status)) {
                        $this->deviceUp($device, $at);
                    }
                });

            Incident::query()->unresolved()->where('rule', IncidentRule::Degraded)->with('device')->get()
                ->filter(fn (Incident $i) => ! $i->device || $i->device->trashed() || $i->device->status === NetworkStatus::Online)
                ->each(fn (Incident $i) => $this->resolve($i, 'recovered', $at));

            // Flapping ends once the device has settled for a whole window.
            $window = $at->copy()->subMinutes((int) config('atlas.incidents.flapping_window_min'));
            Incident::query()->unresolved()->where('rule', IncidentRule::Flapping)->get()
                ->filter(fn (Incident $i) => ! DeviceStatusChange::query()->where('network_device_id', $i->network_device_id)->where('changed_at', '>=', $window)->exists())
                ->each(fn (Incident $i) => $this->resolve($i, 'settled', $at));

            Agent::query()->whereNotNull('silent_since')->get()->each(fn (Agent $agent) => $this->agentSilent($agent, $at));
            Incident::query()->unresolved()->where('rule', IncidentRule::Silent)->with('agent')->get()
                ->filter(fn (Incident $i) => ! $i->agent || $i->agent->silent_since === null)
                ->each(fn (Incident $i) => $this->resolve($i, 'recovered', $at));

            DeviceStatusChange::query()->where('changed_at', '<', $at->copy()->subDays((int) config('atlas.incidents.history_days')))->delete();

            $after = Incident::query()->unresolved()->pluck('id');

            return ['opened' => $after->diff($before)->count(), 'resolved' => $before->diff($after)->count()];
        });
    }

    // ----------------------------------------------------------- people

    /** Somebody has it. The escalation stops; the incident still resolves itself. */
    public function acknowledge(Incident $incident, User $user): Incident
    {
        return DB::transaction(function () use ($incident, $user) {
            TableLock::forWriting('incidents');
            $incident->refresh();

            if ($incident->isResolved()) {
                throw new DomainException('This incident has already resolved.');
            }

            if ($incident->status === IncidentStatus::Acknowledged) {
                return $incident;
            }

            $incident->forceFill([
                'status' => IncidentStatus::Acknowledged,
                'acknowledged_at' => now(),
                'acknowledged_by' => $user->id,
                'next_escalation_at' => null,
            ])->save();

            $this->log($incident, 'acknowledged', 'acknowledged it.', $user);

            return $incident;
        });
    }

    /**
     * An engineer closes a degraded or flapping incident by hand, with why:
     * a known busy hour, a link already being replaced. An offline or silent
     * one resolves only when the device or agent is back, or the next sweep
     * would open it again a minute later.
     */
    public function resolveByHand(Incident $incident, User $user, string $note): Incident
    {
        return DB::transaction(function () use ($incident, $user, $note) {
            TableLock::forWriting('incidents');
            $incident->refresh();

            if ($incident->isResolved()) {
                throw new DomainException('This incident has already resolved.');
            }

            if (! $incident->rule->canBeResolvedByHand()) {
                throw new DomainException('It resolves itself when the '.($incident->agent_id ? 'agent reports' : 'device answers').' again.');
            }

            $this->resolve($incident, 'by_hand', now());
            $this->log($incident, 'note', "noted: {$note}", $user);

            return $incident;
        });
    }

    /** An engineer decides a degraded or flapping incident needs work too. */
    public function raiseTicket(Incident $incident, User $user): Incident
    {
        return DB::transaction(function () use ($incident, $user) {
            TableLock::forWriting('incidents');
            $incident->refresh();

            if ($incident->ticket_id !== null) {
                throw new DomainException('This incident already has a ticket.');
            }

            if (! $incident->device) {
                throw new DomainException('Only an incident on a device can have a ticket.');
            }

            $this->tickets->raiseFor($incident, $user);

            return $incident->refresh();
        });
    }

    // ----------------------------------------------------------- building blocks

    private function open(NetworkDevice $device, IncidentRule $rule, string $title, Carbon $at): Incident
    {
        $incident = new Incident;
        $incident->forceFill([
            'rule' => $rule,
            'network_device_id' => $device->id,
            'title' => $title,
            'opened_at' => $at,
            'is_demo' => (bool) $device->is_demo,
        ])->save();

        $this->log($incident, 'opened', 'opened it: '.mb_strtolower($rule->label()).($device->location ? ' in '.$device->location->label() : '').'.', at: $at);

        return $incident;
    }

    public function resolve(Incident $incident, string $resolution, Carbon $at, ?Incident $groupedInto = null): void
    {
        $incident->forceFill([
            'status' => IncidentStatus::Resolved,
            'resolved_at' => $at,
            'resolution' => $resolution,
            'grouped_into_id' => $groupedInto?->id,
            'next_escalation_at' => null,
        ])->save();

        $this->log($incident, 'resolved', match ($resolution) {
            'grouped' => 'grouped it into the incident on '.$groupedInto?->subjectLabel().', which failed too.',
            'retired' => 'resolved it: the device was retired.',
            'settled' => 'resolved it: the device has kept one status for '.config('atlas.incidents.flapping_window_min').' minutes.',
            'by_hand' => 'resolved it by hand.',
            default => $incident->rule === IncidentRule::Silent ? 'resolved it: the agent is reporting again.' : 'resolved it: the device has recovered.',
        }, at: $at);
    }

    private function log(Incident $incident, string $type, string $message, ?User $user = null, ?Carbon $at = null): void
    {
        $this->timeline->write($incident, $type, $message, $user, $at);
    }

    private function resolveOn(NetworkDevice $device, IncidentRule $rule, string $resolution, Carbon $at): void
    {
        if ($incident = $this->openOn($device, $rule)) {
            $this->resolve($incident, $resolution, $at);
        }
    }

    private function openOn(NetworkDevice $device, IncidentRule $rule): ?Incident
    {
        return Incident::query()->unresolved()->where('network_device_id', $device->id)->where('rule', $rule)->first();
    }

    /** Whether the device is already down in an open offline incident, its own or a parent's. */
    private function openOfflineCovering(NetworkDevice $device): bool
    {
        return DB::table('incident_devices')
            ->join('incidents', 'incidents.id', '=', 'incident_devices.incident_id')
            ->where('incident_devices.network_device_id', $device->id)
            ->whereNull('incident_devices.recovered_at')
            ->whereNull('incidents.resolved_at')
            ->where('incidents.rule', IncidentRule::Offline->value)
            ->exists();
    }

    /** @return Collection<int, NetworkDevice> nearest first */
    private function ancestors(NetworkDevice $device): Collection
    {
        $found = collect();
        $seen = [$device->id => true];
        $next = $device->parent_id;

        while ($next !== null && ! isset($seen[$next])) {
            $parent = NetworkDevice::query()->find($next);

            if (! $parent) {
                break;
            }

            $seen[$next] = true;
            $found->push($parent);
            $next = $parent->parent_id;
        }

        return $found;
    }

    /** @return list<int> */
    private function descendantIds(NetworkDevice $device): array
    {
        $all = [];
        $level = [$device->id];

        while ($level !== []) {
            $level = NetworkDevice::query()->whereIn('parent_id', $level)->whereNotIn('id', [...$all, $device->id])->pluck('id')->all();
            $all = [...$all, ...$level];
        }

        return $all;
    }

    private function depth(NetworkDevice $device): int
    {
        return $this->ancestors($device)->count();
    }
}
