<?php

namespace App\Filament\Widgets;

use App\Models\Todo;
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
                    ->where('is_completed', false)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Open Todos')
                    ->searchable(),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created By'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since(),
            ])
            ->heading('Recent Open Todos (' . Todo::where('is_completed', false)->count() . ' total)')
            ->paginated(false);
    }
}
