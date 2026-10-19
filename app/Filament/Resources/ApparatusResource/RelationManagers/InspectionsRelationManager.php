<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    protected static ?string $title = 'Inspections';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus ? $ownerRecord->inspections()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('completed_at')
            ->recordUrl(fn (ApparatusInspection $record): string => self::resultsUrl($record))
            ->columns([
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('current_designation')
                    ->label('Designation')
                    ->getStateUsing(fn (ApparatusInspection $record) => $record->apparatus?->designation ?? $record->designation_at_time ?? '—')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Vehicle #')
                    ->getStateUsing(fn (ApparatusInspection $record) => $record->vehicle_number ?? $record->apparatus?->vehicle_number ?? '—'),

                Tables\Columns\TextColumn::make('operator_name')
                    ->label('Operator')
                    ->searchable(),

                Tables\Columns\TextColumn::make('rank')
                    ->label('Rank'),

                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'primary',
                        'B' => 'warning',
                        'C' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('inspection_status')
                    ->label('Status')
                    ->getStateUsing(fn (ApparatusInspection $record): string => $record->displayStatus())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Pending review', 'Follow-up needed' => 'warning',
                        'Approved', 'Accepted' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('defects_count')
                    ->label('Issues')
                    ->counts('defects')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('view_results')
                    ->label('View Results')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn (ApparatusInspection $record): string => self::resultsUrl($record)),
            ])
            ->defaultSort('completed_at', 'desc');
    }

    /** Same-tab navigation: ContextualBack on the results page returns here. */
    private static function resultsUrl(ApparatusInspection $record): string
    {
        return route('filament.admin.resources.apparatuses.view-inspection', [
            'record' => $record->apparatus_id,
            'inspection' => $record->id,
        ]);
    }
}
