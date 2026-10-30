<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\ApparatusWorkflowActions;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewApparatus extends ViewRecord
{
    protected static string $resource = ApparatusResource::class;

    /**
     * Admin > Fire Apparatus > this unit, the same trail whether reached from the dashboard,
     * a direct link or a refresh.
     *
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Admin',
            ApparatusResource::getUrl('index') => ApparatusResource::getPluralModelLabel(),
            (string) $this->getRecordTitle(),
        ];
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }

    /** The unit's everyday work first; reference links and editing after. */
    protected function getHeaderActions(): array
    {
        return [
            ApparatusWorkflowActions::scheduleService(Actions\Action::make('scheduleService')),
            ApparatusWorkflowActions::logPm(Actions\Action::make('logPmService')),
            ApparatusWorkflowActions::changeStatus(Actions\Action::make('updateStatus')),
            Actions\ActionGroup::make([
                ApparatusWorkflowActions::dailyCheckout(Actions\Action::make('daily_checkout')),
                ApparatusWorkflowActions::masterRecord(Actions\Action::make('vehicle_record')),
                ApparatusWorkflowActions::linkVehicleRecord(Actions\Action::make('linkVehicleRecord')),
            ])->label('More')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            Actions\EditAction::make()->color('gray'),
        ];
    }
}
