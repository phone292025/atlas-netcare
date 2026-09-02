<?php

namespace App\Services\Shifts;

use App\Enums\AlarmType;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
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
     * IDEMPOTENT. It is scheduled weekly, and somebody will also run it by
     * hand — the unique index on (user_id, shift_date, start_time) plus the
     * existence check below mean a second pass adds nothing rather than
     * rostering everybody twice.
     *
     * Assistants are assigned round-robin by how few shifts they already have
     * that week. That is not a real fairness algorithm and does not pretend to
     * be; it just stops the same person being given every slot, which is what
     * assigning in id order does.
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
            // Shifts already in this week, so re-running counts existing work
            // rather than piling more on the same people.
            $load = Shift::query()
                ->whereBetween('shift_date', [$weekStart, $weekStart->copy()->endOfWeek()])
                ->get()
                ->countBy('user_id');

            foreach ($templates as $template) {
                $date = $weekStart->copy()->addDays(
                    // 0 = Sunday to match Carbon::dayOfWeek, and startOfWeek()
                    // is Monday — so Sunday is the last day of this week, not
                    // the first. Getting this wrong shifts the whole roster by
                    // six days and looks like the generator simply not working.
                    ($template->day_of_week + 6) % 7
                );

                for ($slot = 0; $slot < $template->required_assistants; $slot++) {
                    $assistant = $assistants
                        ->sortBy(fn (User $u) => $load->get($u->id, 0))
                        ->first(fn (User $u) => ! $this->alreadyRostered($u, $date, $template));

                    if (! $assistant) {
                        // Every assistant is already on at this exact time.
                        // Under-filling is honest; double-booking is not.
                        continue;
                    }

                    $shift = Shift::create([
                        'shift_template_id' => $template->id,
                        'user_id' => $assistant->id,
                        'location_id' => $template->location_id,
                        'shift_date' => $date->toDateString(),
                        // Copied, not read through the template: changing
                        // "Mondays 09:00" to 10:00 must not rewrite what
                        // somebody already worked.
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
     * Reminders for one shift, scheduled in advance.
     *
     * This is why alarms carry `trigger_at` rather than being written when
     * they fire: the shift exists a week early, so its reminders can too.
     */
    private function scheduleShiftAlarms(Shift $shift, User $assistant): void
    {
        $startsAt = $shift->startsAt();

        $this->alarms->raise(
            AlarmType::ShiftStart,
            $assistant,
            'Your shift starts soon',
            $shift->location?->label().', '.$shift->label(),
            at: $startsAt->copy()->subMinutes((int) config('atlas.shifts.reminder_before_min', 30)),
            shift: $shift,
        );

        /*
         * Inspection-round reminders, spaced through the shift. A four-hour
         * shift at a 120-minute interval gets one at +2h; the reminder at the
         * very end is skipped, because being told to start a round as you
         * leave is noise, and noise is how people learn to ignore the bell.
         */
        $interval = (int) config('atlas.shifts.round_interval_min', 120);
        $cursor = $startsAt->copy()->addMinutes($interval);

        while ($cursor->lt($shift->endsAt()->copy()->subMinutes(15))) {
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
     * Tell the next person on this location what was left for them.
     *
     * Handover notes nobody reads are just a diary. The next scheduled shift
     * in the same place gets an alarm when it starts — not now, because
     * telling somebody at 13:00 about a shift they begin tomorrow is how it
     * gets forgotten.
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
     * Mark shifts nobody turned up to.
     *
     * A missed shift is a status rather than an absence of one, so the roster
     * can show it and a report can count it. Only shifts whose end time has
     * passed while still `scheduled` — a shift someone checked into and forgot
     * to check out of is a different problem and not this one's to guess at.
     *
     * @return int how many were marked
     */
    public function markMissed(): int
    {
        $stale = Shift::query()
            ->where('status', ShiftStatus::Scheduled)
            ->whereDate('shift_date', '<=', today())
            ->get()
            ->filter(fn (Shift $shift) => $shift->endsAt()->isPast());

        foreach ($stale as $shift) {
            $shift->forceFill(['status' => ShiftStatus::Missed])->save();
        }

        return $stale->count();
    }

    /** Today's shift for this person, if they have one. */
    public function todayFor(User $user): ?Shift
    {
        return Shift::query()
            ->forUser($user)
            ->on(today())
            ->with(['location', 'template'])
            ->orderBy('start_time')
            ->first();
    }

    /**
     * Move one shift to a different assistant (FR-408).
     *
     * The other half of Q4. `generateWeek()` materialises a roster from
     * templates; this is how a real week diverges from the pattern, which it
     * always does — somebody is ill, somebody swaps, somebody is on leave.
     * Without it the only way to change an assignment is to edit the database.
     *
     * FOUR RULES, and each one exists because breaking it loses information:
     *
     * A shift that has been STARTED cannot be reassigned. `checked_in_at`
     * records that a particular person was physically there, and handing the
     * shift to somebody else would attach their name to work they did not do.
     * The same reasoning covers completed and missed shifts: all three are
     * history, and history is not reassignable.
     *
     * ONLY TO AN ACTIVE ASSISTANT. Rostering a deactivated account, or an
     * engineer, produces a shift nobody will ever check in to — and it would
     * be invisible until the morning it was needed.
     *
     * NOT ONTO A CLASH. `shifts` has a unique index on
     * (user_id, shift_date, start_time), so a double-booking would surface as
     * a raw integrity error. Checked here so the user gets a sentence instead.
     *
     * THE ORIGINAL PERSON'S ALARMS GO. A pre-shift reminder written for
     * somebody who is no longer on the shift is worse than no reminder: they
     * turn up, and the person actually rostered does not. The new assignee
     * gets their own reminder through the same path generateWeek() uses.
     *
     * @throws DomainException when any of the four rules is broken
     */
    public function reassign(Shift $shift, User $to): Shift
    {
        /*
         * Scheduled only, deliberately narrower than isOutstanding().
         *
         * That helper answers "is this still work to do", and a CHECKED-IN
         * shift is — but it is also a shift somebody is standing in the room
         * doing right now, and `checked_in_at` says who. Reassigning it would
         * attach their attendance to a different name.
         */
        if ($shift->status !== ShiftStatus::Scheduled) {
            throw new DomainException(
                'This shift has already started or finished, so it cannot be reassigned.'
            );
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

            // Drop the old reminder before writing the new one. Only unsent
            // alarms: one already delivered is a record of something that
            // happened and is not ours to erase.
            $shift->alarms()->whereNull('sent_at')->delete();

            $this->alarms->raise(
                AlarmType::ShiftStart,
                $to,
                'You have been given a shift',
                trim(($shift->location?->label() ?? 'No location').', '.$shift->label()
                    .($from ? ' — taken over from '.$from->name : '')),
                at: $shift->startsAt()->copy()->subMinutes(
                    (int) config('atlas.shifts.reminder_before_min', 30)
                ),
                shift: $shift,
            );

            return $shift->fresh(['user', 'location']);
        });
    }
}
