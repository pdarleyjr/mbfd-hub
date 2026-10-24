<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\InspectionResource\Pages;
use App\Filament\Support\InspectionTestFilter;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InspectionResource extends Resource
{
    protected static ?int $navigationSort = 15;

    protected static ?string $navigationGroup = 'Fleet';

    use EnterpriseTable;

    use HasExportActions;

    protected static ?string $model = ApparatusInspection::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Daily Checkouts';

    protected static ?string $modelLabel = 'Inspection';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('test_excluded_at')
                    ->label('Classification')
                    ->formatStateUsing(fn (): string => 'Excluded test')
                    ->placeholder('Operational')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('completed_at')
                    ->wrap()
                    ->extraAttributes(['class' => 'hub-phone-summary'])
                    ->description(function (ApparatusInspection $record): string {
                        $apparatus = $record->getRelationValue('apparatus');
                        $apparatusName = $apparatus instanceof Apparatus ? $apparatus->getAttribute('name') : null;

                        return ($apparatusName ?: 'Apparatus unrecorded').' · '.$record->operator_name.' · Shift '.$record->shift;
                    })
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('apparatus.name')->visibleFrom('md')
                    ->label('Apparatus')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('operator_name')->visibleFrom('md')
                    ->label('Operator')
                    ->searchable(),

                Tables\Columns\TextColumn::make('rank')->visibleFrom('md')
                    ->label('Rank'),

                Tables\Columns\TextColumn::make('shift')->visibleFrom('md')
                    ->badge()
                    ->colors([
                        'primary' => 'A',
                        'warning' => 'B',
                        'success' => 'C',
                    ]),

                Tables\Columns\TextColumn::make('inspection_status')->visibleFrom('md')
                    ->label('Status')
                    ->state(fn (ApparatusInspection $record): string => InspectionWorkspace::statusPresentation($record)['label'])
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Pending review', 'Needs approval', 'Follow-up needed', 'Held: vehicle swap' => 'warning',
                        'Approved', 'Accepted' => 'success',
                        'Rejected' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('defects_count')->visibleFrom('md')
                    ->label('Issues')
                    ->counts('defects')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                InspectionTestFilter::make(),
                Filter::make('completed_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('From Date'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('completed_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('completed_at', '<=', $date),
                            );
                    }),

                SelectFilter::make('apparatus_id')
                    ->label('Apparatus')
                    ->options(fn (): array => self::apparatusOptions())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('station_id')
                    ->label('Station')
                    ->options(fn (): array => Station::query()->orderBy('station_number')->get(['id', 'name', 'station_number'])
                        ->mapWithKeys(fn (Station $station): array => [$station->id => $station->name])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $stationId): Builder => $query->whereHas('apparatus', fn (Builder $apparatus): Builder => $apparatus->where('station_id', (int) $stationId)),
                    )),

                Tables\Filters\TernaryFilter::make('needs_follow_up')
                    ->label('Needs follow-up')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $nested): Builder => $nested
                            ->where('processing_status', 'accepted_with_exception')
                            ->orWhere('review_status', 'pending_review')),
                        false: fn (Builder $query): Builder => $query
                            ->where(fn (Builder $nested): Builder => $nested->whereNull('processing_status')->orWhere('processing_status', '!=', 'accepted_with_exception'))
                            ->where(fn (Builder $nested): Builder => $nested->whereNull('review_status')->orWhere('review_status', '!=', 'pending_review')),
                    ),

                SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('completed_at', 'desc'));
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /** @return array<int, string> */
    private static function apparatusOptions(): array
    {
        return Apparatus::query()
            ->orderBy('designation')
            ->get()
            ->mapWithKeys(fn (Apparatus $apparatus): array => [
                $apparatus->getKey() => (string) ($apparatus->getAttribute('designation')
                    ?: $apparatus->getAttribute('unit_id')
                    ?: $apparatus->getAttribute('vehicle_number')
                    ?: $apparatus->getAttribute('name')
                    ?: 'Apparatus #'.$apparatus->getKey()),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInspections::route('/'),
            'view' => Pages\ViewInspection::route('/{record}'),
        ];
    }
}
