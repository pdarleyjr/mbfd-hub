<?php

namespace App\Filament\Widgets;

use App\Models\ProjectMilestone;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class UpcomingMilestonesWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    
    protected int | string | array $columnSpan = 'full';

    public function getPollingInterval(): ?string
    {
        return '60s';
    }

    public function exception(\Throwable $e, callable $stopPropagation)
    {
        Log::error('Widget Error', [
            'widget' => static::class,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        $stopPropagation();
    }

    public function table(Table $table): Table
    {
        Log::info('UpcomingMilestonesWidget: Building table');

        return $table
            ->query(
                ProjectMilestone::query()
                    ->with('capitalProject')
                    ->whereHas('capitalProject')
                    ->where('completed', false)
                    ->where('due_date', '>=', now())
                    ->where('due_date', '<=', now()->addDays(30))
                    ->orderBy('due_date', 'asc')
            )
            ->heading('Upcoming Milestones')
            ->description('Milestones due in the next 30 days')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Milestone')
                    ->default('Untitled')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->wrap(),

                Tables\Columns\TextColumn::make('capitalProject.name')
                    ->label('Project')
                    ->default('No project')
                    ->searchable()
                    ->sortable()
                    ->url(function (ProjectMilestone $record): ?string {
                        if (!$record->capital_project_id) {
                            return null;
                        }
                        try {
                            return route('filament.admin.resources.capital-projects.view', ['record' => $record->capital_project_id]);
                        } catch (\Exception $e) {
                            Log::error('UpcomingMilestonesWidget Route Error: ' . $e->getMessage());
                            return null;
                        }
                    })
                    ->color('primary'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->default(now())
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

                Tables\Columns\TextColumn::make('completion_status')
                    ->label('Status')
                    ->state(fn (ProjectMilestone $record): string => 
                        $record->completed ? 'completed' : 'pending'
                    )
                    ->badge()
                    ->color(fn (ProjectMilestone $record): string => 
                        $record->completed ? 'success' : 'gray'
                    )
                    ->formatStateUsing(fn (string $state): string => 
                        ucfirst($state)
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('markComplete')
                    ->label('Mark Complete')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (ProjectMilestone $record) {
                        $record->update([
                            'completed' => true,
                            'completed_at' => now(),
                        ]);
                    })
                    ->visible(fn (ProjectMilestone $record): bool => !$record->completed),

                Tables\Actions\Action::make('view')
                    ->label('View Project')
                    ->icon('heroicon-o-eye')
                    ->url(function (ProjectMilestone $record): ?string {
                        if (!$record->capital_project_id) {
                            return null;
                        }
                        try {
                            return route('filament.admin.resources.capital-projects.view', ['record' => $record->capital_project_id]);
                        } catch (\Exception $e) {
                            Log::error('UpcomingMilestonesWidget Route Error: ' . $e->getMessage());
                            return null;
                        }
                    })
                    ->openUrlInNewTab(),
            ])
            ->paginated(false);
    }
}
