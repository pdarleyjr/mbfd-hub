<?php

namespace App\Filament\Widgets;

use App\Models\ProjectMilestone;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class UpcomingMilestonesWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    
    protected int | string | array $columnSpan = 'full';

    public function getPollingInterval(): ?string
    {
        return '60s';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProjectMilestone::query()
                    ->with('capitalProject')
                    ->where('status', '!=', 'completed')
                    ->where('due_date', '>=', now())
                    ->where('due_date', '<=', now()->addDays(30))
                    ->orderBy('due_date', 'asc')
            )
            ->heading('Upcoming Milestones')
            ->description('Milestones due in the next 30 days')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Milestone')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->wrap(),

                Tables\Columns\TextColumn::make('capitalProject.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable()
                    ->url(fn (ProjectMilestone $record): string => route('filament.admin.resources.capital-projects.view', ['record' => $record->capital_project_id]))
                    ->color('primary'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('M j, Y')
                    ->sortable()
                    ->color(fn (ProjectMilestone $record): string => match(true) {
                        $record->due_date->isPast() => 'danger',
                        $record->due_date->lte(now()->addDays(3)) => 'danger',
                        $record->due_date->lte(now()->addDays(7)) => 'warning',
                        default => 'success'
                    })
                    ->icon(fn (ProjectMilestone $record): string => match(true) {
                        $record->due_date->isPast() => 'heroicon-o-exclamation-triangle',
                        $record->due_date->lte(now()->addDays(3)) => 'heroicon-o-clock',
                        $record->due_date->lte(now()->addDays(7)) => 'heroicon-o-clock',
                        default => 'heroicon-o-calendar'
                    }),

                Tables\Columns\TextColumn::make('days_until_due')
                    ->label('Days Until Due')
                    ->state(fn (ProjectMilestone $record): string => $record->due_date->diffForHumans())
                    ->badge()
                    ->color(fn (ProjectMilestone $record): string => match(true) {
                        $record->due_date->isPast() => 'danger',
                        $record->due_date->lte(now()->addDays(3)) => 'danger',
                        $record->due_date->lte(now()->addDays(7)) => 'warning',
                        default => 'success'
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match($state) {
                        'completed' => 'success',
                        'in_progress' => 'warning',
                        'pending' => 'gray',
                        default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
            ])
            ->actions([
                Tables\Actions\Action::make('markComplete')
                    ->label('Mark Complete')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (ProjectMilestone $record) {
                        $record->update([
                            'status' => 'completed',
                        ]);
                    })
                    ->visible(fn (ProjectMilestone $record): bool => $record->status !== 'completed'),

                Tables\Actions\Action::make('view')
                    ->label('View Project')
                    ->icon('heroicon-o-eye')
                    ->url(fn (ProjectMilestone $record): string => route('filament.admin.resources.capital-projects.view', ['record' => $record->capital_project_id]))
                    ->openUrlInNewTab(),
            ])
            ->paginated(false);
    }
}
