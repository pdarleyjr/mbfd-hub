<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Filament\Resources\DefectResource;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Services\Exports\ExportableTable;
use App\Support\Fleet\DefectActionStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Missing / damaged equipment reported against this station's apparatus,
 * usually from Daily Checkout. Resolution happens on the equipment issue so the
 * existing observer keeps status/resolved in step. Visibility follows the
 * ApparatusDefect policy (admin.fleet.view).
 *
 * The relationship joins apparatuses (which also has a status column), so
 * every filter and sort is qualified with the defects table.
 */
class EquipmentIssuesRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'apparatusDefects';

    protected static ?string $title = 'Equipment Issues';

    private const TABLE = 'apparatus_defects';

    public function table(Table $table): Table
    {
        return static::withExportActions($table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['apparatus:id,designation,unit_id', 'latestStatusEvent.actor:id,name,display_name']))
            ->columns([
                Tables\Columns\TextColumn::make('unit')
                    ->label('Unit')
                    ->state(fn (ApparatusDefect $record): string => self::unitLabel($record->getRelationValue('apparatus')))
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('compartment'),
                Tables\Columns\TextColumn::make('item')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('issue_type')
                    ->label('Issue')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->ucfirst()->toString()),
                // From action_status: "Resolved" (fixed) is not "Closed — no action required".
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->state(fn (ApparatusDefect $record): string => $record->attributedActionLabel())
                    ->color(fn (ApparatusDefect $record): string => match ($record->action_status) {
                        DefectActionStatus::RESOLVED => 'success',
                        DefectActionStatus::DISMISSED => 'gray',
                        DefectActionStatus::REPORTED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('reported_date')
                    ->label('Reported')
                    ->date()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(self::TABLE.'.reported_date', $direction)),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('unresolved')
                    ->label('Unresolved only')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(self::TABLE.'.resolved', false),
                        false: fn (Builder $query): Builder => $query->where(self::TABLE.'.resolved', true),
                    ),
                Tables\Filters\SelectFilter::make('issue_type')
                    ->label('Issue')
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $type): Builder => $query->where(self::TABLE.'.issue_type', $type),
                    )),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc(self::TABLE.'.reported_date')->orderByDesc(self::TABLE.'.id'))
            ->emptyStateHeading('No equipment issues')
            ->emptyStateDescription('Missing or damaged equipment reported for this station\'s apparatus appears here.')
            ->actions([
                Tables\Actions\Action::make('open_issue')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (ApparatusDefect $record): bool => DefectResource::canEdit($record))
                    ->url(fn (ApparatusDefect $record): string => DefectResource::getUrl('edit', ['record' => $record])),
            ]));
    }

    private static function unitLabel(mixed $apparatus): string
    {
        if (! $apparatus instanceof Apparatus) {
            return 'Unit unrecorded';
        }

        return (string) ($apparatus->getAttribute('designation') ?: $apparatus->getAttribute('unit_id') ?: 'Unit unrecorded');
    }
}
