<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Collection;

enum TaskStatus: string implements HasLabel
{
    case TODO = 'todo';
    case IN_PROGRESS = 'in_progress';
    case BLOCKED = 'blocked';
    case DONE = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::TODO => 'To Do',
            self::IN_PROGRESS => 'In Progress',
            self::BLOCKED => 'Blocked',
            self::DONE => 'Done',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::TODO => 'gray',
            self::IN_PROGRESS => 'info',
            self::BLOCKED => 'danger',
            self::DONE => 'success',
        };
    }

    public static function statuses(): Collection
    {
        return collect(self::cases())->map(fn ($status) => [
            'id' => $status->value,
            'title' => $status->getLabel(),
        ]);
    }
}
