<?php

namespace App\Filament\Widgets;

use App\Models\Todo;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class TodoOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Todo::query()
                    ->where('is_completed', false)
                    ->orderBy('sort')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Task')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->iconColor('primary'),
                
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->since()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Todo $record): string => route('filament.admin.resources.todos.edit', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ])
            ->heading('Recent & Pending Todos')
            ->description('Quick view of active todo items')
            ->emptyStateHeading('No pending todos')
            ->emptyStateDescription('All todos are completed or no todos exist.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
