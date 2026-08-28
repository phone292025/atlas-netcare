<?php

namespace App\Services\Shifts;

use App\Enums\AlarmType;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The delivery mechanism for the whole system (FR-506).
 *
 * SHARED SERVICE. M4 raises SLA breaches here, M5 raises access-point
 * outages, M6 raises shift and inspection reminders. Nothing outside this
 * class writes to the `alarms` table.
 *
 * The public surface other modules should use is deliberately small:
 *
 *     $alarms->raise(AlarmType::SlaBreach, $engineer, 'TKT-2026-00417 is overdue',
 *                    message: '...', related: $ticket);
 *
 *     $alarms->raiseForRole(AlarmType::ApOffline, UserRole::Engineer,
 *                           'AP-L6-03 is not responding', related: $accessPoint);
 *
 * Everything else — scheduling, dispatch, read state — is M6's business.
 *
 * ALARMS ARE SCHEDULED, NOT IMMEDIATE. `trigger_at` in the future is normal;
 * the dispatcher sends it when due. That is what makes an inspection-round
 * reminder expressible at all. `raise()` defaults to now, so a caller who
 * wants "tell them straight away" writes nothing extra.
 *
 * @see docs/04-open-questions.md Q5
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class AlarmService
{
    /**
     * Schedule an alarm for one person.
     *
     * @param  Carbon|null  $at  when to send it; now if omitted
     */
    public function raise(
        AlarmType $type,
        User $user,
        string $title,
        ?string $message = null,
        ?Model $related = null,
        ?Carbon $at = null,
        ?Shift $shift = null,
    ): Alarm {
        $alarm = new Alarm([
            'user_id' => $user->id,
            'shift_id' => $shift?->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'trigger_at' => $at ?? now(),
        ]);

        if ($related) {
            $alarm->related()->associate($related);
        }

        $alarm->save();

        return $alarm;
    }

    /**
     * Schedule the same alarm for everybody holding a role.
     *
     * How M5 tells "the engineers" an access point is down without having to
     * decide which engineer owns it. Deactivated accounts are skipped — an
     * unread badge on an account nobody can sign into helps no one.
     *
     * @return Collection<int, Alarm>
     */
    public function raiseForRole(
        AlarmType $type,
        UserRole $role,
        string $title,
        ?string $message = null,
        ?Model $related = null,
        ?Carbon $at = null,
    ): Collection {
        return User::query()->active()->role($role)->get()
            ->map(fn (User $user) => $this->raise($type, $user, $title, $message, $related, $at));
    }

    /**
     * Send everything that has come due.
     *
     * "Sending" is stamping `sent_at`, which is what makes an alarm visible
     * and countable. Q5: in-app delivery only — mobile push needs a native app
     * or service-worker infrastructure this project does not have time for,
     * and that belongs in the report's Limitations rather than being quietly
     * omitted.
     *
     * @return int how many were sent
     */
    public function dispatchDue(): int
    {
        $due = Alarm::query()->due()->get();

        foreach ($due as $alarm) {
            $alarm->forceFill(['sent_at' => now()])->save();
        }

        return $due->count();
    }

    /** How many sent alarms this person has not opened yet. */
    public function unreadCount(User $user): int
    {
        return Alarm::query()->visibleTo($user)->unread()->count();
    }

    /**
     * @return Collection<int, Alarm>
     */
    public function recentFor(User $user, int $limit = 20): Collection
    {
        return Alarm::query()
            ->visibleTo($user)
            ->with('shift.location')
            ->latest('trigger_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Mark everything this person can see as read.
     *
     * Bulk update rather than a loop: a badge showing 40 should not be 40
     * queries, and there is nothing per-row to decide.
     */
    public function markAllRead(User $user): int
    {
        return Alarm::query()->visibleTo($user)->unread()->update(['read_at' => now()]);
    }

    public function markRead(Alarm $alarm): void
    {
        if ($alarm->isUnread()) {
            $alarm->forceFill(['read_at' => now()])->save();
        }
    }
}
