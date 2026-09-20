<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $title = 'Dashboard';

    public function getSubheading(): ?string
    {
        return 'Operational overview for fleet, logistics, inventory, and active support-service tasks.';
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
            Action::make('newTodo')
                ->label('New Todo')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(fn () => route('filament.admin.resources.todos.create')),
        ];
    }
}
