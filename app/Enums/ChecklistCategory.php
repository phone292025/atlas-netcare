<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;

/**
 * What part of a machine a checklist item is about.
 *
 * The inspection form groups items under these headings, so an assistant
 * working down a phone screen sees "Boot & Power" then "Display" rather than
 * twelve unsorted questions. `checklist_items.category` in the ERD is a bare
 * string; per ADR-0004 every such column gets a backed enum, which is also the
 * only way the headings can have proper labels instead of `ucfirst()`.
 *
 * No tone(). A category is not a state — it never colours anything, and giving
 * it a tone would invite somebody to badge it.
 *
 * @see docs/04-database.md — checklist_items
 * @see docs/decisions/ADR-0004-string-columns-not-mysql-enum.md
 */
enum ChecklistCategory: string implements HasLabel
{
    use HasValues;

    case Boot = 'boot';
    case Display = 'display';
    case Peripheral = 'peripheral';
    case Network = 'network';

    public function label(): string
    {
        return match ($this) {
            self::Boot => 'Boot & Power',
            self::Display => 'Display',
            self::Peripheral => 'Peripherals',
            self::Network => 'Network',
        };
    }

    /**
     * The order the sections appear in on the inspection form.
     *
     * Follows the order somebody actually checks a machine: turn it on, look
     * at the screen, try the keyboard and mouse, then test the network. An
     * assistant should never have to walk back to a machine because the form
     * asked for things out of order.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Boot => 1,
            self::Display => 2,
            self::Peripheral => 3,
            self::Network => 4,
        };
    }
}
