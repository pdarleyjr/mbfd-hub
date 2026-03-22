<?php

namespace App\Filament\Training\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Actions\Action;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $title = 'Training Dashboard';

    protected static string $routePath = '/';

    public function getSubheading(): ?string
    {
        return 'Current training work queue, external sources, and division tools.';
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
                ->url(fn () => route('filament.training.resources.training-todos.create')),
            Action::make('openChatify')
                ->label('Open Chatify')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->url('/chatify')
                ->openUrlInNewTab(),
            Action::make('externalSources')
                ->label('External Sources')
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->url(fn () => route('filament.training.resources.external-sources.index')),
        ];
    }
}
