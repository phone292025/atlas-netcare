<?php

namespace App\Models;

use App\Enums\AgentContact;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A probe agent: a program inside a network that measures its devices and
 * pushes readings to Atlas (ADR-0010, ADR-0017).
 *
 * Only `name` comes from a form. The token columns are written by
 * AgentService, and the contact columns by the ingest endpoint on each
 * authenticated request. `token_hash` is hidden, so it never reaches JSON or a
 * log line that dumps the model.
 *
 * @see App\Services\Telemetry\AgentService
 */
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, SoftDeletes;

    /** Contact columns change on every batch. Their history is the readings. */
    public const AUDIT_EXCEPT = ['last_seen_at', 'clock_offset_ms', 'version', 'last_address'];

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token_issued_at' => 'datetime',
            'is_virtual' => 'boolean',
            'last_seen_at' => 'datetime',
            'clock_offset_ms' => 'integer',
        ];
    }

    /**
     * The devices this agent measures. It may report only these.
     *
     * @return HasMany<NetworkDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class);
    }

    /**
     * Every agent still in use, for a "Watched by" select.
     *
     * @return array<int, string>
     */
    public static function choices(): array
    {
        return self::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Whether it is being heard from, as of now. */
    public function contact(): AgentContact
    {
        if ($this->last_seen_at === null) {
            return AgentContact::Never;
        }

        return $this->last_seen_at->gte(now()->subMinutes((int) config('atlas.ingest.silent_after_minutes')))
            ? AgentContact::Connected
            : AgentContact::Silent;
    }

    /** Whether its clock is far enough out to make its times untrustworthy. */
    public function clockIsOff(): bool
    {
        return $this->clock_offset_ms !== null
            && abs($this->clock_offset_ms) > (int) config('atlas.ingest.clock_drift_seconds') * 1000;
    }
}
