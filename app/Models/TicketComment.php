<?php

namespace App\Models;

use Database\Factories\TicketCommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note on a ticket (FR-308).
 *
 * @see docs/modules/M4-ticketing.md
 */
class TicketComment extends Model
{
    /** @use HasFactory<TicketCommentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
        'is_internal',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * An entry TicketService wrote itself: a status change or an assignment.
     * The timeline shows it as something that happened, not as a note.
     *
     * The service's own flag, never how the text begins: an engineer could
     * type "Assigned to ..." into a note. is_system is not fillable.
     *
     * @see App\Services\Tickets\TicketService
     */
    public function isRecordedAction(): bool
    {
        return $this->is_system;
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * Comments this person is allowed to read.
     *
     * Filtered in the QUERY, never in Blade. A view that decides visibility is
     * one misplaced @if away from showing an assistant what the engineers said
     * about their report — and the mistake would be invisible in review,
     * because the data is right there in the variable either way.
     *
     * @param  Builder<TicketComment>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAssistant()) {
            $query->where('is_internal', false);
        }
    }
}
