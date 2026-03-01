<?php

namespace App\Filament\Training\Widgets;

use App\Models\Todo;
use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TrainingTodoWidget extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Todo::query()
                    ->where('status', '!=', 'completed')
                    ->orderBy('priority', 'desc')
                    ->orderBy('due_date', 'asc')
                    ->orderBy('created_at', 'desc')
                    ->limit(15)
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Task')
                    ->searchable()
                    ->wrap()
                    ->limit(50)
                    ->grow(),
                    
                Tables\Columns\BadgeColumn::make('status')
                    ->formatStateUsing(fn (string $state = null): string => match ($state) {
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'delayed' => 'Delayed',
                        default => 'Pending',
                    })
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'delayed',
                    ]),
                    
                Tables\Columns\BadgeColumn::make('priority')
                    ->label('Priority')
                    ->colors([
                        'secondary' => 'low',
                        'primary' => 'medium',
                        'warning' => 'high',
                        'danger' => 'urgent',
                    ]),
                    
                Tables\Columns\TextColumn::make('assigned_to')
                    ->label('Assigned')
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return '-';
                        }
                        if (is_array($state)) {
                            $ids = array_map('intval', $state);
                            $users = User::whereIn('id', $ids)->pluck('name')->toArray();
                            return !empty($users) ? implode(', ', $users) : '-';
                        }
                        return '-';
                    })
                    ->limit(25)
                    ->wrap(),
                    
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due')
                    ->date('m/d')
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->heading('Training Tasks')
            ->paginated(false)
            ->emptyStateHeading('No Training Tasks')
            ->emptyStateDescription('All training tasks are complete or none exist yet.')
            ->emptyStateIcon('heroicon-o-clipboard-check');
    }
}
