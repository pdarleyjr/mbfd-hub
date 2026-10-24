<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource;

use App\Enums\ApparatusPmServiceType;
use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Filament\Resources\UnitMasterVehicleResource;
use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Fleet\ApparatusMasterVehicleLink;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The Fleet manager's and mechanics' everyday actions on one apparatus. The
 * same definitions back the list's row menu and the profile page header, so
 * a workflow behaves identically wherever it is started.
 */
final class ApparatusWorkflowActions
{
    /** Operational statuses Fleet may set (ApparatusServiceTicketWorkflowService validates the same list). */
    public const STATUS_OPTIONS = [
        'In Service' => 'In Service',
        'Available' => 'Available',
        'Reserve' => 'Reserve',
        'Maintenance' => 'Maintenance',
        'Out of Service' => 'Out of Service',
    ];

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function changeStatus(MountableAction $action): MountableAction
    {
        return $action
            ->authorize(fn (Apparatus $record): bool => self::canManageService($record))
            ->label('Change status')
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->modalHeading(fn (Apparatus $record): string => 'Change status · '.self::unitLabel($record))
            ->modalSubmitActionLabel('Save status')
            ->form([
                Forms\Components\Select::make('status')
                    ->label('Operational status')
                    ->options(self::STATUS_OPTIONS)
                    ->default(fn (Apparatus $record): ?string => $record->status)
                    ->native(false)
                    ->required(),
            ])
            ->action(function (Apparatus $record, array $data): void {
                app(ApparatusServiceTicketWorkflowService::class)
                    ->changeOperationalStatus($record, self::actor(), (string) $data['status']);

                Notification::make()
                    ->title(self::unitLabel($record).' is now '.$data['status'])
                    ->success()
                    ->send();
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function scheduleService(MountableAction $action): MountableAction
    {
        return $action
            ->authorize(fn (Apparatus $record): bool => self::canManageService($record))
            ->label('Schedule service')
            ->icon('heroicon-o-calendar-days')
            ->color('primary')
            ->modalHeading(fn (Apparatus $record): string => 'Schedule service · '.self::unitLabel($record))
            ->modalSubmitActionLabel('Create scheduled ticket')
            ->form([
                Forms\Components\Hidden::make('client_submission_id')
                    ->default(fn (): string => (string) Str::uuid())
                    ->required(),
                Forms\Components\Select::make('category')
                    ->options(ApparatusServiceTicketCategory::options())
                    ->default('repair_mechanical')
                    ->required(),
                Forms\Components\Select::make('priority')
                    ->options(ApparatusServiceTicketPriority::options())
                    ->default('routine')
                    ->required(),
                Forms\Components\TextInput::make('service_type')
                    ->label('Service Type')
                    ->placeholder('Example: PMA, pump repair, aerial inspection')
                    ->maxLength(255)
                    ->required(),
                Forms\Components\TextInput::make('title')
                    ->label('Service Summary')
                    ->minLength(5)
                    ->maxLength(255)
                    ->required(),
                Forms\Components\Textarea::make('description')
                    ->label('Service Scope / Observed Issue')
                    ->minLength(10)
                    ->maxLength(10000)
                    ->rows(4)
                    ->required(),
                Forms\Components\DateTimePicker::make('scheduled_for')->required(),
                Forms\Components\TextInput::make('scheduled_location')
                    ->label('Service Location')
                    ->helperText('Use the established Fleet/location name. No default is assumed.')
                    ->maxLength(255)
                    ->required(),
                Forms\Components\DateTimePicker::make('expected_return_at')
                    ->label('Expected Return')
                    ->afterOrEqual('scheduled_for'),
                Forms\Components\Select::make('assigned_to_user_id')
                    ->label('Assigned To')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Forms\Components\TextInput::make('assigned_vendor')->maxLength(255),
                Forms\Components\Textarea::make('public_note')
                    ->label('Public Update')
                    ->helperText('Visible at the origin station and during vehicle checkout.')
                    ->rows(2)
                    ->required(),
                Forms\Components\Textarea::make('internal_note')
                    ->label('Internal Note')
                    ->helperText('Visible only to authorized Fleet and administrators.')
                    ->rows(2),
            ])
            ->action(function (Apparatus $record, array $data): void {
                $data['status'] = 'scheduled';
                $result = app(ApparatusServiceTicketWorkflowService::class)
                    ->createFleetTicket($record, self::actor(), $data);

                Notification::make()
                    ->title($result->created ? 'Service scheduled' : 'Service ticket already exists')
                    ->success()
                    ->body("{$result->ticket->ticket_number}: {$result->ticket->title}")
                    ->send();
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function logPm(MountableAction $action): MountableAction
    {
        return $action
            ->authorize(fn (Apparatus $record): bool => self::canManageService($record))
            ->label('Log PM')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color(fn (Apparatus $record): string => $record->isPmDue() ? 'danger' : 'gray')
            ->modalHeading(fn (Apparatus $record): string => 'Log completed PM · '.self::unitLabel($record))
            ->modalSubmitActionLabel('Log PM')
            ->form([
                Forms\Components\Hidden::make('client_submission_id')
                    ->default(fn (): string => (string) Str::uuid())
                    ->required(),
                Forms\Components\Placeholder::make('current_readings')
                    ->label('Current meter readings')
                    ->content(function (Apparatus $record): string {
                        $hours = $record->current_engine_hours ?? 'N/A';
                        $miles = number_format($record->current_miles ?? 0);
                        $health = $record->getPmHealthStatus();

                        return "Engine hours: {$hours} | Miles: {$miles} | Hours since PM: {$health['hours_since_pm']} of {$health['interval_hours']}";
                    }),
                Forms\Components\DatePicker::make('service_date')
                    ->label('Service Date')
                    ->default(now())
                    ->required(),
                Forms\Components\Select::make('service_type')
                    ->label('Service Type')
                    ->options(ApparatusPmServiceType::options())
                    ->required(),
                Forms\Components\TextInput::make('service_engine_hours')
                    ->label('Engine Hours at Service')
                    ->numeric()
                    ->step(0.1)
                    ->default(fn (Apparatus $record) => $record->current_engine_hours)
                    ->helperText('Defaults to the current reading. Adjust if the service was at a different reading.'),
                Forms\Components\TextInput::make('service_mileage')
                    ->label('Mileage at Service')
                    ->numeric()
                    ->default(fn (Apparatus $record) => $record->current_miles)
                    ->helperText('Defaults to the current reading.'),
                Forms\Components\Textarea::make('service_notes')
                    ->label('Service Notes')
                    ->placeholder('Work performed, parts, vendor'),
            ])
            ->action(function (Apparatus $record, array $data): void {
                $result = app(ApparatusServiceTicketWorkflowService::class)
                    ->logPmService($record, self::actor(), $data);
                $serviceHours = $result->ticket->service_engine_hours ?? $record->current_engine_hours ?? 0;

                Notification::make()
                    ->title($result->created ? 'PM logged' : 'PM already logged')
                    ->success()
                    ->body(self::unitLabel($record).": {$result->ticket->ticket_number} recorded. Next PM due at "
                        .round((float) $serviceHours + ($record->pm_interval_hours ?? 300), 1).' engine hours.')
                    ->send();
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function dailyCheckout(MountableAction $action): MountableAction
    {
        return $action
            ->label('Daily Checkout')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->visible(fn (Apparatus $record): bool => $record->isDailyCheckoutRequired() && filled($record->slug))
            ->url(fn (Apparatus $record): string => url("/daily/vehicle-inspections/{$record->slug}"))
            ->openUrlInNewTab();
    }

    /**
     * Opens the vehicle's master record: documents, accidents, take-home
     * inspections and field history live there, not on the apparatus.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function masterRecord(MountableAction $action): MountableAction
    {
        return $action
            ->label('Vehicle record')
            ->icon('heroicon-o-folder-open')
            ->color('gray')
            ->visible(function (Apparatus $record): bool {
                $master = $record->masterVehicle;

                return $master instanceof UnitMasterVehicle && (auth()->user()?->can('view', $master) ?? false);
            })
            ->url(fn (Apparatus $record): ?string => $record->masterVehicle instanceof UnitMasterVehicle
                ? UnitMasterVehicleResource::getUrl('view', ['record' => $record->masterVehicle])
                : null);
    }

    /**
     * Fleet's decision for a unit the automatic match could not make (no number, a VIN
     * conflict, two master rows with one number). Linking makes the master record win:
     * the apparatus takes its vehicle #, make, model, year and VIN, and every change is
     * written to the vehicle's history.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function linkVehicleRecord(MountableAction $action): MountableAction
    {
        return $action
            ->label(fn (Apparatus $record): string => $record->unit_master_vehicle_id === null ? 'Link vehicle record' : 'Change vehicle record')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->authorize(fn (Apparatus $record): bool => self::canLink($record))
            ->modalHeading(fn (Apparatus $record): string => 'Link vehicle record · '.self::unitLabel($record))
            ->modalDescription(fn (Apparatus $record): string => trim(($record->master_link_note ? $record->master_link_note.' ' : '')
                .'The vehicle you choose becomes the record for this unit. Its vehicle #, make, model, year and VIN replace the ones on this apparatus, and the change is kept in the vehicle history.'))
            ->modalSubmitActionLabel('Link vehicle')
            ->form([
                Forms\Components\Select::make('unit_master_vehicle_id')
                    ->label('Master Vehicle Inventory vehicle')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search, Apparatus $record): array => self::linkableVehicles($record, $search))
                    ->options(fn (Apparatus $record): array => self::linkableVehicles($record, (string) $record->vehicle_number))
                    ->getOptionLabelUsing(fn ($value): ?string => UnitMasterVehicle::query()->find($value)?->displayName()),
            ])
            ->action(function (Apparatus $record, array $data): void {
                abort_unless(self::canLink($record), 403);
                $master = UnitMasterVehicle::query()->findOrFail((int) $data['unit_master_vehicle_id']);
                $holder = $master->apparatus;
                if ($holder instanceof Apparatus && ! $holder->is($record)) {
                    Notification::make()->danger()
                        ->title('That vehicle is already linked')
                        ->body('Vehicle #'.$master->veh_number.' belongs to '.self::unitLabel($holder).'. Change that unit first.')
                        ->send();

                    return;
                }

                $changes = app(ApparatusMasterVehicleLink::class)->link($record, $master, self::actor());
                $record->refresh();

                Notification::make()->success()
                    ->title(self::unitLabel($record).' is linked to vehicle #'.$master->veh_number)
                    ->body($changes === [] ? 'The records already matched.' : count($changes).' '.(count($changes) === 1 ? 'field was' : 'fields were').' aligned and recorded in the vehicle history.')
                    ->send();
            });
    }

    /** @return array<int, string> master vehicles not linked to another apparatus, matching the search */
    private static function linkableVehicles(Apparatus $record, string $search): array
    {
        $term = '%'.trim($search).'%';

        return UnitMasterVehicle::query()
            ->whereDoesntHave('apparatus', fn ($query) => $query->whereKeyNot($record->getKey()))
            ->when(trim($search) !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('veh_number', 'like', $term)
                ->orWhere('make', 'like', $term)
                ->orWhere('model', 'like', $term)
                ->orWhere('serial_number', 'like', $term)))
            ->orderBy('veh_number')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (UnitMasterVehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])
            ->all();
    }

    /** Linking changes both records, so it needs edit rights on both. */
    private static function canLink(Apparatus $record): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('update', $record)
            && $user->can('update', UnitMasterVehicle::class);
    }

    public static function unitLabel(Apparatus $record): string
    {
        return (string) ($record->designation ?: $record->name ?: $record->unit_id ?: 'Apparatus #'.$record->getKey());
    }

    private static function canManageService(Apparatus $record): bool
    {
        return auth()->user()?->can('manageService', $record) ?? false;
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
