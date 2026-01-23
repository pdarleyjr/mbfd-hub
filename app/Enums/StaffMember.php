<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StaffMember: string implements HasLabel
{
    case MIGUEL_ANCHIA = 'Miguel Anchia';
    case RICHARD_QUINTELA = 'Richard Quintela';
    case PETER_DARLEY = 'Peter Darley';
    case GERALD_DE_YOUNG = 'Gerald De Young';
    case OTHER = 'Other';

    public function getLabel(): string
    {
        return $this->value;
    }

    public static function getOptions(): array
    {
        return [
            self::MIGUEL_ANCHIA->value => self::MIGUEL_ANCHIA->getLabel(),
            self::RICHARD_QUINTELA->value => self::RICHARD_QUINTELA->getLabel(),
            self::PETER_DARLEY->value => self::PETER_DARLEY->getLabel(),
            self::GERALD_DE_YOUNG->value => self::GERALD_DE_YOUNG->getLabel(),
            self::OTHER->value => self::OTHER->getLabel(),
        ];
    }
}
