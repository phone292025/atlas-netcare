<?php

namespace App\Models;

use App\Enums\AlertChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One alert about one incident, to one person, at one escalation step, on
 * one channel. Inserted before it is sent: the unique key is what stops a
 * retry alerting anyone twice.
 *
 * @see App\Services\Incidents\IncidentAlerts
 */
class IncidentAlert extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => AlertChannel::class,
            'step' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
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
