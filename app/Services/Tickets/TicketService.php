<?php

namespace App\Services\Tickets;

use App\Enums\AlarmType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Opening tickets and moving them through their lifecycle.
 *
 * THE STATE MACHINE LIVES HERE AND NOWHERE ELSE. A controller may not write
 * `status`, and neither may a form — `status` is absent from Ticket::$fillable
 * for exactly that reason. Every move goes through transition(), which refuses
 * anything the machine does not allow.
 *
 * This matters more than it looks. Hiding an illegal button in Blade is not
 * the same as forbidding the transition: the button is a suggestion, the
 * service is the rule. A hand-written POST closes a ticket nobody resolved
 * unless something server-side says no.
 *
 * THE LIFECYCLE IS Q3's, ADOPTED 2026-08-28:
 *
 *   open ──▶ acknowledged ──▶ in_progress ──▶ resolved ──▶ closed
 *                                                 │
 *                                                 └──▶ reopened ──▶ in_progress
 *
 * To change it, edit TRANSITIONS below. That is the whole surface — the map is
 * one array precisely so ratifying a different lifecycle is a small edit
 * rather than an archaeology exercise.
 *
 * @see docs/02-open-questions.md Q3
 * @see docs/modules/M4-ticketing.md
 */
class TicketService
{
    /**
     * Which states each state may move to.
     *
     * Reading it aloud is the specification: an open ticket can only be
     * acknowledged; a resolved one can be closed or reopened; a closed one is
     * finished. Nothing may go backwards except through `reopened`, which
     * exists so that "this was not actually fixed" is a recorded event rather
     * than an engineer quietly flipping the status back.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'open' => ['acknowledged'],
        'acknowledged' => ['in_progress'],
        'in_progress' => ['resolved'],
        'resolved' => ['closed', 'reopened'],
        'reopened' => ['in_progress'],
        'closed' => [],
    ];

    /**
     * Raise a ticket.
     *
     * `$subject` is the lab PC or access point it is about, or null for a
     * fault that belongs to a place rather than a machine.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(array $attributes, User $reporter, ?Model $subject = null): Ticket
    {
        return DB::transaction(function () use ($attributes, $reporter, $subject) {
            $priority = $attributes['priority'] instanceof TicketPriority
                ? $attributes['priority']
                : TicketPriority::from($attributes['priority']);

            $ticket = new Ticket($attributes);

            if ($subject) {
                $ticket->ticketable()->associate($subject);

                // Denormalise the location off the asset so the queue can
                // filter by place without joining through a polymorphic
                // relation to two different tables.
                $ticket->location_id = $attributes['location_id'] ?? $subject->location_id ?? null;
            }

            $ticket->reported_by = $reporter->id;

            $openedAt = now();

            $ticket->forceFill([
                'ticket_number' => $this->reserveNumber(),
                'status' => TicketStatus::Open,
                'opened_at' => $openedAt,
                'due_at' => $openedAt->copy()->addHours($priority->slaHours()),
            ])->save();

            return $ticket;
        });
    }

    /**
     * Move a ticket to a new state, or refuse.
     *
     * @throws DomainException when the machine does not allow the move
     */
    public function transition(Ticket $ticket, TicketStatus $to, User $actor, array $extra = []): Ticket
    {
        $from = $ticket->status;

        if (! $this->canTransition($from, $to)) {
            throw new DomainException(
                "A {$from->label()} ticket cannot become {$to->label()}."
            );
        }

        return DB::transaction(function () use ($ticket, $from, $to, $actor, $extra) {
            $changes = ['status' => $to];

            // Each transition stamps its own timestamp. They are separate
            // columns rather than one "status changed at" because the report
            // needs time-to-acknowledge and time-to-resolve as distinct
            // figures, and one column cannot answer both.
            match ($to) {
                TicketStatus::Acknowledged => $changes += [
                    'acknowledged_at' => now(),
                    // Acknowledging is taking ownership. Leaving it unassigned
                    // produces the queue everyone has seen: eleven
                    // acknowledged tickets and nobody working on any of them.
                    'assigned_to' => $ticket->assigned_to ?? $actor->id,
                ],

                TicketStatus::Resolved => $changes += [
                    'resolved_at' => now(),
                    'resolution_notes' => $extra['resolution_notes'] ?? null,
                ],

                TicketStatus::Closed => $changes += ['closed_at' => now()],

                // Reopening clears the resolution. Leaving resolved_at set
                // would make the ticket count as resolved in every report
                // while sitting in the queue as open work.
                TicketStatus::Reopened => $changes += [
                    'resolved_at' => null,
                    'closed_at' => null,
                    'resolution_notes' => null,
                ],

                default => null,
            };

            $ticket->forceFill($changes)->save();

            $this->recordTransition($ticket, $from, $to, $actor);

            return $ticket->refresh();
        });
    }

    public function canTransition(TicketStatus $from, TicketStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], strict: true);
    }

    /**
     * The states this ticket could legally move to next.
     *
     * The UI renders buttons from this, so a button can never offer a move the
     * service would then refuse.
     *
     * @return list<TicketStatus>
     */
    public function nextStates(Ticket $ticket): array
    {
        return array_map(
            static fn (string $value) => TicketStatus::from($value),
            self::TRANSITIONS[$ticket->status->value] ?? [],
        );
    }

    /**
     * Tell the engineers about tickets that have blown their SLA.
     *
     * M4 owns the question "which tickets are breaching?" — that is
     * `Ticket::scopeBreaching()`, and it deliberately excludes resolved and
     * closed ones. M6 owns delivery. This method is the seam between them, and
     * it is why AlarmService is passed in rather than reached for: M4 knows
     * nothing about how an alarm is stored or sent.
     *
     * ONE ALARM PER TICKET, EVER. Without the guard this runs on a schedule
     * and re-alarms the same overdue ticket every minute until somebody
     * resolves it, which is precisely how a notification bell becomes
     * something people mute.
     *
     * @return int how many were raised
     */
    public function raiseSlaBreachAlarms(AlarmService $alarms): int
    {
        $alreadyAlarmed = Alarm::query()
            ->where('type', AlarmType::SlaBreach)
            ->where('related_type', (new Ticket)->getMorphClass())
            ->pluck('related_id')
            ->unique();

        $breaching = Ticket::query()
            ->breaching()
            ->whereNotIn('id', $alreadyAlarmed)
            ->get();

        foreach ($breaching as $ticket) {
            $alarms->raiseForRole(
                AlarmType::SlaBreach,
                UserRole::Engineer,
                "{$ticket->ticket_number} is past its target",
                "{$ticket->title} — {$ticket->priority->label()}, due {$ticket->due_at?->diffForHumans()}.",
                related: $ticket,
            );
        }

        return $breaching->count();
    }

    /**
     * Is this asset already the subject of an unfinished ticket?
     *
     * M5's collector asks this before auto-raising one, so an access point
     * that is offline for six hours produces a single ticket rather than one
     * per polling interval (FR-503).
     */
    public function hasOutstandingFor(Model $subject): bool
    {
        return Ticket::query()
            ->outstanding()
            ->where('ticketable_type', $subject->getMorphClass())
            ->where('ticketable_id', $subject->getKey())
            ->exists();
    }

    /**
     * TKT-2026-00417.
     *
     * Reserved here rather than as a database default so the format is one
     * readable line of PHP instead of vendor-specific SQL. The count is per
     * calendar year, so the number stays short for the life of the project.
     *
     * Called inside the transaction opened by open(), and the column is unique,
     * so two simultaneous requests cannot both take the same number — the
     * loser hits the unique index rather than silently duplicating.
     */
    private function reserveNumber(): string
    {
        $year = now()->year;

        $lastNumber = Ticket::withTrashed()
            ->where('ticket_number', 'like', "TKT-{$year}-%")
            ->orderByDesc('ticket_number')
            ->value('ticket_number');

        $sequence = $lastNumber
            ? ((int) substr($lastNumber, -5)) + 1
            : 1;

        return sprintf('TKT-%d-%05d', $year, $sequence);
    }

    /**
     * Leave a trail of who moved the ticket and when.
     *
     * A comment rather than a separate history table: the ticket detail page
     * already renders comments as a timeline, and one ordered list of "what
     * happened" reads better than two interleaved ones. Marked internal so it
     * does not clutter the reporter's view of their own ticket.
     */
    private function recordTransition(Ticket $ticket, TicketStatus $from, TicketStatus $to, User $actor): void
    {
        $ticket->comments()->create([
            'user_id' => $actor->id,
            'body' => "Moved this ticket from {$from->label()} to {$to->label()}.",
            'is_internal' => true,
        ]);
    }

    /**
     * Hand a ticket to a particular engineer, or take the name off it (FR-307).
     *
     * The requirement reads "acknowledge, assign, progress, resolve and close",
     * and every verb but ASSIGN was built. What existed was implicit: whoever
     * acknowledged a ticket became its owner, in one line of transition().
     * There was no way to give it to somebody else, so a ticket acknowledged
     * by the wrong person, or by somebody who then went on leave, could only
     * be moved by editing the database.
     *
     * ASSIGNMENT IS NOT A TRANSITION. It does not appear in TicketService's
     * state machine and must not: who is doing the work and how far along it
     * is are different questions, and a reassignment mid-repair should not
     * move a ticket backwards through its own lifecycle.
     *
     * Null unassigns, which is a real need — an engineer going on leave should
     * be able to put a ticket back in the pool rather than pick a colleague
     * for it.
     *
     * @throws DomainException when the ticket is finished, or the target
     *                         cannot hold work
     */
    public function assign(Ticket $ticket, ?User $engineer, User $actor): Ticket
    {
        if ($ticket->status === TicketStatus::Closed) {
            throw new DomainException('A closed ticket cannot be reassigned.');
        }

        if ($engineer !== null) {
            if (! $engineer->is_active) {
                throw new DomainException('That account is deactivated, so it cannot be given work.');
            }

            if (! ($engineer->isEngineer() || $engineer->isAdmin())) {
                throw new DomainException('Only an engineer can be assigned a ticket.');
            }
        }

        if ($ticket->assigned_to === $engineer?->id) {
            throw new DomainException($engineer
                ? 'That ticket is already assigned to them.'
                : 'That ticket is already unassigned.');
        }

        return DB::transaction(function () use ($ticket, $engineer, $actor) {
            // assigned_to is absent from $fillable — it decides who is
            // accountable for work, which is not a thing a form may set.
            $ticket->forceFill(['assigned_to' => $engineer?->id])->save();

            /*
             * An internal note, so the trail is on the ticket itself and not
             * only in the audit log. The reporter does not need to see the
             * engineers reshuffling work between themselves, which is why it
             * is internal.
             */
            TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id' => $actor->id,
                'body' => $engineer
                    ? "Assigned to {$engineer->name}."
                    : 'Unassigned and returned to the queue.',
                'is_internal' => true,
            ]);

            return $ticket->fresh(['assignee']);
        });
    }
}
