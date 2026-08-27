<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;

/**
 * The three roles Atlas NetCare serves.
 *
 * Stored as a string column on `users`. There is no roles table: the set is
 * fixed by the case study and is never managed at runtime.
 *
 * @see docs/03-requirements.md FR-104, FR-402
 */
enum UserRole: string implements HasLabel
{
    use HasValues;

    case Admin = 'admin';
    case Engineer = 'engineer';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'System Administrator',
            self::Engineer => 'Network Engineer',
            self::Assistant => 'Student IT Assistant',
        };
    }

    /**
     * Where this role lands after signing in.
     *
     * Routes arrive with their modules; DashboardRedirect falls back to the
     * profile page for any route that is not registered yet.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin => 'admin.users.index',
            self::Engineer => 'engineer.dashboard',
            self::Assistant => 'assistant.shifts.today',
        };
    }

    /**
     * The sidebar entries this role sees, from config/atlas.php.
     *
     * @return array<int, array{label: string, route: string, icon: string}>
     */
    public function navigation(): array
    {
        return config('atlas.navigation.'.$this->value, []);
    }
}
