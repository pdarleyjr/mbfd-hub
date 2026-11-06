<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource;

use App\Enums\ApparatusPmServiceType;
use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Filament\Resources\ApparatusOperationalAlertResource;
use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\UnitMasterVehicleResource;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusOperationalAlert;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use App\Services\Fleet\ApparatusChangeoutPresenter;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Services\Fleet\ApparatusMasterVehicleLink;
use App\Services\Fleet\ApparatusOperationalAlertService;
use App\Services\Fleet\ChangeoutConflictException;
use App\Support\Fleet\FrontlineUnits;
use App\Support\Fleet\UnitIdentity;
use App\Support\HubNavigation;
use App\Support\HubTimezone;
use Closure;
use Filament\Actions;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Throwable;

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
                    ->default(fn (Apparatus $record): ?string => $record->getAttribute('status'))
                    ->native(false)
                    ->helperText(fn (Apparatus $record): ?string => self::frontlineStatusHint($record))
                    ->rule(fn (Apparatus $record): Closure => self::frontlineStatusRule($record))
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
            // Same window: a new tab escapes an installed app. Daily's Back returns to this admin page.
            ->url(fn (Apparatus $record): string => HubNavigation::withReturnTo(
                "/daily/vehicle-inspections/{$record->slug}",
                Livewire::isLivewireRequest() ? Livewire::originalUrl() : request()->fullUrl(),
            ));
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
                    ->searchPrompt('Type a vehicle #, make, model or VIN')
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

                try {
                    $changes = app(ApparatusMasterVehicleLink::class)->link($record, $master, self::actor());
                } catch (UniqueConstraintViolationException) {
                    Notification::make()->danger()
                        ->title('That vehicle was just linked to another unit')
                        ->body('Refresh the page and choose again.')
                        ->send();

                    return;
                }
                $record->refresh();

                Notification::make()->success()
                    ->title(self::unitLabel($record).' is linked to vehicle #'.$master->veh_number)
                    ->body($changes === [] ? 'The records already matched.' : count($changes).' '.(count($changes) === 1 ? 'field was' : 'fields were').' aligned and recorded in the vehicle history.')
                    ->send();
            });
    }

    /**
     * Preschedules a truck swap: one upcoming_swap alert, no approvals. Nothing about the truck changes until the crew
     * does the changeout, and the truck named here is a suggestion they can overrule. Row
     * visibility only reads what the table already loaded, so the Apparatus list gains no query per row.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function scheduleChangeout(MountableAction $action): MountableAction
    {
        return $action
            ->label('Schedule changeout')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->authorize(fn (): bool => self::canManageChangeouts())
            ->visible(fn (Apparatus $record): bool => filled($record->slug))
            ->modalHeading(fn (Apparatus $record): string => 'Schedule changeout · '.self::unitLabel($record))
            ->modalDescription('Crews see this on the station page. Nothing about the truck changes until the changeout is done.')
            ->modalSubmitActionLabel('Schedule changeout')
            ->form([
                Forms\Components\Hidden::make('dedupe_key')->default(fn (): string => 'schedule-changeout:'.Str::uuid()),
                Forms\Components\TextInput::make('title')
                    ->label('Title')
                    ->default(fn (Apparatus $record): string => ApparatusOperationalAlertResource::defaultTitle($record, ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP))
                    ->required()
                    ->maxLength(180),
                Forms\Components\TextInput::make('reason')->label('Reason')->required()->maxLength(180),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\DatePicker::make('scheduled_date')
                        ->label('Date')
                        ->minDate(fn (): string => HubTimezone::today()->format('Y-m-d'))
                        ->required(),
                    ApparatusOperationalAlertResource::windowField(),
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label('Exact time (optional, Eastern)')
                        ->seconds(false),
                ]),
                Forms\Components\Select::make('planned_replacement_apparatus_id')
                    ->label('Replacement truck (optional)')
                    ->helperText('The crew sees this truck first, and can still choose a different one.')
                    ->options(fn (Apparatus $record): array => self::truckOptions((int) $record->getKey()))
                    ->searchable(),
                Forms\Components\Textarea::make('message')
                    ->label('Message')
                    ->default(fn (Apparatus $record): string => self::unitLabel($record).' is scheduled to move to a different truck. Keep using truck '.($record->vehicle_number ?: 'as assigned').' until the changeout is done.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
                Forms\Components\Textarea::make('instructions')->label('Instructions for the crew (optional)')->rows(2)->maxLength(1000),
                Forms\Components\Toggle::make('send_notification')->label('Notify members')->helperText('Sends once, in the members\' alert bell.'),
            ])
            ->action(function (Apparatus $record, array $data): void {
                $saved = self::saveAlert($record, [...ApparatusOperationalAlertResource::forService($data), 'category' => ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP]);
                if ($saved instanceof ApparatusOperationalAlert) {
                    Notification::make()->success()
                        ->title('Changeout scheduled')
                        ->body(self::unitLabel($record).': '.$saved->scheduleLabel())
                        ->send();
                }
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function addOperationalAlert(MountableAction $action): MountableAction
    {
        return $action
            ->label('Add operational alert')
            ->icon('heroicon-o-megaphone')
            ->color('gray')
            ->authorize(fn (): bool => self::canManageChangeouts())
            ->visible(fn (Apparatus $record): bool => filled($record->slug))
            ->modalHeading(fn (Apparatus $record): string => 'Add operational alert · '.self::unitLabel($record))
            ->modalDescription('Crews see this on the station page until it ends or Fleet closes it.')
            ->modalSubmitActionLabel('Add alert')
            ->form([
                Forms\Components\Hidden::make('dedupe_key')->default(fn (): string => 'add-alert:'.Str::uuid()),
                Forms\Components\Select::make('category')
                    ->label('Kind of alert')
                    ->options(collect(ApparatusOperationalAlert::CATEGORIES)->except(ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP)->all())
                    ->default(ApparatusOperationalAlert::CATEGORY_GENERAL)
                    ->native(false)
                    ->required(),
                Forms\Components\TextInput::make('title')
                    ->label('Title')
                    ->default(fn (Apparatus $record): string => ApparatusOperationalAlertResource::defaultTitle($record, ApparatusOperationalAlert::CATEGORY_GENERAL))
                    ->required()
                    ->maxLength(180),
                Forms\Components\Textarea::make('message')->label('Message')->required()->rows(3)->maxLength(1000),
                Forms\Components\TextInput::make('reason')->label('Reason (optional)')->maxLength(180),
                Forms\Components\Textarea::make('instructions')->label('Instructions for the crew (optional)')->rows(2)->maxLength(1000),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\DatePicker::make('scheduled_date')->label('Date (optional)'),
                    ApparatusOperationalAlertResource::windowField(),
                    Forms\Components\DatePicker::make('ends_on')->label('Stop showing after (optional)'),
                ]),
                Forms\Components\Select::make('apparatus_service_ticket_id')
                    ->label('Service ticket (optional)')
                    ->options(fn (Apparatus $record): array => ApparatusOperationalAlertResource::ticketOptions((int) $record->getKey()))
                    ->searchable(),
                Forms\Components\Toggle::make('send_notification')->label('Notify members')->helperText('Sends once, in the members\' alert bell.'),
            ])
            ->action(function (Apparatus $record, array $data): void {
                if (self::saveAlert($record, $data) instanceof ApparatusOperationalAlert) {
                    Notification::make()->success()->title('Alert added')->body(self::unitLabel($record))->send();
                }
            });
    }

    /**
     * The three truck actions every apparatus page leads with.
     *
     * @return list<Actions\Action>
     */
    public static function truckActions(): array
    {
        return [
            self::swapTruck(Actions\Action::make('swapTruck')),
            self::takeOutOfService(Actions\Action::make('takeOutOfService')),
            self::returnToService(Actions\Action::make('returnToService')),
        ];
    }

    /**
     * Puts a different truck on this unit, now, through the one changeout service the crews use. No approval: the truck
     * picked here takes the unit and goes In Service, and this truck becomes a spare and goes Out of Service.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function swapTruck(MountableAction $action): MountableAction
    {
        return $action
            ->label('Swap truck')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->authorize(fn (): bool => self::canManageChangeouts())
            ->visible(fn (Apparatus $record): bool => filled($record->slug))
            ->modalHeading(fn (Apparatus $record): string => 'Which truck will run as '.self::unitLabel($record).'?')
            ->modalDescription(fn (Apparatus $record): string => self::swapConsequence($record))
            ->modalSubmitActionLabel('Swap truck')
            ->form(fn (Apparatus $record): array => self::replacementFields($record, false))
            ->action(function (Apparatus $record, array $data, mixed $livewire): void {
                self::swap($record, $data, $livewire);
            });
    }

    /**
     * A front-line unit is never out of service; its truck can be. For a truck carrying one, this asks which truck takes
     * the unit and does the swap. If no truck is available, an admin can say so: the truck goes Out of Service, the unit
     * stays on it, and Fleet is told the same way as a swap. Any other truck simply goes Out of Service.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function takeOutOfService(MountableAction $action): MountableAction
    {
        return $action
            ->label('Mark this truck out of service')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->authorize(fn (Apparatus $record): bool => self::canManageService($record))
            ->visible(fn (Apparatus $record): bool => $record->getAttribute('status') !== ApparatusChangeout::OLD_TRUCK_STATUS)
            ->modalHeading(fn (Apparatus $record): string => FrontlineUnits::isFrontline($record)
                ? 'Which truck will run as '.self::unitLabel($record).'?'
                : 'Mark truck '.($record->vehicle_number ?: self::unitLabel($record)).' out of service')
            ->modalDescription(fn (Apparatus $record): string => FrontlineUnits::isFrontline($record)
                ? self::unitLabel($record).' is a front-line unit, so it stays in service. Its truck can go out of service: '.self::swapConsequence($record)
                : 'The truck will show Out of Service on the Apparatus list and the station boards.')
            ->modalSubmitActionLabel(fn (Apparatus $record): string => FrontlineUnits::isFrontline($record) ? 'Save' : 'Mark out of service')
            ->form(fn (Apparatus $record): array => FrontlineUnits::isFrontline($record) ? self::replacementFields($record, true) : [])
            ->action(function (Apparatus $record, array $data, mixed $livewire): void {
                if (! FrontlineUnits::isFrontline($record)) {
                    self::setStatus($record, ApparatusChangeout::OLD_TRUCK_STATUS, $livewire);

                    return;
                }
                if (! (bool) ($data['no_replacement'] ?? false)) {
                    self::swap($record, $data, $livewire);

                    return;
                }
                try {
                    $changeout = app(ApparatusChangeoutService::class)->recordWithoutReplacement($record, self::actor(), (string) ($data['reason'] ?? ''));
                } catch (ValidationException|AuthorizationException $exception) {
                    self::refused($exception);
                }
                $record->refresh();
                self::refreshStatusField($livewire);
                Notification::make()->warning()
                    ->title($changeout->headline())
                    ->body('Fleet and the admin dashboard were told. Use Swap truck as soon as a truck is available.')
                    ->persistent()
                    ->send();
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function returnToService(MountableAction $action): MountableAction
    {
        return $action
            ->label('Mark this truck back in service')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->authorize(fn (Apparatus $record): bool => self::canManageService($record))
            ->visible(fn (Apparatus $record): bool => $record->getAttribute('status') !== 'In Service')
            ->requiresConfirmation()
            ->modalHeading(fn (Apparatus $record): string => 'Put truck '.($record->vehicle_number ?: self::unitLabel($record)).' back in service?')
            ->modalDescription(fn (Apparatus $record): string => 'It will show In Service as '.self::unitLabel($record).'. To put it on a different unit, use Swap truck on that unit.')
            ->modalSubmitActionLabel('Mark in service')
            ->action(function (Apparatus $record, mixed $livewire): void {
                self::setStatus($record, 'In Service', $livewire);
            });
    }

    /**
     * The rare admin correction of the unit fields themselves: a typo, a renamed unit. It never moves a unit to another
     * truck (that is Swap truck), and the identity guard on the model refuses a vehicle number, a unit another truck
     * carries, or fields that disagree.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function changeUnitAssignment(MountableAction $action): MountableAction
    {
        return $action
            ->label('Change unit assignment')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->authorize(fn (Apparatus $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalHeading(fn (Apparatus $record): string => 'Change unit assignment · '.($record->vehicle_number ? 'truck '.$record->vehicle_number : self::unitLabel($record)))
            ->modalDescription('Only for correcting this record. To put a different truck on a unit, close this and use Swap truck. A unit ID names the unit, like E1, never a vehicle number.')
            ->modalSubmitActionLabel('Save unit assignment')
            ->fillForm(fn (Apparatus $record): array => [
                'unit_id' => $record->getAttribute('unit_id'),
                'designation' => $record->designation,
                'name' => $record->name,
                'slug' => $record->slug,
            ])
            ->form([
                Forms\Components\TextInput::make('unit_id')
                    ->label('Unit ID')
                    ->helperText('Like E1, R22 or FB6. The designation, name and link name below are filled in from it.')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state, Apparatus $record): void {
                        $unit = trim((string) $state);
                        if ($unit === '') {
                            return;
                        }
                        $set('designation', UnitIdentity::designationFor($unit));
                        $set('name', UnitIdentity::nameFor($unit, $record->type));
                        $set('slug', UnitIdentity::slugFor($unit));
                    }),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('designation')->label('Designation')->placeholder('E 1')->maxLength(255),
                    Forms\Components\TextInput::make('name')->label('Name')->placeholder('ENGINE E1')->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->label('Link name (slug)')
                        ->placeholder('e1')
                        ->helperText('Used in Daily Checkout links and alerts for this unit.')
                        ->required()
                        ->alphaDash()
                        ->maxLength(255),
                ]),
                Forms\Components\Checkbox::make('confirmed')
                    ->label('I am correcting this record, not moving the unit to a different truck.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirm that this is a correction. To move a unit, use Swap truck.']),
            ])
            ->action(function (Apparatus $record, array $data, MountableAction $action): void {
                $fields = collect(UnitIdentity::FIELDS)
                    ->mapWithKeys(static fn (string $field): array => [$field => filled($data[$field] ?? null) ? trim((string) $data[$field]) : null])
                    ->all();
                try {
                    DB::transaction(static fn (): bool => $record->fill($fields)->save());
                } catch (ValidationException $exception) {
                    $record->refresh();
                    self::refused($exception);
                } catch (UniqueConstraintViolationException) {
                    $record->refresh();
                    Notification::make()->danger()->title('The unit assignment was not saved')
                        ->body('Another truck record already uses that unit ID or link name.')->persistent()->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Unit assignment saved')->body('Truck '.($record->vehicle_number ?: 'record #'.$record->getKey()).' is listed as '.self::unitLabel($record).'.')->send();
            });
    }

    /** Explains the steer on the status choices: a front-line unit's truck is taken out of service with a swap. */
    public static function frontlineStatusHint(?Apparatus $record): ?string
    {
        $unit = $record instanceof Apparatus ? FrontlineUnits::carriedBy($record) : null;

        return $unit === null ? null
            : "{$unit} is a front-line unit and must have a truck In Service. To take this truck off {$unit}, use Mark this truck out of service or Swap truck at the top of the page.";
    }

    /** Server-side: a status other than In Service on a front-line unit's truck goes through the swap flow instead. */
    public static function frontlineStatusRule(?Apparatus $record): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $unit = $record instanceof Apparatus && $record->exists ? FrontlineUnits::carriedBy($record) : null;
            if ($unit === null || $value === 'In Service' || $value === $record->getOriginal('status')) {
                return;
            }
            $fail("{$unit} is a front-line unit, so it cannot be {$value}. Use Mark this truck out of service to pick the truck that will run as {$unit}, or to record that no truck is available.");
        };
    }

    /**
     * The truck choice for a swap, and for a front-line truck going out of service, the explicit "no truck" escape hatch.
     *
     * @return list<Forms\Components\Component>
     */
    private static function replacementFields(Apparatus $record, bool $withEscapeHatch): array
    {
        $unit = self::unitLabel($record);
        $showChoice = fn (Forms\Get $get): bool => ! $withEscapeHatch || ! (bool) $get('no_replacement');

        return array_values(array_filter([
            Forms\Components\Select::make('to_apparatus_id')
                ->label('Truck from the fleet list')
                ->options(fn (): array => self::swapOptions($record))
                ->searchable()
                ->visible($showChoice)
                ->required(fn (Forms\Get $get): bool => $showChoice($get) && blank($get('to_vehicle_number')))
                ->validationMessages(['required' => "Pick the truck that will run as {$unit}, or type its number."]),
            Forms\Components\TextInput::make('to_vehicle_number')
                ->label('Or type the vehicle number on the truck')
                ->helperText('For a truck that is not on the list. A record is made for it and Fleet is asked to check it.')
                ->maxLength(64)
                ->visible($showChoice),
            Forms\Components\Textarea::make('note')
                ->label('Note for Fleet (optional)')
                ->rows(2)
                ->maxLength(1000)
                ->visible($showChoice),
            $withEscapeHatch ? Forms\Components\Toggle::make('no_replacement')
                ->label("No truck is available. Keep {$unit} on this truck and mark the truck Out of Service anyway.")
                ->helperText("Fleet and the admin dashboard are told, the same as a swap. {$unit} shows as having no truck in service until you swap a truck onto it.")
                ->visible(fn (): bool => self::canManageChangeouts())
                ->live() : null,
            $withEscapeHatch ? Forms\Components\Textarea::make('reason')
                ->label('Why no other truck can run as '.$unit)
                ->rows(2)
                ->maxLength(1000)
                ->visible(fn (Forms\Get $get): bool => (bool) $get('no_replacement'))
                ->required(fn (Forms\Get $get): bool => (bool) $get('no_replacement')) : null,
        ]));
    }

    /**
     * Spare trucks first, then trucks carrying another unit (that unit trades onto this truck). Same type first; any truck
     * when the type has no other trucks.
     *
     * @return array<string, array<int, string>>
     */
    private static function swapOptions(Apparatus $record): array
    {
        $choices = app(ApparatusChangeoutPlanner::class)->choices($record, null);
        $label = static fn (array $row): string => trim($row['vehicle_number'].' · '.$row['label'].' ('.($row['status'] ?: 'no status').')');
        $spares = collect($choices['candidates'])->mapWithKeys(static fn (array $row): array => [$row['apparatus_id'] => $label($row)])->all();
        $others = collect($choices['other_vehicles'])->mapWithKeys(static fn (array $row): array => [$row['apparatus_id'] => $label($row).', trades units'])->all();
        if ($spares === [] && $others === []) {
            return ['Any truck' => self::truckOptions((int) $record->getKey(), false)];
        }

        return array_filter(['Spare trucks' => $spares, 'Trucks carrying another unit' => $others]);
    }

    private static function swapConsequence(Apparatus $record): string
    {
        $number = $record->vehicle_number ? 'Truck '.$record->vehicle_number : 'This truck';

        return 'The truck you pick takes over '.self::unitLabel($record)." and goes In Service. {$number} becomes a spare and is marked Out of Service. No approval is needed, and Fleet is told.";
    }

    /** @param array<string, mixed> $data */
    private static function swap(Apparatus $record, array $data, mixed $livewire): void
    {
        $toId = filled($data['to_apparatus_id'] ?? null) ? (int) $data['to_apparatus_id'] : null;
        $toNumber = filled($data['to_vehicle_number'] ?? null) ? trim((string) $data['to_vehicle_number']) : null;
        try {
            $result = app(ApparatusChangeoutService::class)->submit($record, self::actor(), [
                'client_submission_id' => (string) Str::uuid(),
                'expected_vehicle_number' => $record->vehicle_number,
                'expected_unit_slug' => (string) $record->slug,
                'to_apparatus_id' => $toNumber === null ? $toId : null,
                'to_vehicle_number' => $toNumber,
                'note' => $data['note'] ?? null,
            ]);
        } catch (ValidationException|AuthorizationException|ChangeoutConflictException $exception) {
            self::refused($exception);
        }
        $changeout = $result['changeout'];
        Notification::make()->success()
            ->title($changeout->headline())
            ->body(ApparatusChangeoutPresenter::messageFor($changeout))
            ->send();
        // The unit now lives on the other truck: follow it there. A list row just refreshes.
        if ($livewire instanceof ViewRecord || $livewire instanceof EditRecord) {
            $livewire->redirect(ApparatusResource::getUrl('view', ['record' => $changeout->to_apparatus_id]));
        }
    }

    private static function setStatus(Apparatus $record, string $status, mixed $livewire): void
    {
        app(ApparatusServiceTicketWorkflowService::class)->changeOperationalStatus($record, self::actor(), $status);
        $record->refresh();
        self::refreshStatusField($livewire);
        Notification::make()->success()->title('Truck '.($record->vehicle_number ?: self::unitLabel($record)).' is now '.$status)->send();
    }

    /** An edit form open on the same truck must not save the old status back over the new one. */
    private static function refreshStatusField(mixed $livewire): void
    {
        if ($livewire instanceof EditRecord) {
            $livewire->refreshFormData(['status']);
        }
    }

    /** Says why in plain words and keeps the modal open, so the admin can correct the choice. */
    private static function refused(Throwable $exception): never
    {
        $body = $exception instanceof ValidationException
            ? implode(' ', collect($exception->errors())->flatten()->unique()->all())
            : ($exception->getMessage() ?: 'You do not have permission to do this.');
        Notification::make()->danger()->title('Nothing was changed')->body($body)->persistent()->send();

        throw new Halt;
    }

    /** @param array<string, mixed> $data */
    private static function saveAlert(Apparatus $record, array $data): ?ApparatusOperationalAlert
    {
        try {
            return app(ApparatusOperationalAlertService::class)->create([...$data, 'apparatus_id' => $record->getKey()], self::actor());
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('The alert was not saved')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'Check the form and try again.'))->persistent()->send();
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('You do not have permission to do this.')->send();
        }

        return null;
    }

    /**
     * Trucks with a vehicle number, for a truck choice in a form. Called only when a modal opens.
     *
     * @return array<int, string>
     */
    public static function truckOptions(?int $excludeId = null, bool $sameType = true): array
    {
        $type = $excludeId !== null && $sameType ? Apparatus::query()->whereKey($excludeId)->value('type') : null;

        return Apparatus::query()
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->when(filled($type), fn ($query) => $query->where('type', $type))
            ->whereNotNull('vehicle_number')
            ->orderBy('vehicle_number')
            ->limit(300)
            ->get(['id', 'vehicle_number', 'designation', 'name', 'unit_id', 'status'])
            ->mapWithKeys(fn (Apparatus $truck): array => [$truck->getKey() => trim($truck->vehicle_number.' · '.self::unitLabel($truck).' ('.($truck->getAttribute('status') ?: 'no status').')')])
            ->all();
    }

    /**
     * One answer per HTTP request: canManageFleetSwaps() runs permission queries, and the Apparatus list evaluates
     * this for every action on every row.
     */
    private static function canManageChangeouts(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }
        $key = 'fleet.can-manage-changeouts.'.$user->getKey();
        $attributes = request()->attributes;
        if (! $attributes->has($key)) {
            $attributes->set($key, $user->canManageFleetSwaps());
        }

        return (bool) $attributes->get($key);
    }

    /** @return array<int, string> master vehicles not linked to another apparatus, matching the search */
    private static function linkableVehicles(Apparatus $record, string $search): array
    {
        $term = '%'.mb_strtolower(trim($search)).'%';

        return UnitMasterVehicle::query()
            ->whereDoesntHave('apparatus', fn ($query) => $query->whereKeyNot($record->getKey()))
            ->when(trim($search) !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereRaw('LOWER(veh_number) LIKE ?', [$term])
                ->orWhereRaw('LOWER(make) LIKE ?', [$term])
                ->orWhereRaw('LOWER(model) LIKE ?', [$term])
                ->orWhereRaw('LOWER(serial_number) LIKE ?', [$term])))
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
        return (string) ($record->designation ?: $record->name ?: $record->getAttribute('unit_id') ?: 'Apparatus #'.$record->getKey());
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
