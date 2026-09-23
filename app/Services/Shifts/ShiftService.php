<?php

namespace App\Services\Shifts;

use App\Enums\AlarmType;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\CampusClock;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generating the roster, and an assistant's day on it.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 * @see docs/02-open-questions.md Q4
 */
class ShiftService
{
    public function __construct(private readonly AlarmService $alarms) {}

    /**
     * Create shifts for a week from the active templates (Q4).
     *
     * Idempotent: it runs weekly and somebody will also run it by hand, so a
     * second pass fills only the slots still empty. Only slots that have not
     * started: a shift created after its start is a no-show nobody could have
     * attended, and it would be marked missed within minutes.
     *
     * Assistants are picked by how few shifts they already have that week; not
     * a fairness algorithm, but it stops one person being given every slot.
     *
     * @return Collection<int, Shift>
     */
    public function generateWeek(Carbon $weekStart): Collection
    {
        $weekStart = $weekStart->copy()->startOfWeek();
        $templates = ShiftTemplate::query()->active()->with('location')->get();

        if ($templates->isEmpty()) {
            return collect();
        }

        $assistants = User::query()->active()->role(UserRole::Assistant)->orderBy('id')->get();

        if ($assistants->isEmpty()) {
            throw new DomainException('There are no active assistants to roster.');
        }

        $created = collect();

        DB::transaction(function () use ($templates, $assistants, $weekStart, &$created) {
            // Existing shifts count towards each person's load, so a re-run
            // does not pile more work on the same people.
            $load = Shift::query()
                ->whereBetween('shift_date', [$weekStart, $weekStart->copy()->endOfWeek()])
                ->get()
                ->countBy('user_id');

            foreach ($templates as $template) {
                $date = $weekStart->copy()->addDays(
                    // day_of_week is 0 = Sunday (Carbon's numbering), and the
                    // week starts on Monday, so Sunday is the last day.
                    ($template->day_of_week + 6) % 7
                );

                if (Carbon::parse($date->toDateString().' '.$template->start_time, CampusClock::timezone())->isPast()) {
                    continue;
                }

                // Start from what the slot already holds. The per-person check
                // below stops double-booking one assistant, not filling one
                // slot twice with two different people.
                $alreadyFilled = Shift::query()
                    ->where('shift_template_id', $template->id)
                    ->whereDate('shift_date', $date)
                    ->count();

                for ($slot = $alreadyFilled; $slot < $template->required_assistants; $slot++) {
                    $assistant = $assistants
                        ->sortBy(fn (User $u) => $load->get($u->id, 0))
                        ->first(fn (User $u) => ! $this->alreadyRostered($u, $date, $template));

                    if (! $assistant) {
                        // Everyone is already on at this time. Under-filling
                        // is honest; double-booking is not.
                        continue;
                    }

                    $shift = Shift::create([
                        'shift_template_id' => $template->id,
                        'user_id' => $assistant->id,
                        'location_id' => $template->location_id,
                        'shift_date' => $date->toDateString(),
                        // Copied, not read through the template, so editing a
                        // pattern never rewrites what somebody already worked.
                        'start_time' => $template->start_time,
                        'end_time' => $template->end_time,
                    ]);

                    $load->put($assistant->id, $load->get($assistant->id, 0) + 1);
                    $created->push($shift);

                    $this->scheduleShiftAlarms($shift, $assistant);
                }
            }
        });

        return $created;
    }

    /**
     * Reminders for one shift, scheduled in advance through `trigger_at`.
     * Roster generation and reassignment both come through here, so a shift
     * that changes hands keeps its inspection-round reminders.
     */
    private function scheduleShiftAlarms(
        Shift $shift,
        User $assistant,
        string $title = 'Your shift starts soon',
        ?string $message = null,
    ): void {
        $startsAt = $shift->startsAt();

        // Never dated in the past. A shift handed over once it is under way
        // gets its notice now; dated at the old reminder time, it was sorted
        // below everything since and read as old news.
        $this->alarms->raise(
            AlarmType::ShiftStart,
            $assistant,
            $title,
            $message ?? $shift->location?->label().', '.$shift->label(),
            at: $startsAt->copy()->subMinutes((int) config('atlas.shifts.reminder_before_min', 30))->max(now()),
            shift: $shift,
        );

        // Round reminders spaced through the shift. None in the last quarter
        // hour: being told to start a round as you leave is noise. None
        // already past either, or covering a shift sends them all at once.
        $interval = (int) config('atlas.shifts.round_interval_min', 120);
        $cursor = $startsAt->copy()->addMinutes($interval);

        while ($cursor->lt($shift->endsAt()->copy()->subMinutes(15))) {
            if ($cursor->isPast()) {
                $cursor->addMinutes($interval);

                continue;
            }

            $this->alarms->raise(
                AlarmType::InspectionRound,
                $assistant,
                'Time for an inspection round',
                'Machines in '.($shift->location?->code ?? 'your location').' are due a check.',
                at: $cursor->copy(),
                shift: $shift,
            );

            $cursor->addMinutes($interval);
        }
    }

    private function alreadyRostered(User $user, Carbon $date, ShiftTemplate $template): bool
    {
        return Shift::query()
            ->where('user_id', $user->id)
            ->whereDate('shift_date', $date)
            ->where('start_time', $template->start_time)
            ->exists();
    }

    /**
     * Start a shift.
     *
     * @throws DomainException when it is not this person's shift to start, or
     *                         not the right time to start it
     */
    public function checkIn(Shift $shift, User $user): Shift
    {
        if ($shift->user_id !== $user->id) {
            throw new DomainException('That is not your shift.');
        }

        if ($shift->status !== ShiftStatus::Scheduled) {
            throw new DomainException('This shift has already been started.');
        }

        if (! $shift->canCheckInNow()) {
            throw new DomainException('It is not time for this shift yet.');
        }

        $shift->forceFill([
            'status' => ShiftStatus::CheckedIn,
            'checked_in_at' => now(),
        ])->save();

        return $shift;
    }

    /**
     * End a shift, recording what the next person needs to know (FR-211).
     */
    public function checkOut(Shift $shift, User $user, ?string $handoverNotes = null): Shift
    {
        if ($shift->user_id !== $user->id) {
            throw new DomainException('That is not your shift.');
        }

        if ($shift->status !== ShiftStatus::CheckedIn) {
            throw new DomainException('You are not checked in to this shift.');
        }

        $shift->forceFill([
            'status' => ShiftStatus::Completed,
            'checked_out_at' => now(),
            'handover_notes' => $handoverNotes,
        ])->save();

        if (filled($handoverNotes)) {
            $this->passHandoverOn($shift, $handoverNotes);
        }

        return $shift;
    }

    /**
     * Send the notes to the next scheduled shift in the same place, timed for
     * when it starts rather than now, so they arrive when they are useful.
     */
    private function passHandoverOn(Shift $shift, string $notes): void
    {
        $next = Shift::query()
            ->where('location_id', $shift->location_id)
            ->where('id', '!=', $shift->id)
            ->where('status', ShiftStatus::Scheduled)
            ->whereDate('shift_date', '>=', $shift->shift_date)
            ->orderBy('shift_date')->orderBy('start_time')
            ->first();

        if (! $next || ! $next->user) {
            return;
        }

        $this->alarms->raise(
            AlarmType::Handover,
            $next->user,
            'Handover from the previous shift',
            $notes,
            at: $next->startsAt(),
            shift: $next,
        );
    }

    /**
     * Mark shifts nobody turned up to: ended and still `scheduled`. A shift
     * somebody checked into and forgot to leave is a different problem.
     *
     * @return int how many were marked
     */
    public function markMissed(): int
    {
        $stale = Shift::query()
            ->where('status', ShiftStatus::Scheduled)
            ->whereDate('shift_date', '<=', CampusClock::today())
            ->get()
            ->filter(fn (Shift $shift) => $shift->endsAt()->isPast());

        foreach ($stale as $shift) {
            $shift->forceFill(['status' => ShiftStatus::Missed])->save();
        }

        return $stale->count();
    }

    /** Today's shift on campus for this person, if they have one. */
    public function todayFor(User $user): ?Shift
    {
        return Shift::query()
            ->forUser($user)
            ->on(CampusClock::today())
            ->with(['location', 'template'])
            ->orderBy('start_time')
            ->first();
    }

    /**
     * Move one shift to a different assistant (FR-408): how a real week
     * diverges from the generated pattern.
     *
     * Only a SCHEDULED shift that has not ENDED. A started, completed or missed
     * one is history, and `checked_in_at` records who was actually there. One
     * that has begun with nobody checked in can still go to somebody who can
     * cover the rest of it.
     *
     * Only to an ACTIVE ASSISTANT, or nobody will ever check in to it.
     *
     * Not onto a CLASH. The unique index would refuse it anyway; checked here
     * so the admin gets a sentence rather than an integrity error.
     *
     * Unsent alarms follow the shift: its reminders are rebuilt for the new
     * assignee and anything else pending, such as a handover note, moves
     * across. Delivered alarms stay; they record something that happened.
     *
     * @throws DomainException when any of those rules is broken
     */
    public function reassign(Shift $shift, User $to): Shift
    {
        if ($shift->status !== ShiftStatus::Scheduled) {
            throw new DomainException(
                'This shift has already started or finished, so it cannot be reassigned.'
            );
        }

        if (! $shift->isReassignable()) {
            throw new DomainException('This shift has already ended, so it cannot be reassigned.');
        }

        if ($to->role !== UserRole::Assistant || ! $to->is_active) {
            throw new DomainException('Shifts can only be assigned to an active assistant.');
        }

        if ($to->id === $shift->user_id) {
            throw new DomainException('That shift is already assigned to them.');
        }

        $clash = Shift::query()
            ->where('user_id', $to->id)
            ->where('shift_date', $shift->shift_date)
            ->where('start_time', $shift->start_time)
            ->exists();

        if ($clash) {
            throw new DomainException($to->name.' is already rostered at that time.');
        }

        return DB::transaction(function () use ($shift, $to) {
            $from = $shift->user;

            $shift->forceFill(['user_id' => $to->id])->save();

            $shift->alarms()->whereNull('sent_at')
                ->whereIn('type', [AlarmType::ShiftStart, AlarmType::InspectionRound])
                ->delete();

            $shift->alarms()->whereNull('sent_at')->update(['user_id' => $to->id]);

            $this->scheduleShiftAlarms(
                $shift,
                $to,
                'You have been given a shift',
                trim(($shift->location?->label() ?? 'No location').', '.$shift->label()
                    .($from ? ' — taken over from '.$from->name : '')),
            );

            return $shift->fresh(['user', 'location']);
        });
    }

    /**
     * Take a shift off the roster (FR-408): the lab is closed that day, or
     * the slot was never needed.
     *
     * Only before it STARTS. From then on it is attendance: nobody coming is
     * recorded as missed, and removing it would hide that. Its unsent alarms
     * go with it, except a handover note, which moves to the next shift in the
     * same place. Delivered ones stay, as in reassign(). The assistant is
     * told, because the shift was on their screen and in their calendar.
     *
     * @throws DomainException when the shift has started or finished
     */
    public function remove(Shift $shift): void
    {
        if (! $shift->isRemovable()) {
            throw new DomainException(
                'This shift has already started, so it stays on the roster as a record of who was there.'
            );
        }

        DB::transaction(function () use ($shift) {
            $handovers = $shift->alarms()->whereNull('sent_at')
                ->where('type', AlarmType::Handover)
                ->pluck('message');

            $shift->alarms()->whereNull('sent_at')->delete();
            // Detached, or the foreign key's cascade takes them with the shift.
            $shift->alarms()->update(['shift_id' => null]);
            $shift->delete();

            foreach ($handovers as $notes) {
                $this->passHandoverOn($shift, (string) $notes);
            }

            if ($shift->user) {
                $this->alarms->raise(
                    AlarmType::ShiftRemoved,
                    $shift->user,
                    'Your shift has been removed',
                    ($shift->location?->label() ?? 'No location').', '.$shift->label().'. You do not need to come in.',
                );
            }
        });
    }

    /**
     * Remove every shift in a week that has not started, and keep the rest.
     * "Generate this week" puts removed shifts back from the templates.
     *
     * @return array{removed: int, kept: int}
     */
    public function clearWeek(Carbon $weekStart): array
    {
        $weekStart = $weekStart->copy()->startOfWeek();

        $shifts = Shift::query()
            ->with(['user', 'location'])
            ->whereBetween('shift_date', [$weekStart, $weekStart->copy()->endOfWeek()])
            // In order, so a handover note moved off one shift can move again
            // if the shift it lands on is removed next.
            ->orderBy('shift_date')->orderBy('start_time')
            ->get();

        $removable = $shifts->filter(fn (Shift $shift) => $shift->isRemovable());

        DB::transaction(fn () => $removable->each(fn (Shift $shift) => $this->remove($shift)));

        return ['removed' => $removable->count(), 'kept' => $shifts->count() - $removable->count()];
    }
}
