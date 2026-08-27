<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;

/**
 * What kind of fault a ticket describes. Used for filtering the queue and for
 * the "ticket volume by category" report in M7.
 */
enum TicketCategory: string implements HasLabel
{
    use HasValues;

    case Hardware = 'hardware';
    case Network = 'network';
    case Peripheral = 'peripheral';
    case Software = 'software';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hardware => 'Hardware',
            self::Network => 'Network',
            self::Peripheral => 'Peripheral',
            self::Software => 'Software',
            self::Other => 'Other',
        };
    }
}
