<?php

namespace App\Enums\Contracts;

/**
 * Every enum in Atlas NetCare carries its own human-readable label, so a form
 * option and a table badge can never disagree about what a value is called.
 *
 * Implementing this makes the requirement a compile-time contract rather than a
 * runtime surprise: HasValues::options() calls label(), and without the
 * interface a new enum that forgets it would only fail when a page rendered.
 *
 * @see docs/decisions/ADR-0004-string-columns-not-mysql-enum.md
 */
interface HasLabel
{
    public function label(): string;
}
