<?php

namespace App\Filament\Widgets;

use App\Models\Todo;
use App\Models\User;
use App\Filament\Resources\TodoResource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TodoOverviewWidget extends BaseWidget
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
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Task')
                    ->searchable()
                    ->wrap()
                    ->limit(60)
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
                            // Convert string IDs to integers
                            $ids = array_map('intval', $state);
                            $users = User::whereIn('id', $ids)->pluck('name')->toArray();
                            return !empty($users) ? implode(', ', $users) : '-';
                        }
                        return '-';
                    })
                    ->limit(30)
                    ->wrap(),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->heading('Open Tasks (' . Todo::where('status', '!=', 'completed')->count() . ' total)')
            ->paginated(false)
            ->recordUrl(
                fn (Todo $record): string => TodoResource::getUrl('view', ['record' => $record])
            );
    }
}
