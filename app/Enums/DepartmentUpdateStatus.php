<?php

declare(strict_types=1);

namespace App\Enums;

enum DepartmentUpdateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            static fn (self $status): array => [$status->value => $status->label()],
        )->all();
    }

    public function label(): string
    {
        return str($this->value)->title()->toString();
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Archived => 'warning',
        };
    }
}
