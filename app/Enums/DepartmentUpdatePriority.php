<?php

declare(strict_types=1);

namespace App\Enums;

enum DepartmentUpdatePriority: string
{
    case Normal = 'normal';
    case Important = 'important';
    case Critical = 'critical';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            static fn (self $priority): array => [$priority->value => $priority->label()],
        )->all();
    }

    public function label(): string
    {
        return str($this->value)->title()->toString();
    }

    public function color(): string
    {
        return match ($this) {
            self::Normal => 'info',
            self::Important => 'warning',
            self::Critical => 'danger',
        };
    }
}
