<?php

namespace App\Filament\Resources\UnitMasterVehicleResource\Pages;

use App\Filament\Resources\UnitMasterVehicleResource;
use App\Models\Employee;
use App\Models\TakeHomeVehicleInspection;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Services\Fleet\UnitMasterVehicleChangeRecorder;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/**
 * The individual vehicle profile: master details, the latest take-home
 * inspection, history, and the corrections Fleet still has to decide.
 */
class ViewUnitMasterVehicle extends ViewRecord
{
    protected static string $resource = UnitMasterVehicleResource::class;

    private const HISTORY_LIMIT = 50;

    /** @var array<int|string, string> */
    private array $employeeNames = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof UnitMasterVehicle && $record->displayName() !== ''
            ? $record->displayName()
            : 'Vehicle profile';
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->getRecord())
            ->schema([
                Section::make('Vehicle')
                    ->schema(array_map(fn (TextEntry $entry): TextEntry => $entry->placeholder('—'), [
                        TextEntry::make('veh_number')->label('Vehicle #'),
                        TextEntry::make('ymm')->label('Year / make / model')
                            ->getStateUsing(fn (UnitMasterVehicle $record): ?string => trim(implode(' ', array_filter([$record->year, $record->make, $record->model]))) ?: null),
                        TextEntry::make('color'),
                        TextEntry::make('tag_number')->label('License plate / tag'),
                        TextEntry::make('serial_number')->label('VIN / serial'),
                        TextEntry::make('category')->label('Category')->badge()
                            ->getStateUsing(fn (UnitMasterVehicle $record): string => $record->categoryLabel())
                            ->color(fn (UnitMasterVehicle $record): string => $record->isNonroad() ? 'info' : 'gray'),
                        TextEntry::make('age')->label('Age')
                            ->getStateUsing(fn (UnitMasterVehicle $record): ?string => ($age = $record->ageInYears()) === null
                                ? null
                                : $age.' '.($age === 1 ? 'year' : 'years')),
                        TextEntry::make('fleet_status_code')->label('FY27 status')
                            ->helperText('City FY27 status letter; meaning pending Fleet confirmation'),
                        TextEntry::make('fleet_class')->label('Fleet class')
                            ->getStateUsing(fn (UnitMasterVehicle $record): ?string => implode(' - ', array_filter([$record->fleet_class_code, $record->fleet_class_description])) ?: null),
                        TextEntry::make('dept_code')->label('Department'),
                        TextEntry::make('section'),
                        TextEntry::make('employee_or_vehicle_name')->label('Assigned name / vehicle name'),
                        TextEntry::make('assignment'),
                        TextEntry::make('driver')->label('Current driver')
                            ->getStateUsing(fn (UnitMasterVehicle $record): ?string => UnitMasterVehicleResource::employeeLabel($record->currentDriver)),
                        TextEntry::make('location'),
                        TextEntry::make('record_source')->label('Record source'),
                        TextEntry::make('needs_fleet_review')->label('Fleet review')->badge()
                            ->getStateUsing(fn (UnitMasterVehicle $record): string => $record->needs_fleet_review ? 'Needs review' : 'Reviewed')
                            ->color(fn (string $state): string => $state === 'Needs review' ? 'warning' : 'gray'),
                    ]))
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->compact(),

                Section::make('Last Take Home Inspection')
                    ->schema([
                        ViewEntry::make('last_inspection')
                            ->hiddenLabel()
                            ->view('filament.fleet.vehicle-last-inspection')
                            ->viewData(fn (UnitMasterVehicle $record): array => ['inspection' => $this->lastInspection($record)]),
                    ]),

                Section::make('Inspection history')
                    ->schema([
                        ViewEntry::make('inspection_history')
                            ->hiddenLabel()
                            ->view('filament.fleet.vehicle-inspection-history')
                            ->viewData(fn (UnitMasterVehicle $record): array => [
                                'inspections' => $record->takeHomeInspections()->limit(100)->get(),
                            ]),
                    ])
                    ->collapsible(),

                Section::make('Proposed corrections')
                    ->description('Differences reported by an inspection or the FY27 list. The master record changes only when Fleet accepts.')
                    ->schema([
                        ViewEntry::make('proposed_corrections')
                            ->hiddenLabel()
                            ->view('filament.fleet.vehicle-proposed-corrections')
                            ->viewData(fn (UnitMasterVehicle $record): array => [
                                'changes' => $this->pendingChanges($record),
                                'canDecide' => $this->canDecide(),
                                'valueLabel' => fn (UnitMasterVehicleChange $change, ?string $value): string => $this->valueLabel($change, $value),
                            ]),
                    ]),

                Section::make('Change history')
                    ->schema([
                        ViewEntry::make('change_history')
                            ->hiddenLabel()
                            ->view('filament.fleet.vehicle-change-history')
                            ->viewData(fn (UnitMasterVehicle $record): array => [
                                'changes' => $this->decidedChanges($record),
                                'valueLabel' => fn (UnitMasterVehicleChange $change, ?string $value): string => $this->valueLabel($change, $value),
                            ]),
                    ])
                    ->collapsible(),
            ]);
    }

    public function acceptChangeAction(): Actions\Action
    {
        return Actions\Action::make('acceptChange')
            ->label('Accept')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Accept this correction?')
            ->modalDescription('The master vehicle record will be updated to the proposed value.')
            ->visible(fn (): bool => $this->canDecide())
            ->action(fn (array $arguments) => $this->decide($arguments, true));
    }

    public function rejectChangeAction(): Actions\Action
    {
        return Actions\Action::make('rejectChange')
            ->label('Reject')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject this correction?')
            ->modalDescription('The master vehicle record will keep its current value.')
            ->visible(fn (): bool => $this->canDecide())
            ->action(fn (array $arguments) => $this->decide($arguments, false));
    }

    /** Only Fleet managers (admin.fleet.manage or super_admin) may decide corrections. */
    private function canDecide(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', $this->getRecord());
    }

    /** @param array<string, mixed> $arguments */
    private function decide(array $arguments, bool $accept): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $this->canDecide(), 403);

        $change = UnitMasterVehicleChange::query()
            ->where('unit_master_vehicle_id', $this->getRecord()->getKey())
            ->findOrFail((int) ($arguments['change'] ?? 0));

        if ($change->status !== UnitMasterVehicleChange::STATUS_PROPOSED) {
            Notification::make()->title('This correction was already decided')->warning()->send();

            return;
        }

        $recorder = app(UnitMasterVehicleChangeRecorder::class);
        $accept ? $recorder->accept($change, $user) : $recorder->reject($change, $user);

        $this->getRecord()->refresh();
        Notification::make()
            ->title($accept ? 'Correction accepted' : 'Correction rejected')
            ->success()
            ->send();
    }

    private function lastInspection(UnitMasterVehicle $record): ?TakeHomeVehicleInspection
    {
        return $record->takeHomeInspections()->first();
    }

    /** @return Collection<int, UnitMasterVehicleChange> */
    private function pendingChanges(UnitMasterVehicle $record): Collection
    {
        return $record->changes()->pending()->with('user')->reorder('created_at', 'desc')->get();
    }

    /** @return Collection<int, UnitMasterVehicleChange> */
    private function decidedChanges(UnitMasterVehicle $record): Collection
    {
        return $record->changes()
            ->where('status', '!=', UnitMasterVehicleChange::STATUS_PROPOSED)
            ->with(['user', 'decidedBy'])
            ->limit(self::HISTORY_LIMIT)
            ->get();
    }

    /** Readable value for a change: driver ids become names, categories become labels. */
    private function valueLabel(UnitMasterVehicleChange $change, ?string $value): string
    {
        if (blank($value)) {
            return '(blank)';
        }

        return match ($change->field) {
            'current_driver_employee_id' => $this->employeeNames[$value] ??= (UnitMasterVehicleResource::employeeLabel(Employee::query()->find($value)) ?? 'Employee #'.$value),
            'asset_category' => UnitMasterVehicle::CATEGORY_LABELS[$value] ?? $value,
            default => $value,
        };
    }
}
