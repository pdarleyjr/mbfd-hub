<?php

namespace App\Filament\Training\Pages;

use App\Models\Training\TrainingTodo;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $title = 'Training Dashboard';

    protected static string $routePath = '/';

    public function getSubheading(): ?string
    {
        return 'Current training work queue and division tools.';
    }

    public function getColumns(): int|string|array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTrainingTodo')
                ->label('New Training Todo')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(fn () => route('filament.training.resources.training-todos.create'))
                ->visible(fn (): bool => auth()->user()?->can('create', TrainingTodo::class) ?? false),
        ];
    }
}
