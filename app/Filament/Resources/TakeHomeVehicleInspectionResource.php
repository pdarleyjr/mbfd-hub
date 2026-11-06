<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\TakeHomeVehicleInspectionResource\Pages;
use App\Filament\Support\ArchiveFilter;
use App\Models\TakeHomeVehicleInspection;
use App\Models\User;
use App\Services\Fleet\TakeHomeInspectionArchiver;
use App\Support\Fleet\TakeHomeInspectionAccess;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fleet view of every submitted Take Home Vehicle Inspection. A single inspection
 * is shown by the member-facing report page, linked from here (with the member's
 * corrections and their history). The only thing Fleet does here is Clear a
 * handled inspection (archive it, restorable); the answers are never edited.
 */
class TakeHomeVehicleInspectionResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    private const TIMEZONE = \App\Support\HubTimezone::NAME;

    protected static ?string $model = TakeHomeVehicleInspection::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?string $navigationLabel = 'Take Home Inspections';

    protected static ?string $modelLabel = 'Take Home Inspection';

    protected static ?string $pluralModelLabel = 'Take Home Inspections';

    protected static ?int $navigationSort = 21;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestRevision'))
            ->columns([
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted (ET)')
                    ->dateTime('M j, Y g:i A', self::TIMEZONE)
                    ->sortable(),
                Tables\Columns\TextColumn::make('reference')
                    ->description(fn (TakeHomeVehicleInspection $record): ?string => self::stateNote($record))
                    ->searchable(),
                Tables\Columns\TextColumn::make('vehicle')
                    ->label('Vehicle')
                    ->getStateUsing(fn (TakeHomeVehicleInspection $record): string => $record->vehicleLabel() ?: 'Unknown vehicle')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%'.mb_strtolower(trim($search)).'%';

                        return $query->where(fn (Builder $q) => $q
                            ->whereRaw('LOWER(reference) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(inspector_name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(driver_name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(CAST(vehicle_snapshot AS TEXT)) LIKE ?', [$term]));
                    }),
                Tables\Columns\TextColumn::make('inspector_name')
                    ->label('Inspector')
                    ->searchable(),
                // Answers below are the member's latest correction when there is one (effective()).
                Tables\Columns\TextColumn::make('driver_name')
                    ->label('Driver')
                    ->getStateUsing(fn (TakeHomeVehicleInspection $record): ?string => $record->effective()->driver_name)
                    ->placeholder('Not recorded')
                    ->searchable(),
                Tables\Columns\TextColumn::make('acceptable_for_use')
                    ->label('Acceptable')
                    ->badge()
                    ->getStateUsing(fn (TakeHomeVehicleInspection $record): string => $record->effective()->acceptableLabel())
                    ->color(fn (TakeHomeVehicleInspection $record): string => TakeHomeVehicleInspection::answerColor($record->effective()->acceptable_for_use)),
                Tables\Columns\TextColumn::make('issues')
                    ->label('Issues')
                    ->getStateUsing(fn (TakeHomeVehicleInspection $record): int => self::countChecks($record->effective(), TakeHomeVehicleInspection::STATUS_ISSUE))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('not_checked')
                    ->label('Not checked / unanswered')
                    ->getStateUsing(fn (TakeHomeVehicleInspection $record): int => $record->effective()->uncheckedChecks()->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('keys_present')
                    ->label('Keys present')
                    ->getStateUsing(fn (TakeHomeVehicleInspection $record): string => $record->effective()->keysLabel()),
            ])
            ->filters([
                // Active by default: a cleared inspection is kept under Archived.
                ArchiveFilter::make(),
                Tables\Filters\Filter::make('needs_attention')
                    ->label('Needs attention')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                        ->where('acceptable_for_use', false)
                        ->orWhereNull('acceptable_for_use')
                        ->orWhere('has_issues', true)
                        ->orWhere('has_not_checked', true)
                        ->orWhere('odometer_flagged', true))),
                Tables\Filters\SelectFilter::make('acceptable_for_use')
                    ->label('Acceptable for use')
                    ->options(['yes' => 'Yes', 'no' => 'No', 'unanswered' => 'Not answered'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'yes' => $query->where('acceptable_for_use', true),
                        'no' => $query->where('acceptable_for_use', false),
                        'unanswered' => $query->whereNull('acceptable_for_use'),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('submitted')
                    ->form([
                        DatePicker::make('from')->label('Submitted from'),
                        DatePicker::make('until')->label('Submitted until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q
                            ->where('submitted_at', '>=', Carbon::parse($date, self::TIMEZONE)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q
                            ->where('submitted_at', '<=', Carbon::parse($date, self::TIMEZONE)->endOfDay()->utc())))
                    ->indicateUsing(function (array $data): array {
                        return array_values(array_filter([
                            ($data['from'] ?? null) ? 'Submitted from '.Carbon::parse($data['from'])->format('M j, Y') : null,
                            ($data['until'] ?? null) ? 'Submitted until '.Carbon::parse($data['until'])->format('M j, Y') : null,
                        ]));
                    }),
            ])
            ->recordUrl(fn (TakeHomeVehicleInspection $record): string => route('take-home-inspections.show', $record->reference))
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (TakeHomeVehicleInspection $record): string => route('take-home-inspections.show', $record->reference)),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (TakeHomeVehicleInspection $record): string => route('take-home-inspections.pdf', $record->reference)),
                Tables\Actions\Action::make('clear')
                    ->label('Clear')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn (TakeHomeVehicleInspection $record): bool => ! $record->isArchived() && TakeHomeInspectionAccess::canClear(self::actor()))
                    ->form([Textarea::make('archive_reason')->label('Note (optional)')->maxLength(TakeHomeInspectionArchiver::MAX_REASON)])
                    ->modalHeading('Clear this inspection?')
                    ->modalDescription('It leaves the active list and the dashboards and is kept under Archived. You can restore it.')
                    ->modalSubmitActionLabel('Clear')
                    ->action(function (TakeHomeVehicleInspection $record, array $data): void {
                        app(TakeHomeInspectionArchiver::class)->archive($record, self::actor() ?? abort(403), $data['archive_reason'] ?? null);
                        Notification::make()->title('Inspection cleared')->body('It is kept under Archived.')->success()->send();
                    }),
                Tables\Actions\Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (TakeHomeVehicleInspection $record): bool => $record->isArchived() && TakeHomeInspectionAccess::canClear(self::actor()))
                    ->requiresConfirmation()
                    ->modalHeading('Restore this inspection?')
                    ->modalDescription('It returns to the active list.')
                    ->action(function (TakeHomeVehicleInspection $record): void {
                        app(TakeHomeInspectionArchiver::class)->restore($record, self::actor() ?? abort(403));
                        Notification::make()->title('Inspection restored')->success()->send();
                    }),
            ])
            ->defaultSort('submitted_at', 'desc'));
    }

    private static function actor(): ?User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : null;
    }

    /** "Edited", "Cleared Nov 6, 2026", or both, under the reference. */
    private static function stateNote(TakeHomeVehicleInspection $record): ?string
    {
        $parts = array_filter([
            $record->isEdited() ? 'Edited' : null,
            $record->isArchived() ? 'Cleared '.$record->archived_at->clone()->timezone(self::TIMEZONE)->format('M j, Y') : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTakeHomeVehicleInspections::route('/'),
        ];
    }

    private static function countChecks(TakeHomeVehicleInspection $inspection, string $status): int
    {
        return collect($inspection->checks ?? [])->where('status', $status)->count();
    }
}
