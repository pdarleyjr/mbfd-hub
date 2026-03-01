<?php

namespace App\Filament\Pages\Admin;

use App\Filament\Resources\TodoResource;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Alignment;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';
    
    protected static ?string $title = 'Dashboard';
    
    public function getSubheading(): ?string
    {
        return 'Operational overview for fleet, logistics, inventory, and active support-service tasks.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTodo')
                ->label('New Todo')
                ->icon('heroicon-o-plus')
                ->url(TodoResource::getUrl('create'))
                ->color('primary'),
                
            Action::make('askAi')
                ->label('Ask AI Assistant')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->modalHeading('AI Assistant')
                ->modalDescription('Ask questions about fleet status, inventory, defects, or request analysis.')
                ->form([
                    \Filament\Forms\Components\Textarea::make('question')
                        ->label('Your Question')
                        ->placeholder('e.g., "Summarize current out of service apparatus" or "What equipment is low on stock?"')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    // This will trigger the AI service - we'll implement this via a modal or redirect
                    // For now, redirect to the dashboard which has the AI widget
                })
                ->modalSubmitActionLabel('Ask'),
                
            Action::make('filterDashboard')
                ->label('Filter Dashboard')
                ->icon('heroicon-o-funnel')
                ->color('gray')
                ->modalHeading('Filter Dashboard')
                ->modalDescription('Filter data across all dashboard widgets.')
                ->form([
                    \Filament\Forms\Components\Select::make('status')
                        ->label('Apparatus Status')
                        ->options([
                            'all' => 'All Statuses',
                            'in_service' => 'In Service',
                            'out_of_service' => 'Out of Service',
                            'maintenance' => 'In Maintenance',
                        ])
                        ->default('all'),
                        
                    \Filament\Forms\Components\Select::make('station')
                        ->label('Station')
                        ->options(\App\Models\Station::all()->pluck('name', 'id'))
                        ->multiple()
                        ->searchable(),
                        
                    \Filament\Forms\Components\DatePicker::make('date_from')
                        ->label('Date From'),
                        
                    \Filament\Forms\Components\DatePicker::make('date_to')
                        ->label('Date To'),
                ])
                ->action(function (array $data) {
                    // Store filters in session for widgets to use
                    session(['admin_dashboard_filters' => $data]);
                    $this->dispatch('dashboard-filters-updated', filters: $data);
                })
                ->modalSubmitActionLabel('Apply Filters'),
        ];
    }

    public function getMaxContentWidth(): ?string
    {
        return '7xl';
    }
}
