<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Resources\ApparatusResource;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily Checkout (vehicle inspection) submissions for apparatus assigned to
 * this station. Read-only: review happens on the inspection and its exceptions.
 * Visibility follows the ApparatusInspection policy (admin.fleet.view).
 *
 * The relationship joins apparatuses, so every column used in a query is
 * qualified with the inspections table.
 */
class DailyCheckoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'apparatusInspections';

    protected static ?string $title = 'Daily Checkouts';

    private const TABLE = 'apparatus_inspections';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('apparatus:id,designation,unit_id,vehicle_number')
                ->withCount('defects'))
            ->columns([
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('In progress')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(self::TABLE.'.completed_at', $direction)),
                Tables\Columns\TextColumn::make('unit')
                    ->label('Unit')
                    ->state(fn (ApparatusInspection $record): string => self::unitLabel($record))
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('operator_name')->label('Operator'),
                Tables\Columns\TextColumn::make('shift')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('inspection_status')
                    ->label('Status')
                    ->state(fn (ApparatusInspection $record): string => $record->displayStatus())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Pending review', 'Follow-up needed' => 'warning',
                        'Approved', 'Accepted' => 'success',
                        'Rejected' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('defects_count')
                    ->label('Issues')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('apparatus_id')
                    ->label('Apparatus')
                    ->options(fn (): array => $this->stationApparatusOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $apparatusId): Builder => $query->where(self::TABLE.'.apparatus_id', (int) $apparatusId),
                    )),
                Tables\Filters\TernaryFilter::make('needs_follow_up')
                    ->label('Needs follow-up')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $nested): Builder => $nested
                            ->where(self::TABLE.'.processing_status', 'accepted_with_exception')
                            ->orWhere(self::TABLE.'.review_status', 'pending_review')),
                        false: fn (Builder $query): Builder => $query
                            ->where(fn (Builder $nested): Builder => $nested
                                ->whereNull(self::TABLE.'.processing_status')
                                ->orWhere(self::TABLE.'.processing_status', '!=', 'accepted_with_exception'))
                            ->where(fn (Builder $nested): Builder => $nested
                                ->whereNull(self::TABLE.'.review_status')
                                ->orWhere(self::TABLE.'.review_status', '!=', 'pending_review')),
                    ),
                Tables\Filters\SelectFilter::make('shift')
                    ->options(['A' => 'A Shift', 'B' => 'B Shift', 'C' => 'C Shift'])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $shift): Builder => $query->where(self::TABLE.'.shift', $shift),
                    )),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc(self::TABLE.'.completed_at')->orderByDesc(self::TABLE.'.id'))
            ->emptyStateHeading('No Daily Checkouts yet')
            ->emptyStateDescription('Vehicle inspections submitted for this station\'s apparatus appear here.')
            ->actions([
                Tables\Actions\Action::make('view_results')
                    ->label('View results')
                    ->icon('heroicon-o-eye')
                    ->url(fn (ApparatusInspection $record): string => ApparatusResource::getUrl('view-inspection', [
                        'record' => $record->apparatus_id,
                        'inspection' => $record->id,
                    ])),
            ]);
    }

    /** @return array<int, string> */
    private function stationApparatusOptions(): array
    {
        $station = $this->getOwnerRecord();
        if (! $station instanceof Station) {
            return [];
        }

        return Apparatus::query()
            ->where('station_id', $station->getKey())
            ->orderBy('designation')
            ->get(['id', 'designation', 'unit_id'])
            ->mapWithKeys(fn (Apparatus $apparatus): array => [
                (int) $apparatus->getKey() => (string) ($apparatus->getAttribute('designation') ?: $apparatus->getAttribute('unit_id') ?: 'Apparatus '.$apparatus->getKey()),
            ])
            ->all();
    }

    private static function unitLabel(ApparatusInspection $record): string
    {
        $apparatus = $record->getRelationValue('apparatus');
        $designation = $apparatus instanceof Apparatus ? $apparatus->getAttribute('designation') : null;
        $unitId = $apparatus instanceof Apparatus ? $apparatus->getAttribute('unit_id') : null;

        return (string) ($designation ?: $record->getAttribute('designation_at_time') ?: $unitId ?: 'Unit unrecorded');
    }
}
