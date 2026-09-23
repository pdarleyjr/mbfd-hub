<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\StationOperationsHubWidget;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\MaxWidth;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $title = 'Station Operations Command Board';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    public function getSubheading(): ?string
    {
        return 'Department-wide activity and exceptions for the active operational day.';
    }

    public function getWidgets(): array
    {
        return [
            StationOperationsHubWidget::class,
        ];
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return request()->boolean('display') ? ['class' => 'mbfd-command-display'] : [];
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('displayMode')
                ->label('Display Mode')
                ->icon('heroicon-o-tv')
                ->url(static::getUrl(['display' => 1]))
                ->visible(fn (): bool => ! request()->boolean('display')),
        ];
    }
}
