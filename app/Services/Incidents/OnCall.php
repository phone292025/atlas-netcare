<?php

namespace App\Services\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Incident;
use App\Models\Shift;
use App\Models\User;
use App\Support\CampusClock;
use App\Support\TableLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is told about an incident, and when the next people are (roadmap 2.5).
 *
 * The roster is the on-call schedule. An incident first goes to the
 * assistants checked in to a shift now, those in the device's room before
 * anybody else; then, if nobody has acknowledged it within
 * `atlas.incidents.escalate_after_min`, to the engineers; then to the
 * administrators. A step with nobody in it, such as no assistant on shift
 * at night, is skipped at once. IncidentRule::escalation() lists the steps.
 */
class OnCall
{
    public function __construct(
        private readonly IncidentAlerts $alerts,
        private readonly IncidentLog $log,
    ) {}

    /** Alert the first step that has anybody in it. */
    public function start(Incident $incident): void
    {
        $this->alertFrom($incident, 0);
    }

    /**
     * Move every unacknowledged incident whose wait is over to its next step.
     * Returns how many moved.
     */
    public function escalateDue(): int
    {
        return DB::transaction(function () {
            TableLock::forWriting('incidents');

            $due = Incident::query()->unresolved()
                ->where('status', IncidentStatus::Open)
                ->whereNotNull('next_escalation_at')
                ->where('next_escalation_at', '<=', now())
                ->get();

            foreach ($due as $incident) {
                $this->alertFrom($incident, $incident->escalation_step + 1, escalated: true);
            }

            return $due->count();
        });
    }

    /**
     * The people in one step of this incident's escalation.
     *
     * @return Collection<int, User>
     */
    public function recipients(Incident $incident, int $step): Collection
    {
        $roles = $incident->rule->escalation()[$step] ?? [];
        $people = collect();

        foreach ($roles as $role) {
            $people = $people->concat($role === UserRole::Assistant
                ? $this->assistantsOnShift($incident)
                : User::query()->active()->role($role)->orderBy('id')->get());
        }

        return $people->unique('id')->values();
    }

    private function alertFrom(Incident $incident, int $step, bool $escalated = false): void
    {
        $steps = count($incident->rule->escalation());

        for (; $step < $steps; $step++) {
            $people = $this->recipients($incident, $step);

            if ($people->isEmpty()) {
                continue;
            }

            $this->alerts->send($incident, $people, $step);

            $last = $step === $steps - 1;
            $incident->forceFill([
                'escalation_step' => $step,
                'next_escalation_at' => $last ? null : now()->addMinutes((int) config('atlas.incidents.escalate_after_min')),
            ])->save();

            $names = $people->pluck('name')->take(3)->implode(', ').($people->count() > 3 ? ' and '.($people->count() - 3).' more' : '');
            $this->log->write($incident, $escalated ? 'escalated' : 'alerted', ($escalated ? 'Nobody acknowledged it. Told ' : 'Told ').$names.'.');

            return;
        }

        // Nobody left to tell: stop asking every minute.
        $incident->forceFill(['next_escalation_at' => null])->save();
    }

    /**
     * Assistants checked in to a shift today. Those whose shift is in the
     * device's location come first and alone: they are in the room.
     *
     * @return Collection<int, User>
     */
    private function assistantsOnShift(Incident $incident): Collection
    {
        $shifts = Shift::query()
            ->on(CampusClock::today())
            ->where('status', ShiftStatus::CheckedIn)
            ->with('user')
            ->get()
            ->filter(fn (Shift $shift) => $shift->user?->is_active);

        $here = $shifts->where('location_id', $incident->device?->location_id);

        return ($here->isNotEmpty() ? $here : $shifts)->pluck('user')->unique('id')->values();
    }
}
