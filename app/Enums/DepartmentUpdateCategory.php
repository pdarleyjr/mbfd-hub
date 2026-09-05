<?php

declare(strict_types=1);

namespace App\Enums;

enum DepartmentUpdateCategory: string
{
    case General = 'general';
    case Training = 'training';
    case Operations = 'operations';
    case It = 'it';
    case Administration = 'administration';
    case Urgent = 'urgent';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            static fn (self $category): array => [$category->value => $category->label()],
        )->all();
    }

    public function label(): string
    {
        return $this === self::It ? 'IT' : str($this->value)->title()->toString();
    }

    public function color(): string
    {
        return match ($this) {
            self::Training => 'success',
            self::Operations => 'primary',
            self::It => 'info',
            self::Administration => 'gray',
            self::Urgent => 'danger',
            self::General => 'gray',
        };
    }
}
