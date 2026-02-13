<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StationSupplyRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplyRequests';
    protected static ?string $title = 'Supply Requests';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('request_text')
                    ->label('Request')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_by_name')
                    ->label('Requested By')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_by_shift')
                    ->label('Shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'success',
                        'B' => 'warning',
                        'C' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'open',
                        'info' => 'ordered',
                        'success' => 'replenished',
                        'danger' => 'denied',
                    ]),
                Tables\Columns\TextColumn::make('admin_notes')
                    ->label('Admin Notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->timezone('America/New_York'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'ordered' => 'Ordered',
                        'replenished' => 'Replenished',
                        'denied' => 'Denied',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form([
                        Forms\Components\Select::make('status')
                            ->options([
                                'open' => 'Open',
                                'ordered' => 'Ordered',
                                'replenished' => 'Replenished',
                                'denied' => 'Denied',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Admin Notes'),
                    ]),
            ])
            ->bulkActions([]);
    }
}
