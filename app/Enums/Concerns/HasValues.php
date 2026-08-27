<?php

namespace App\Enums\Concerns;

/**
 * Shared helpers for every backed enum in the system.
 *
 * Every status field in Atlas NetCare is a string column cast to a PHP enum
 * rather than a MySQL ENUM, so that adding a case is a code change instead of
 * an ALTER TABLE on a table six other people already have locally.
 *
 * @see docs/decisions/ADR-0004-string-columns-not-mysql-enum.md
 */
trait HasValues
{
    /**
     * Every backing value, for seeders, factories and validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Backing value => human label, for building select inputs.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            self::values(),
            array_map(static fn (self $case): string => $case->label(), self::cases()),
        );
    }
}
