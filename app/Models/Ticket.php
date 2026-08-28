<?php

namespace App\Models;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One traceable fault report.
 *
 * `status`, `ticket_number`, and every timestamp in the lifecycle are absent
 * from $fillable. They belong to TicketService, which is the only thing
 * allowed to move a ticket between states — a form that could set `status`
 * would let anybody close their own ticket by editing a select.
 *
 * @see App\Services\Tickets\TicketService
 * @see docs/modules/M4-ticketing.md
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'description',
        'category',
        'priority',
        'ticketable_type',
        'ticketable_id',
        'location_id',
        'inspection_id',
        'reported_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'category' => TicketCategory::class,
            'opened_at' => 'datetime',
            'due_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function ticketable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Inspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('created_at');
    }

    /** @param  Builder<Ticket>  $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value]);
    }

    /** @param  Builder<Ticket>  $query */
    public function scopeReportedBy(Builder $query, User $user): void
    {
        $query->where('reported_by', $user->id);
    }

    /**
     * Past its SLA target and still nobody's finished with it.
     *
     * A resolved ticket that took too long has still breached — but it is no
     * longer actionable, so the alarm sweep only wants outstanding ones.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeBreaching(Builder $query): void
    {
        $query->outstanding()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    /** Whether the SLA target has passed with the ticket still open. */
    public function isBreaching(): bool
    {
        return $this->status->isOutstanding()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * How long is left, as a human phrase — "in 3 hours", "2 hours ago".
     *
     * Null when there is no target or the ticket is finished, so the queue can
     * leave the cell empty rather than printing a countdown against a ticket
     * nobody needs to act on.
     */
    public function dueInWords(): ?string
    {
        if ($this->due_at === null || ! $this->status->isOutstanding()) {
            return null;
        }

        return $this->due_at->diffForHumans();
    }

    /**
     * "APU-1042" or "AP-L6-03" — what the ticket is about, for a list.
     *
     * Falls back to the location, because a ticket can be about a place rather
     * than a machine: "no network anywhere on Level 6" belongs to nothing more
     * specific.
     */
    public function subjectLabel(): string
    {
        $asset = $this->ticketable;

        if ($asset) {
            return $asset->asset_tag ?? $asset->ap_code ?? class_basename($asset).' #'.$asset->getKey();
        }

        return $this->location?->code ?? 'Unassigned';
    }
}
