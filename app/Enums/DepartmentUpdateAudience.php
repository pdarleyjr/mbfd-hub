<?php

declare(strict_types=1);

namespace App\Enums;

enum DepartmentUpdateAudience: string
{
    case Everyone = 'everyone';
    case Officers = 'officers';
    case DriverEngineers = 'driver_engineers';
    case Firefighters = 'firefighters';
    case Administration = 'administration';
    case Selected = 'selected';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            static fn (self $audience): array => [$audience->value => $audience->label()],
        )->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::DriverEngineers => 'Driver Engineers (select members)',
            self::Selected => 'Selected Members',
            default => str($this->value)->title()->toString(),
        };
    }
}
