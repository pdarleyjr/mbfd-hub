<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnitMasterVehicleResource;

use App\Enums\DailyCheckoutRequirement;
use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\ApparatusWorkflowActions;
use App\Models\Apparatus;
use App\Models\Station;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Services\Fleet\MasterVehicleApparatusCreator;
use App\Services\Fleet\MasterVehicleApparatusReview;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The Apparatus review worklist's three decisions on a Master Vehicle Inventory record. The same
 * definitions back the list's row menu and the vehicle profile header.
 *
 *  - Create apparatus record: prefilled from the vehicle record, saved and linked in one step.
 *  - Not an apparatus: a support or staff vehicle; leaves the worklist.
 *  - Undo not an apparatus: puts it back.
 */
final class ApparatusReviewActions
{
    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function createApparatus(MountableAction $action): MountableAction
    {
        return $action
            ->authorize(fn (UnitMasterVehicle $record): bool => self::canCreate($record))
            ->visible(fn (UnitMasterVehicle $record): bool => self::needsDecision($record))
            ->label('Create apparatus record')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->modalHeading(fn (UnitMasterVehicle $record): string => 'Create apparatus record · '.self::vehicleLabel($record))
            ->modalDescription('The vehicle number, year, make, model and VIN come from this vehicle record and stay shared with it. Nothing is created until you save.')
            ->modalSubmitActionLabel('Create and link')
            ->fillForm(fn (UnitMasterVehicle $record): array => [
                'veh_number' => $record->veh_number,
                'year_make_model' => trim(implode(' ', array_filter([$record->year, $record->make, $record->model]))),
                'serial_number' => $record->serial_number,
                'type' => MasterVehicleApparatusCreator::suggestedType($record->fleet_class_description),
                'class_description' => $record->fleet_class_description,
                'status' => MasterVehicleApparatusCreator::DEFAULT_STATUS,
                'daily_checkout_requirement' => DailyCheckoutRequirement::Unknown->value,
            ])
            ->form([
                Forms\Components\Section::make('From the vehicle record')
                    ->schema([
                        Forms\Components\TextInput::make('veh_number')->label('Vehicle #')->disabled()->dehydrated(false),
                        Forms\Components\TextInput::make('year_make_model')->label('Year / make / model')->disabled()->dehydrated(false),
                        Forms\Components\TextInput::make('serial_number')->label('VIN / serial')->disabled()->dehydrated(false),
                    ])->columns(3),
                Forms\Components\Section::make('Apparatus details')
                    ->schema([
                        Forms\Components\TextInput::make('designation')
                            ->label('Designation')
                            ->placeholder('E 7, R 4, Utility 3')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('station_id')
                            ->label('Station')
                            ->options(fn (): array => Station::query()->orderBy('station_number')->get()
                                ->mapWithKeys(fn (Station $station): array => [$station->getKey() => 'Station '.$station->station_number])->all())
                            ->native(false)
                            ->placeholder('Not assigned'),
                        Forms\Components\Select::make('type')
                            ->label('Type')
                            ->options(array_combine(MasterVehicleApparatusCreator::TYPES, MasterVehicleApparatusCreator::TYPES))
                            ->native(false)
                            ->required(),
                        Forms\Components\TextInput::make('class_description')->label('Class')->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->label('Operational status')
                            ->options(ApparatusWorkflowActions::STATUS_OPTIONS)
                            ->native(false)
                            ->required(),
                        Forms\Components\Select::make('daily_checkout_requirement')
                            ->label('Daily Checkout policy')
                            ->options(DailyCheckoutRequirement::options())
                            ->native(false)
                            ->required()
                            ->helperText('Classify explicitly. Unknown keeps it on the Fleet list until someone decides.'),
                    ])->columns(3),
            ])
            ->action(function (UnitMasterVehicle $record, array $data, MountableAction $action, mixed $livewire): void {
                $user = self::actor();
                try {
                    $apparatus = app(MasterVehicleApparatusCreator::class)->create($record, $user, $data);
                } catch (ValidationException $exception) {
                    Notification::make()->title('The apparatus record was not created')->body(self::firstMessage($exception))->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Apparatus '.($apparatus->designation ?: $apparatus->name).' created and linked to vehicle '.self::vehicleLabel($record))
                    ->success()
                    ->send();
                if ($user->can('view', $apparatus) && method_exists($livewire, 'redirect')) {
                    $livewire->redirect(ApparatusResource::getUrl('view', ['record' => $apparatus]));
                }
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function notApparatus(MountableAction $action): MountableAction
    {
        return $action
            ->authorize(fn (UnitMasterVehicle $record): bool => self::canUpdate($record))
            ->visible(fn (UnitMasterVehicle $record): bool => self::needsDecision($record))
            ->label('Not an apparatus')
            ->icon('heroicon-o-no-symbol')
            ->color('gray')
            ->modalHeading(fn (UnitMasterVehicle $record): string => 'Not an apparatus · '.self::vehicleLabel($record))
            ->modalDescription('Use this for a support or staff vehicle. It leaves the Apparatus review list; you can undo this later.')
            ->modalSubmitActionLabel('Mark as not an apparatus')
            ->form([
                Forms\Components\Textarea::make('note')
                    ->label('Note (optional)')
                    ->rows(2)
                    ->maxLength(MasterVehicleApparatusReview::MAX_NOTE),
            ])
            ->action(function (UnitMasterVehicle $record, array $data, MountableAction $action): void {
                try {
                    app(MasterVehicleApparatusReview::class)->markNotApparatus($record, self::actor(), $data['note'] ?? null);
                } catch (ValidationException $exception) {
                    Notification::make()->title('Nothing was changed')->body(self::firstMessage($exception))->danger()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title('Vehicle '.self::vehicleLabel($record).' marked as not an apparatus')->success()->send();
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function undoNotApparatus(MountableAction $action): MountableAction
    {
        return $action
            ->authorize(fn (UnitMasterVehicle $record): bool => self::canUpdate($record))
            ->visible(fn (UnitMasterVehicle $record): bool => $record->isMarkedNotApparatus())
            ->label('Undo not an apparatus')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (UnitMasterVehicle $record): string => 'Undo not an apparatus · '.self::vehicleLabel($record))
            ->modalDescription('The vehicle goes back on the Apparatus review list.')
            ->action(function (UnitMasterVehicle $record): void {
                app(MasterVehicleApparatusReview::class)->undo($record, self::actor());
                Notification::make()->title('Vehicle '.self::vehicleLabel($record).' is back in apparatus review')->success()->send();
            });
    }

    /** A record that has no apparatus row and has not been marked "not an apparatus". */
    public static function needsDecision(UnitMasterVehicle $record): bool
    {
        return ! $record->isMarkedNotApparatus() && $record->apparatus === null;
    }

    private static function canCreate(UnitMasterVehicle $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('create', Apparatus::class) && $user->can('update', $record);
    }

    private static function canUpdate(UnitMasterVehicle $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', $record);
    }

    private static function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function vehicleLabel(UnitMasterVehicle $record): string
    {
        return $record->displayName() !== '' ? $record->displayName() : 'record #'.$record->getKey();
    }

    private static function firstMessage(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }
}
