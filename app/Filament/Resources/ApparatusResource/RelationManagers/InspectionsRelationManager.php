<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Filament\Support\InspectionTestFilter;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Services\Exports\ExportableTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InspectionsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'inspections';

    protected static ?string $title = 'Inspections';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus ? $ownerRecord->inspections()->operational()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return static::withExportActions($table
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
                        'Pending review', 'Needs approval', 'Follow-up needed', 'Held: vehicle swap' => 'warning',
                        'Approved', 'Accepted' => 'success',
                        'Rejected' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('defects_count')
                    ->label('Issues')
                    ->counts('defects')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                InspectionTestFilter::make(),
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
            ->defaultSort('completed_at', 'desc'));
    }

    /** Same-tab navigation to the one canonical inspection page; its Back control returns here. */
    private static function resultsUrl(ApparatusInspection $record): string
    {
        return InspectionWorkspace::url($record);
    }
}
