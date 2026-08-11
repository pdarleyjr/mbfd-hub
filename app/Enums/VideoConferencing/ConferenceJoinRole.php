<?php

namespace App\Enums\VideoConferencing;

enum ConferenceJoinRole: string
{
    case Self = 'self';
    case Command = '300';
    case Station1 = 'sta1';
    case Station2 = 'sta2';
    case Station3 = 'sta3';
    case Station4 = 'sta4';
    case Station6 = 'sta6';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'Myself',
            self::Command => '300 (Command)',
            self::Station1 => 'Station 1',
            self::Station2 => 'Station 2',
            self::Station3 => 'Station 3',
            self::Station4 => 'Station 4',
            self::Station6 => 'Station 6',
        };
    }

    public function fixedIdentity(): ?string
    {
        return $this === self::Self ? null : 'mbfd:'.$this->value;
    }

    public function isStation(): bool
    {
        return in_array($this, self::stationRoles(), true);
    }

    /** @return list<self> */
    public static function stationRoles(): array
    {
        return [self::Station1, self::Station2, self::Station3, self::Station4, self::Station6];
    }
}
