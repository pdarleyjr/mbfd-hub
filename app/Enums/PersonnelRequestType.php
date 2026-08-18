<?php

declare(strict_types=1);

namespace App\Enums;

enum PersonnelRequestType: string
{
    case Uniform = 'uniform';
    case Equipment = 'equipment';

    public function label(): string
    {
        return match ($this) {
            self::Uniform => 'Uniform Request',
            self::Equipment => 'Personnel Equipment Request',
        };
    }
}
