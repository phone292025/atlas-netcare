<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;

/**
 * The ways an incident alert reaches a person (roadmap 2.6). In-app always;
 * email when mail is set up; Telegram when the server has a bot token and
 * the person has a chat id on their profile.
 */
enum AlertChannel: string implements HasLabel
{
    use HasValues;

    case InApp = 'in_app';
    case Email = 'email';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In the app',
            self::Email => 'Email',
            self::Telegram => 'Telegram',
        };
    }
}
