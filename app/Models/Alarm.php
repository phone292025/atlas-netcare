<?php

namespace App\Models;

use App\Enums\AlarmType;
use Database\Factories\AlarmFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something one person needs to be told, at a particular time.
 *
 * @see App\Services\Shifts\AlarmService
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class Alarm extends Model
{
    /** @use HasFactory<AlarmFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'shift_id', 'type', 'title', 'message',
        'related_type', 'related_id', 'trigger_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AlarmType::class,
            'trigger_at' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return MorphTo<Model, $this> */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Alarms this person should actually see.
     *
     * SENT ONLY. An alarm scheduled for 14:00 exists in the table from the
     * moment the shift is created — showing it at 09:00 would tell somebody
     * about a reminder before it is due, and the unread count would be wrong
     * all morning.
     *
     * @param  Builder<Alarm>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)->whereNotNull('sent_at');
    }

    /** @param  Builder<Alarm>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * Due to be sent, and not sent yet.
     *
     * @param  Builder<Alarm>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('sent_at')->where('trigger_at', '<=', now());
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
