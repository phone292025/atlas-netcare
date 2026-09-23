<?php

namespace App\Services\Tickets;

use App\Enums\AlarmType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opening tickets and moving them through their lifecycle.
 *
 * THE STATE MACHINE LIVES HERE AND NOWHERE ELSE. `status` is absent from
 * Ticket::$fillable, and every move goes through transition(), which refuses
 * anything the machine does not allow. Hiding a button in Blade is a
 * suggestion; this is the rule a hand-written POST runs into.
 *
 * The lifecycle is Q3's:
 *
 *   open ──▶ acknowledged ──▶ in_progress ──▶ resolved ──▶ closed
 *                                                 │
 *                                                 └──▶ reopened ──▶ in_progress
 *
 * To change it, edit TRANSITIONS. That is the whole surface.
 *
 * @see docs/02-open-questions.md Q3
 * @see docs/modules/M4-ticketing.md
 */
class TicketService
{
    /**
     * Which states each state may move to. Nothing goes backwards except
     * through `reopened`, so "this was not actually fixed" is a recorded event
     * rather than a status quietly flipped back.
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
     * Attempts at a ticket number before giving up. Each retry only loses to
     * a request that committed in the microseconds since the last read, so
     * four collisions in a row means something other than contention.
     */
    private const NUMBER_ATTEMPTS = 3;

    /**
     * Raise a ticket.
     *
     * `$subject` is the lab PC or access point it is about, or null for a
     * fault that belongs to a place rather than a machine.
     *
     * Retried on a unique violation: reserveNumber() reads the highest number
     * and adds one, so two simultaneous submits compute the same one. The
     * retry re-reads, and the person reporting a fault gets their ticket
     * rather than an error page. `ticket_number` is the table's only unique
     * column, so the violation is always this.
     *
     * `$isDemo` marks a ticket the SYSTEM raised about demonstration data, so
     * `atlas:purge-demo-data` can remove it. A person raising one by hand is
     * never demo data, whatever the asset.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws UniqueConstraintViolationException after NUMBER_ATTEMPTS
     */
    public function open(array $attributes, User $reporter, ?Model $subject = null, bool $isDemo = false): Ticket
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->insert($attributes, $reporter, $subject, $isDemo));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::NUMBER_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * One attempt at writing the ticket, in its own transaction so a failed
     * attempt leaves nothing behind.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes, User $reporter, ?Model $subject, bool $isDemo): Ticket
    {
        $priority = $attributes['priority'] instanceof TicketPriority
            ? $attributes['priority']
            : TicketPriority::from($attributes['priority']);

        $ticket = new Ticket($attributes);

        if ($subject) {
            $ticket->ticketable()->associate($subject);

            // Denormalised, so the queue can filter by place without joining
            // through a polymorphic relation to two tables.
            $ticket->location_id = $attributes['location_id'] ?? $subject->location_id ?? null;
        }

        $ticket->reported_by = $reporter->id;

        $openedAt = now();

        $ticket->forceFill([
            'ticket_number' => $this->reserveNumber(),
            'status' => TicketStatus::Open,
            'opened_at' => $openedAt,
            'due_at' => $openedAt->copy()->addHours($priority->slaHours()),
            'is_demo' => $isDemo,
        ])->save();

        return $ticket;
    }

    /**
     * Move a ticket to a new state, or refuse.
     *
     * @throws DomainException when the machine does not allow the move
     */
    public function transition(Ticket $ticket, TicketStatus $to, User $actor, array $extra = []): Ticket
    {
        return DB::transaction(function () use ($ticket, $to, $actor, $extra) {
            // Re-read the row under a lock, so two people moving the same
            // ticket at once cannot both pass the check below from the same
            // starting state.
            $current = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $ticket->setRawAttributes($current->getAttributes(), sync: true);

            $from = $ticket->status;

            if (! $this->canTransition($from, $to)) {
                throw new DomainException(
                    "A {$from->label()} ticket cannot become {$to->label()}."
                );
            }

            // No silent closures, enforced here as well as in the form so any
            // caller inherits the rule. M7 learns fault patterns from these
            // notes.
            if ($to === TicketStatus::Resolved && blank($extra['resolution_notes'] ?? null)) {
                throw new DomainException('Say what you did to fix it before resolving.');
            }

            $changes = ['status' => $to];

            // A timestamp per step, because the reports need time to
            // acknowledge and time to resolve as separate figures.
            match ($to) {
                TicketStatus::Acknowledged => $changes += [
                    'acknowledged_at' => now(),
                    // Acknowledging is taking ownership.
                    'assigned_to' => $ticket->assigned_to ?? $actor->id,
                ],

                TicketStatus::Resolved => $changes += [
                    'resolved_at' => now(),
                    'resolution_notes' => $extra['resolution_notes'] ?? null,
                ],

                TicketStatus::Closed => $changes += ['closed_at' => now()],

                // Reopening clears the resolution, or the ticket would count
                // as resolved in every report while sitting in the queue.
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
     * The states this ticket could legally move to next. The screen renders
     * its buttons from this, so it never offers a move the service refuses.
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
     * Tell the engineers about tickets past their SLA (FR-507).
     *
     * Which tickets are breaching is Ticket::scopeBreaching(); delivery is
     * AlarmService's, passed in so this module knows nothing about how an
     * alarm is stored or sent. One alarm per ticket, ever: this runs every
     * minute, and a bell that rings every minute gets muted.
     *
     * @return int how many were raised
     */
    public function raiseSlaBreachAlarms(AlarmService $alarms): int
    {
        $breaching = Ticket::query()
            ->breaching()
            ->whereDoesntHave('alarms', fn ($q) => $q->where('type', AlarmType::SlaBreach))
            ->get();

        foreach ($breaching as $ticket) {
            // An absolute campus time, because the message is saved: "due 3
            // hours ago" would still say so days later.
            $due = $ticket->due_at?->campus()->format('j M Y, H:i') ?? 'with no target';

            $alarms->raiseForRole(
                AlarmType::SlaBreach,
                UserRole::Engineer,
                "{$ticket->ticket_number} is past its target",
                "{$ticket->title} — {$ticket->priority->label()}, due {$due}.",
                related: $ticket,
            );
        }

        return $breaching->count();
    }

    /**
     * Is this asset already the subject of an unfinished ticket? The collector
     * asks before auto-raising one, so a six-hour outage produces one ticket
     * (FR-503).
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
     * The next number, like TKT-2026-00417, counted per calendar year.
     *
     * Not safe on its own: two callers reading before either writes compute
     * the same number. The unique index stops the duplicate and open()
     * retries; locking the whole year's tickets on every raise would be the
     * alternative. `protected` so a test can subclass it and force the
     * collision.
     */
    protected function reserveNumber(): string
    {
        return Ticket::formatNumber(Ticket::highestSequenceThisYear() + 1);
    }

    /**
     * An internal comment recording the move. Comments already render as the
     * ticket's timeline, and one ordered list reads better than two.
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
     * Hand a ticket to an engineer, or put it back in the queue with null
     * (FR-307).
     *
     * Assignment is not a transition: who is doing the work and how far along
     * it is are different questions, and a reassignment mid-repair must not
     * move the ticket through its lifecycle.
     *
     * @throws DomainException when the ticket is closed, or the target
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
            // Not fillable: it decides who is accountable for the work.
            $ticket->forceFill(['assigned_to' => $engineer?->id])->save();

            // On the ticket as well as in the audit log, and internal: the
            // reporter does not need to watch work being reshuffled.
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
