<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Infolists;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InventorySubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventorySubmissions';

    protected static ?string $title = 'Inventory Submissions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'success',
                        'B' => 'warning',
                        'C' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->timezone('America/New_York'),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created By')
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            // These records deliberately have no standalone resource. The
            // owning Station is their canonical, read-only admin context.
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist([
                        Infolists\Components\TextEntry::make('employee_name')->label('Submitted by')->placeholder('Unknown employee'),
                        Infolists\Components\TextEntry::make('shift')->label('Shift')->placeholder('—'),
                        Infolists\Components\TextEntry::make('submitted_at')->label('Submitted')->dateTime('M j, Y g:i A')->timezone('America/New_York'),
                        Infolists\Components\TextEntry::make('notes')->label('Notes')->placeholder('No notes')->columnSpanFull(),
                        Infolists\Components\TextEntry::make('items')
                            ->label('Submitted inventory')
                            ->formatStateUsing(static fn (?array $state): string => collect($state ?? [])
                                ->map(static fn (array $item): string => collect($item)
                                    ->filter(static fn (mixed $value): bool => $value !== null && $value !== '')
                                    ->map(static fn (mixed $value, string $key): string => str($key)->headline().': '.$value)
                                    ->implode(' · '))
                                ->implode("\n"))
                            ->placeholder('No submitted inventory lines')
                            ->columnSpanFull(),
                    ]),
            ])
            ->bulkActions([]);
    }
}
