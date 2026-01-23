<?php

namespace App\Filament\Pages;

use App\Enums\StaffMember;
use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Mokhosh\FilamentKanban\Pages\KanbanBoard;

class TasksKanbanBoard extends KanbanBoard
{
    protected static string $model = Task::class;
    protected static string $statusEnum = TaskStatus::class;
    protected static string $recordTitleAttribute = 'title';
    protected static string $recordStatusAttribute = 'status';
    protected static ?string $navigationGroup = 'Projects';
    protected static ?string $navigationLabel = 'Tasks';
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?int $navigationSort = 2;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                RichEditor::make('description')
                    ->columnSpanFull(),
                TagsInput::make('assigned_to')
                    ->label('Assigned To')
                    ->suggestions(
                        collect(StaffMember::getOptions())
                            ->except('Other')
                            ->values()
                            ->toArray()
                    )
                    ->placeholder('Select or type staff names')
                    ->helperText('Select from predefined staff or type custom names'),
                TextInput::make('created_by')
                    ->label('Created By')
                    ->default(auth()->user()?->name)
                    ->placeholder('Enter your name'),
                DateTimePicker::make('due_at')
                    ->label('Due Date'),
            ]);
    }
}
