<?php

namespace App\Filament\Widgets;

use App\Models\ProjectMilestone;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Log;

class UpcomingMilestonesWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    
    protected int | string | array $columnSpan = 'full';
    
    protected static bool $isLazy = false;

    protected function getTableQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        return ProjectMilestone::query()
            ->with('capitalProject')
            ->whereHas('capitalProject')
            ->where('completed', false)
            ->where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDays(30))
            ->orderBy('due_date', 'asc');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
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
                    ->color('primary'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('M j, Y')
                    ->sortable()
                    ->color(fn (ProjectMilestone $record): string => match(true) {
                        $record->due_date && $record->due_date->isPast() => 'danger',
                        $record->due_date && $record->due_date->lte(now()->addDays(3)) => 'danger',
                        $record->due_date && $record->due_date->lte(now()->addDays(7)) => 'warning',
                        default => 'success'
                    }),

                Tables\Columns\TextColumn::make('days_until_due')
                    ->label('Days Until Due')
                    ->state(fn (ProjectMilestone $record): string => 
                        $record->due_date ? $record->due_date->diffForHumans() : 'N/A'
                    )
                    ->badge()
                    ->color(fn (ProjectMilestone $record): string => match(true) {
                        $record->due_date && $record->due_date->isPast() => 'danger',
                        $record->due_date && $record->due_date->lte(now()->addDays(3)) => 'danger',
                        $record->due_date && $record->due_date->lte(now()->addDays(7)) => 'warning',
                        default => 'success'
                    }),

                Tables\Columns\TextColumn::make('completion_status')
                    ->label('Status')
                    ->state(fn (ProjectMilestone $record): string => 
                        $record->completed ? 'Completed' : 'Pending'
                    )
                    ->badge()
                    ->color(fn (ProjectMilestone $record): string => 
                        $record->completed ? 'success' : 'gray'
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
            ])
            ->emptyStateHeading('No upcoming milestones')
            ->emptyStateDescription('No milestones are due in the next 30 days.')
            ->paginated(false);
    }
}
