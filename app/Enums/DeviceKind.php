<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;

/**
 * What a network device is (ADR-0016).
 *
 * Access points are the only kind with clients, a capacity and a risk score,
 * and the only kind the simulator plays. The rest are watched for being up
 * and how fast they answer, and can be the parent an access point hangs from.
 */
enum DeviceKind: string implements HasLabel
{
    use HasValues;

    case AccessPoint = 'access_point';
    case Switch = 'switch';
    case Router = 'router';
    case Server = 'server';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AccessPoint => 'Access point',
            self::Switch => 'Switch',
            self::Router => 'Router',
            self::Server => 'Server',
            self::Other => 'Other device',
        };
    }

    /**
     * The kinds the network devices screen adds. Access points have their own
     * inventory, with the fields only they need.
     *
     * @return array<string, string>
     */
    public static function nonAccessPointOptions(): array
    {
        return array_diff_key(self::options(), [self::AccessPoint->value => true]);
    }
}
