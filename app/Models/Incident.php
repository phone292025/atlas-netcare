<?php

namespace App\Models;

use App\Enums\IncidentRule;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fault the system noticed (ADR-0014). Nothing here is fillable: every
 * column is written by IncidentService, from readings and from people
 * acknowledging, never from a form.
 *
 * @see App\Services\Incidents\IncidentService
 * @see docs/modules/M8-incidents.md
 */
class Incident extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'escalation_step' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rule' => IncidentRule::class,
            'status' => IncidentStatus::class,
            'opened_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'next_escalation_at' => 'datetime',
            'escalation_step' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    /** @return BelongsTo<NetworkDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id')->withTrashed();
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return BelongsTo<Incident, $this> */
    public function groupedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'grouped_into_id');
    }

    /**
     * Every device this incident covers, its own included.
     *
     * @return BelongsToMany<NetworkDevice, $this>
     */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(NetworkDevice::class, 'incident_devices')
            ->withPivot(['joined_at', 'recovered_at'])
            ->withTimestamps()
            ->withTrashed();
    }

    /** @return HasMany<IncidentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->orderBy('happened_at')->orderBy('id');
    }

    /** @return HasMany<IncidentAlert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(IncidentAlert::class);
    }

    /** @param  Builder<Incident>  $query */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * This incident's page for a person: the same screen under each role's
     * prefix, so their sidebar stays theirs.
     */
    public function urlFor(User $user): string
    {
        return route($user->role->value.'.incidents.show', $this);
    }

    /** What it is about, for a title or a list. */
    public function subjectLabel(): string
    {
        return $this->device?->code ?? $this->agent?->name ?? 'Removed';
    }
}
