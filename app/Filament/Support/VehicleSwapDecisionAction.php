<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Apparatus;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\User;
use App\Services\Fleet\VehicleSwapDecisionException;
use App\Services\Fleet\VehicleSwapService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Contracts\View\View;

/**
 * The single Fleet decision modal, shared by the Pending vehicle swaps queue
 * and the apparatus profile. All rules live in VehicleSwapService; this only
 * collects the choice and reports the outcome.
 */
final class VehicleSwapDecisionAction
{
    public static function make(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('decideVehicleSwap')
            ->label('Decide')
            ->icon('heroicon-o-scale')
            ->color('primary')
            ->modalHeading(fn (ApparatusVehicleSwapRequest $record): string => 'Vehicle swap on '.($record->unit_designation ?: 'this unit'))
            ->modalDescription('Nothing has changed yet. The checkout is saved and its findings are on hold until you decide.')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Record decision')
            ->modalContent(fn (ApparatusVehicleSwapRequest $record): View => view('filament.fleet.vehicle-swap-decision', [
                'swap' => $record,
                'otherUnits' => app(VehicleSwapService::class)->otherUnitsHolding($record),
            ]))
            ->form(fn (ApparatusVehicleSwapRequest $record): array => self::form($record))
            ->visible(fn (ApparatusVehicleSwapRequest $record): bool => $record->isPending() && self::canDecide())
            ->action(function (array $data, ApparatusVehicleSwapRequest $record, Tables\Actions\Action $action): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    $decided = app(VehicleSwapService::class)->decide(
                        (int) $record->getKey(),
                        $actor,
                        (string) $data['decision'],
                        $data['note'] ?? null,
                        $data['demote_to'] ?? null,
                        isset($data['mapped_apparatus_id']) ? (int) $data['mapped_apparatus_id'] : null,
                    );
                } catch (VehicleSwapDecisionException $exception) {
                    Notification::make()->danger()->title('Decision not recorded')->body($exception->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(ApparatusVehicleSwapRequest::statusLabel($decided->status))
                    ->body('The held checkout findings were applied.')
                    ->send();
            });
    }

    public static function canDecide(): bool
    {
        return auth()->user()?->can('decide', ApparatusVehicleSwapRequest::class) ?? false;
    }

    private static function truckLabel(Apparatus $truck): string
    {
        return trim(($truck->vehicle_number ?: 'No vehicle ID').' · '.($truck->designation ?: $truck->name ?: $truck->getAttribute('unit_id') ?: 'no unit').' ('.($truck->getAttribute('status') ?: 'no status').')');
    }

    /** @return list<Forms\Components\Component> */
    private static function form(ApparatusVehicleSwapRequest $record): array
    {
        $noTruckRecord = $record->reported_apparatus_id === null;

        return [
            Forms\Components\Radio::make('decision')
                ->label('Decision')
                ->required()
                ->live()
                ->options([
                    VehicleSwapService::DECISION_APPROVE_SWAP => 'Approve swap: the two trucks trade unit designations',
                    VehicleSwapService::DECISION_APPROVE_REPLACE => 'Approve: the reported truck takes this unit, the old truck is demoted',
                    VehicleSwapService::DECISION_REJECT => 'Reject: no change, findings go to the assigned truck',
                ])
                ->descriptions([
                    VehicleSwapService::DECISION_APPROVE_SWAP => 'Each truck keeps its own vehicle ID, VIN, meters, defects and history; only the unit designation, station and frontline role move.',
                    VehicleSwapService::DECISION_APPROVE_REPLACE => 'The old truck loses the designation and becomes Reserve or Out of Service below.',
                    VehicleSwapService::DECISION_REJECT => 'No designation or status changes.',
                ]),
            Forms\Components\Select::make('mapped_apparatus_id')
                ->label('Which truck record is vehicle '.$record->reported_vehicle_number.'?')
                ->helperText('This vehicle ID is not on a truck record. Pick the existing record it is, or reject the report. No record is created.')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Apparatus::query()
                    ->where('id', '!=', $record->apparatus_id)
                    ->where(fn ($query) => $query->where('vehicle_number', 'like', "%{$search}%")->orWhere('designation', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                    ->orderBy('vehicle_number')->limit(50)->get()
                    ->mapWithKeys(fn (Apparatus $truck): array => [$truck->getKey() => self::truckLabel($truck)])->all())
                ->options(fn (): array => Apparatus::query()
                    ->where('id', '!=', $record->apparatus_id)->orderBy('vehicle_number')->limit(50)->get()
                    ->mapWithKeys(fn (Apparatus $truck): array => [$truck->getKey() => self::truckLabel($truck)])->all())
                ->getOptionLabelUsing(fn ($value): string => ($truck = Apparatus::query()->find($value)) ? self::truckLabel($truck) : (string) $value)
                ->visible(fn (Forms\Get $get): bool => $noTruckRecord && in_array($get('decision'), [VehicleSwapService::DECISION_APPROVE_SWAP, VehicleSwapService::DECISION_APPROVE_REPLACE], true))
                ->required(fn (Forms\Get $get): bool => $noTruckRecord && in_array($get('decision'), [VehicleSwapService::DECISION_APPROVE_SWAP, VehicleSwapService::DECISION_APPROVE_REPLACE], true)),
            Forms\Components\Select::make('demote_to')
                ->label('Old truck becomes')
                ->options([
                    VehicleSwapService::DEMOTE_RESERVE => 'Reserve',
                    VehicleSwapService::DEMOTE_OUT_OF_SERVICE => 'Out of Service',
                ])
                ->default(VehicleSwapService::DEMOTE_RESERVE)
                ->native(false)
                ->visible(fn (Forms\Get $get): bool => $get('decision') === VehicleSwapService::DECISION_APPROVE_REPLACE)
                ->required(fn (Forms\Get $get): bool => $get('decision') === VehicleSwapService::DECISION_APPROVE_REPLACE),
            Forms\Components\Textarea::make('note')
                ->label('Decision note (optional)')
                ->rows(3)
                ->maxLength(1000),
        ];
    }
}
