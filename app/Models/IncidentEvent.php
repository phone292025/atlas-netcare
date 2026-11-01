<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an incident's timeline, written by IncidentService. */
class IncidentEvent extends Model
{
    /** @var list<string> */
    protected $fillable = ['type', 'user_id', 'message', 'happened_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    /** The dot beside it on the timeline: opening and escalating are bad news. */
    public function tone(): ?string
    {
        return match ($this->type) {
            'opened', 'escalated' => 'urgent',
            'resolved' => 'healthy',
            default => null,
        };
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
