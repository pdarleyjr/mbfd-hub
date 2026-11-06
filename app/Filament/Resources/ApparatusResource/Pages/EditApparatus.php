<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\ApparatusWorkflowActions;
use App\Filament\Resources\ApparatusResource\Widgets\FrontlineCoverageBanner;
use App\Filament\Resources\ApparatusResource\Widgets\VehicleChangesBanner;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditApparatus extends EditRecord
{
    protected static string $resource = ApparatusResource::class;

    /**
     * The apparatus edit, the master vehicle write-through and the audit entries commit
     * or roll back together. The unit identity guard on the model reports against the
     * form field it is about.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return DB::transaction(fn (): Model => parent::handleRecordUpdate($record, $data));
        } catch (ValidationException $exception) {
            $record->refresh();

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [(str_starts_with($field, 'data.') ? $field : 'data.'.$field) => $messages])
                ->all());
        }
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }

    /** @return list<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [FrontlineCoverageBanner::class, VehicleChangesBanner::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ApparatusWorkflowActions::truckActions(),
            Actions\ActionGroup::make([
                ApparatusWorkflowActions::scheduleChangeout(Actions\Action::make('scheduleChangeout')),
                ApparatusWorkflowActions::addOperationalAlert(Actions\Action::make('addOperationalAlert')),
                ApparatusWorkflowActions::dailyCheckout(Actions\Action::make('daily_checkout')),
                ApparatusWorkflowActions::masterRecord(Actions\Action::make('vehicle_record')),
                ApparatusWorkflowActions::linkVehicleRecord(Actions\Action::make('linkVehicleRecord')),
                Actions\DeleteAction::make(),
            ])->label('More')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            Actions\ViewAction::make()->color('gray'),
        ];
    }
}
