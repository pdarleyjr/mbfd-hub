<?php

declare(strict_types=1);

namespace App\Enums;

enum ApparatusServiceTicketPriority: string
{
    case Routine = 'routine';
    case Attention = 'attention';
    case Urgent = 'urgent';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Routine->value => 'Routine',
            self::Attention->value => 'Needs Attention',
            self::Urgent->value => 'Urgent',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::options());
    }
}
