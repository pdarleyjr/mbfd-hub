<?php

namespace App\Filament\Pages;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Mokhosh\FilamentKanban\Pages\KanbanBoard;

class TasksKanbanBoard extends KanbanBoard
{
    protected static string $model = Task::class;
    protected static string $statusEnum = TaskStatus::class;
    protected static ?string $navigationGroup = 'Projects';
    protected static ?string $navigationLabel = 'Tasks';
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?int $navigationSort = 2;

    protected function getEditModalFormSchema(null | int | string $recordId): array
    {
        return [
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            RichEditor::make('description')
                ->columnSpanFull(),
            Select::make('assigned_to_user_id')
                ->label('Assigned To')
                ->relationship('assignedTo', 'name')
                ->searchable()
                ->preload(),
            DateTimePicker::make('due_at')
                ->label('Due Date'),
        ];
    }
}
