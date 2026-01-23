<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Mokhosh\FilamentKanban\Concerns\IsKanbanStatus;

enum TaskStatus: string implements HasLabel
{
    use IsKanbanStatus;

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

    public function getTitle(): string
    {
        return $this->getLabel();
    }

    public static function statuses(): \Illuminate\Support\Collection
    {
        return collect(static::cases())->map(fn($case) => [
            'id' => $case->value,
            'title' => $case->getTitle(),
        ]);
    }
}
