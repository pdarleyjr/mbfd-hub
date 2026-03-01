<?php

namespace App\Filament\Training\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Actions\Action;
use Filament\Support\Enums\MaxWidth;
use App\Filament\Training\Widgets\TrainingStatsWidget;
use App\Filament\Training\Widgets\TrainingTodoWidget;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $title = 'Training Dashboard';

    protected ?string $subheading = 'Current training work queue, external sources, and division tools.';

    protected static string $routePath = '/';

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::SevenExtraLarge;
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
                ->url(route('filament.training.resources.training-todos.create')),
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
                ->url(route('filament.training.resources.external-sources.index')),
        ];
    }

    public function getHeaderWidgets(): array
    {
        return [
            TrainingStatsWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            TrainingTodoWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'xl' => 4,
        ];
    }
}
