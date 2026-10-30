<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EmployeeMessagesWidget;
use App\Filament\Widgets\EquipmentChangeRequestsWidget;
use App\Filament\Widgets\StationOperationsHubWidget;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\MaxWidth;
use Livewire\Attributes\Locked;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Logistics Dashboard';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $title = 'Logistics Dashboard';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    public function getSubheading(): ?string
    {
        return 'Department-wide checkouts, equipment issues and station activity for the active operational day.';
    }

    /** Captured on the first request: later Livewire requests to this page do not carry ?display=1. */
    #[Locked]
    public bool $displayMode = false;

    public function mount(): void
    {
        $this->displayMode = request()->boolean('display');
    }

    public function getWidgets(): array
    {
        // Employee messages are person-specific: never on the display-mode (wall) screen.
        $display = $this->displayMode || request()->boolean('display');

        return [
            StationOperationsHubWidget::class,
            ...($display ? [] : [EmployeeMessagesWidget::class]),
            // Names members and is decided by Fleet reviewers: never on the wall display.
            ...($display ? [] : [EquipmentChangeRequestsWidget::class]),
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
