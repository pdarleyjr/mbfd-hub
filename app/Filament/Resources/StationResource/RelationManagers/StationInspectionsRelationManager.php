<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Resources\StationInspectionResource;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StationInspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'stationInspections';

    protected static ?string $title = 'Station Inspections';

    protected static ?string $recordTitleAttribute = 'inspection_type';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('inspection_type')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('inspection_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('overall_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'pass', 'passed', 'satisfactory' => 'success',
                        'fail', 'failed', 'unsatisfactory' => 'danger',
                        'partial', 'needs_attention', 'warning' => 'warning',
                        'pending', 'in_progress' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('sog_mandate_acknowledged')
                    ->label('SOG')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('inspector.name')
                    ->label('Inspector')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('overall_status')
                    ->label('Status')
                    ->options([
                        'pass' => 'Pass',
                        'fail' => 'Fail',
                        'needs_attention' => 'Needs Attention',
                    ]),
            ])
            ->defaultSort('inspection_date', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist(fn (Infolist $infolist): Infolist => StationInspectionResource::infolist($infolist)),
            ]);
    }
}
