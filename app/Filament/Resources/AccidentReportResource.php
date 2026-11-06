<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\AccidentReportResource\Pages;
use App\Models\AccidentReport;
use App\Models\User;
use App\Support\HubTimezone;
use App\Support\Personnel\PersonnelAccess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Accident reports for every Hub administrator (and accounts granted personnel.accidents.view). */
class AccidentReportResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = AccidentReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Personnel';

    protected static ?string $navigationLabel = 'Accident Reports';

    protected static ?string $modelLabel = 'accident report';

    protected static ?string $slug = 'accident-reports';

    protected static ?string $recordRouteKeyName = 'ulid';

    protected static ?int $navigationSort = 16;

    public static function canViewAny(): bool
    {
        return PersonnelAccess::canViewAccidents(self::actor());
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

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

    public static function getNavigationBadge(): ?string
    {
        if (! self::canViewAny()) {
            return null;
        }
        $count = request()->attributes->get('accident-report-badge') ?? AccidentReport::query()->unresolved()->whereNull('alert_acknowledged_at')->count();
        request()->attributes->set('accident-report-badge', $count);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Accident reports not yet acknowledged';
    }

    public static function getEloquentQuery(): Builder
    {
        // Drafts are the member's own unfinished work and are not department records yet.
        return parent::getEloquentQuery()->scopes(['submitted'])->with(['subject', 'reporter', 'supervisor', 'vehicle.apparatus']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->defaultSort('submitted_at', 'desc')
            ->recordUrl(fn (AccidentReport $record): string => self::getUrl('view', ['record' => $record]))
            ->recordClasses(fn (AccidentReport $record): ?string => $record->resolved_at === null && $record->alert_acknowledged_at === null ? 'mbfd-accident-row-unacknowledged' : null)
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('Reference')->searchable()->weight('semibold')
                    ->description(fn (AccidentReport $record): string => $record->typeLabel()),
                Tables\Columns\TextColumn::make('subject.name')->label('Employee involved')->searchable()
                    ->description(fn (AccidentReport $record): ?string => $record->subject?->employee_id ? 'ID '.$record->subject->employee_id : null),
                Tables\Columns\TextColumn::make('vehicle.veh_number')->label('Vehicle')->placeholder('Not a vehicle report')
                    ->formatStateUsing(fn (?string $state, AccidentReport $record): ?string => $state ? trim($state.' '.($record->vehicle?->apparatus->designation ?? '')) : null),
                Tables\Columns\TextColumn::make('station_label')->label('Station / assignment')->placeholder('Not recorded')->toggleable(),
                Tables\Columns\TextColumn::make('occurred_at')->label('Accident')->dateTime('M j, Y g:i A', HubTimezone::NAME)->sortable(),
                Tables\Columns\TextColumn::make('submitted_at')->label('Submitted')->since(HubTimezone::NAME)->dateTimeTooltip('M j, Y g:i A', HubTimezone::NAME)->sortable(),
                Tables\Columns\TextColumn::make('status_label')->label('Status')->badge()
                    ->state(fn (AccidentReport $record): string => $record->statusLabel())
                    ->color(fn (AccidentReport $record): string => match (true) {
                        $record->resolved_at !== null => 'success',
                        $record->follow_up_required, $record->alert_acknowledged_at === null => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\IconColumn::make('alert_acknowledged_at')->label('Acknowledged')->boolean()
                    ->state(fn (AccidentReport $record): bool => $record->alert_acknowledged_at !== null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('report_type')->label('Report')->options(AccidentReport::TYPES),
                Tables\Filters\TernaryFilter::make('open')->label('Open accidents')->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('resolved_at'),
                        false: fn (Builder $query) => $query->whereNotNull('resolved_at'),
                        blank: fn (Builder $query) => $query,
                    ),
                Tables\Filters\Filter::make('unacknowledged')->label('Not yet acknowledged')->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('alert_acknowledged_at')),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Open')]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccidentReports::route('/'),
            'view' => Pages\ViewAccidentReport::route('/{record}'),
        ];
    }

    private static function actor(): ?User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : null;
    }
}
