<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusChangeoutResource\Pages;

use App\Filament\Concerns\RunsFleetChangeoutActions;
use App\Filament\Resources\ApparatusChangeoutResource;
use App\Filament\Resources\ApparatusResource\ApparatusWorkflowActions;
use App\Filament\Support\ApparatusChangeoutWording;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusOperationalAlert;
use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Resources\Pages\ViewRecord;

/** One changeout: the trucks, the equipment lines, the move in readable words, and Fleet's decision. */
class ViewApparatusChangeout extends ViewRecord
{
    use RunsFleetChangeoutActions;

    protected static string $resource = ApparatusChangeoutResource::class;

    public function getTitle(): string
    {
        $changeout = $this->changeout();

        return $changeout->unit_designation.': truck '.($changeout->from_vehicle_number ?: 'not recorded').' to truck '.$changeout->to_vehicle_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->verifyLineAction(),
            Action::make('rejectChangeout')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->canDecide())
                ->modalHeading('Reject this changeout')
                ->modalDescription('No truck changes. The record stays, with your reason on it.')
                ->modalSubmitActionLabel('Reject')
                ->form([
                    Forms\Components\Textarea::make('note')->label('Reason')->required()->rows(3)->maxLength(1000),
                ])
                ->action(fn (array $data) => $this->runFleetAction(
                    fn (User $actor) => $this->service()->decide((int) $this->changeout()->getKey(), $actor, 'reject', (string) $data['note']),
                    'Changeout rejected',
                    'No truck changed.',
                )),
            Action::make('cancelChangeout')
                ->label('Cancel')
                ->icon('heroicon-o-minus-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->canDecide())
                ->modalHeading('Cancel this changeout')
                ->modalDescription('Use this when the crew withdrew it or it is no longer needed. No truck changes.')
                ->modalSubmitActionLabel('Cancel changeout')
                ->modalCancelActionLabel('Keep it')
                ->form([
                    Forms\Components\Textarea::make('note')->label('Note (optional)')->rows(3)->maxLength(1000),
                ])
                ->action(fn (array $data) => $this->runFleetAction(
                    fn (User $actor) => $this->service()->cancel((int) $this->changeout()->getKey(), $actor, $data['note'] ?? null),
                    'Changeout cancelled',
                    'No truck changed.',
                )),
        ];
    }

    private function approveAction(): Action
    {
        return Action::make('approveChangeout')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => $this->canDecide())
            ->modalHeading(fn (): string => 'Approve moving '.$this->changeout()->unit_designation)
            ->modalDescription(fn (): string => $this->changeout()->to_apparatus_id === null
                ? 'The unit moves when you approve. Tickets and defects stay open on the old truck.'
                : 'The unit moves to truck '.$this->changeout()->to_vehicle_number.' when you approve. Tickets and defects stay open on the old truck.')
            ->modalSubmitActionLabel('Approve and move the unit')
            ->fillForm(fn (): array => [
                'old_truck_status' => $this->changeout()->alert?->old_truck_status_after,
            ])
            ->form([
                Forms\Components\Select::make('mapped_apparatus_id')
                    ->label(fn (): string => 'Which truck record is vehicle '.$this->changeout()->to_vehicle_number.'?')
                    ->helperText('This number is not on a truck record. Pick the record it is, or reject. No record is created.')
                    ->options(fn (): array => ApparatusWorkflowActions::truckOptions((int) $this->changeout()->from_apparatus_id, sameType: false))
                    ->searchable()
                    ->visible(fn (): bool => $this->changeout()->to_apparatus_id === null)
                    ->required(fn (): bool => $this->changeout()->to_apparatus_id === null),
                Forms\Components\Select::make('old_truck_status')
                    ->label('Old truck becomes')
                    ->helperText('If you leave this empty, the old truck takes the status the new truck had.')
                    ->options(array_combine(ApparatusOperationalAlert::OLD_TRUCK_STATUSES, ApparatusOperationalAlert::OLD_TRUCK_STATUSES))
                    ->native(false),
                Forms\Components\Textarea::make('note')->label('Decision note (optional)')->rows(3)->maxLength(1000),
            ])
            ->action(fn (array $data) => $this->runFleetAction(
                fn (User $actor) => $this->service()->decide(
                    (int) $this->changeout()->getKey(),
                    $actor,
                    'approve',
                    $data['note'] ?? null,
                    filled($data['mapped_apparatus_id'] ?? null) ? (int) $data['mapped_apparatus_id'] : null,
                    filled($data['old_truck_status'] ?? null) ? (string) $data['old_truck_status'] : null,
                ),
                'Changeout approved',
                'Tickets and defects stayed on the old truck.',
            ));
    }

    private function verifyLineAction(): Action
    {
        return Action::make('verifyLine')
            ->label('Verify line')
            ->icon('heroicon-o-shield-check')
            ->color('primary')
            ->visible(fn (): bool => $this->changeout()->isCompleted() && $this->openLines() !== [] && $this->canVerify())
            ->modalHeading('Verify an equipment line')
            ->modalDescription('This records your name against the line. Settling the last open line closes the handoff.')
            ->modalSubmitActionLabel('Verify line')
            ->form([
                Forms\Components\Select::make('line_id')
                    ->label('Line')
                    ->options(fn (): array => array_map(
                        static fn (ApparatusChangeoutItem $item): string => ApparatusChangeoutWording::lineLabel($item).' ('.($item->custody_status === ApparatusChangeoutItem::CUSTODY_NEEDS_REVIEW ? 'needs review' : 'controlled item').')',
                        $this->openLines(),
                    ))
                    ->native(false)
                    ->live()
                    ->required(),
                Forms\Components\Textarea::make('note')
                    ->label(fn (Get $get): string => $this->lineNeedsNote($get('line_id')) ? 'What you found' : 'Note (optional)')
                    ->required(fn (Get $get): bool => $this->lineNeedsNote($get('line_id')))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(fn (array $data) => $this->runFleetAction(
                fn (User $actor) => $this->service()->verifyLine((int) $this->changeout()->getKey(), (int) $data['line_id'], $actor, $data['note'] ?? null),
                'Line verified',
            ));
    }

    /** A line marked for review is settled by what someone found, so the record has to say it. */
    private function lineNeedsNote(mixed $lineId): bool
    {
        $line = $this->openLines()[(int) $lineId] ?? null;

        return $line?->custody_status === ApparatusChangeoutItem::CUSTODY_NEEDS_REVIEW;
    }

    /** @return array<int, ApparatusChangeoutItem> open lines by id, the ones this person can settle */
    private function openLines(): array
    {
        $fleet = auth()->user()?->canManageFleetSwaps() ?? false;

        return $this->changeout()->items()
            ->whereIn('custody_status', $fleet ? ApparatusChangeoutItem::OPEN_CUSTODY : [ApparatusChangeoutItem::CUSTODY_PENDING_VERIFICATION])
            ->orderBy('id')
            ->get()
            ->keyBy(fn (ApparatusChangeoutItem $item): int => (int) $item->getKey())
            ->all();
    }

    private function canDecide(): bool
    {
        return $this->changeout()->isPendingFleet() && (auth()->user()?->can('decide', ApparatusChangeout::class) ?? false);
    }

    private function canVerify(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->service()->canVerify($user, $this->changeout());
    }

    private function service(): ApparatusChangeoutService
    {
        return app(ApparatusChangeoutService::class);
    }

    private function changeout(): ApparatusChangeout
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ApparatusChangeout, 404);

        return $record;
    }
}
