<?php

declare(strict_types=1);

namespace App\Filament\Support;

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

    /** @return list<Forms\Components\Component> */
    private static function form(ApparatusVehicleSwapRequest $record): array
    {
        $nothingToTrade = app(VehicleSwapService::class)->otherUnitsHolding($record)->isEmpty();

        return [
            Forms\Components\Radio::make('decision')
                ->label('Decision')
                ->required()
                ->live()
                ->options([
                    VehicleSwapService::DECISION_APPROVE_SWAP => 'Approve swap: trade the two units\' vehicle IDs',
                    VehicleSwapService::DECISION_APPROVE_REPLACE => 'Approve: the reported vehicle takes this unit, the old vehicle is demoted',
                    VehicleSwapService::DECISION_REJECT => 'Reject: no change, findings go to the assigned vehicle',
                ])
                ->descriptions([
                    VehicleSwapService::DECISION_APPROVE_SWAP => $nothingToTrade
                        ? 'Not available: the reported vehicle is not on any other unit.'
                        : 'The other unit receives this unit\'s current vehicle ID.',
                    VehicleSwapService::DECISION_APPROVE_REPLACE => 'Mark the old vehicle Reserve or Out of Service below.',
                    VehicleSwapService::DECISION_REJECT => 'No vehicle ID or status changes.',
                ])
                ->disableOptionWhen(fn (string $value): bool => $nothingToTrade && $value === VehicleSwapService::DECISION_APPROVE_SWAP),
            Forms\Components\Select::make('demote_to')
                ->label('Old vehicle becomes')
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
