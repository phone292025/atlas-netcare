<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who uses Atlas NetCare.
 *
 * There is no self-registration: an administrator creates every account
 * (FR-105). Accounts are deactivated, never deleted, so the inspections and
 * tickets attributed to a person survive them leaving (NFR-06).
 *
 * @see docs/modules/M1-identity-and-audit.md
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Deliberately excludes `role`, `is_active` and `is_demo`.
     *
     * Privilege must never be assignable from request input — a mass-assigned
     * `role` is how a form becomes a privilege escalation. Those are set
     * explicitly by the admin controller in M1, through a Policy.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tp_number',
        'name',
        'email',
        'password',
        'phone',
    ];

    /**
     * The column defaults, so a model that has not been re-read from the
     * database does not report them as null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'must_change_password' => false,
        'is_demo' => false,
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Anyone holding this can read that person's roster without signing
        // in. It must never reach a JSON response or a log line by accident.
        'calendar_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',

            // Without this cast Eloquent hands back int(0) for a boolean
            // column, and a strict `=== false` comparison silently fails open.
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'is_demo' => 'boolean',

            'role' => UserRole::class,
        ];
    }

    /**
     * Accounts that can currently sign in.
     *
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeRole(Builder $query, UserRole $role): void
    {
        $query->where('role', $role);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isEngineer(): bool
    {
        return $this->role === UserRole::Engineer;
    }

    public function isAssistant(): bool
    {
        return $this->role === UserRole::Assistant;
    }

    /** @return HasMany<Inspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }
}
