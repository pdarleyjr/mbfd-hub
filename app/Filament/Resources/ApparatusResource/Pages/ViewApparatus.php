<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\ApparatusWorkflowActions;
use App\Filament\Resources\ApparatusResource\Widgets\FrontlineCoverageBanner;
use App\Filament\Resources\ApparatusResource\Widgets\VehicleChangesBanner;
use App\Filament\Resources\ChecklistTemplateResource;
use App\Models\Apparatus;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use App\Services\DailyCheckoutChecklistResolver;
use App\Support\Fleet\ChecklistTemplateAccess;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

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

    /** @return list<class-string> a unit without an In Service truck, and a change involving this truck */
    protected function getHeaderWidgets(): array
    {
        return [FrontlineCoverageBanner::class, VehicleChangesBanner::class];
    }

    /** The truck actions first (swap, out of service, back in service), then everyday work, reference links and editing. */
    protected function getHeaderActions(): array
    {
        return [
            ...ApparatusWorkflowActions::truckActions(),
            ApparatusWorkflowActions::scheduleService(Actions\Action::make('scheduleService')),
            ApparatusWorkflowActions::logPm(Actions\Action::make('logPmService')),
            Actions\ActionGroup::make([
                ApparatusWorkflowActions::changeStatus(Actions\Action::make('updateStatus')),
                ApparatusWorkflowActions::changeUnitAssignment(Actions\Action::make('changeUnitAssignment')),
                ApparatusWorkflowActions::scheduleChangeout(Actions\Action::make('scheduleChangeout')),
                ApparatusWorkflowActions::addOperationalAlert(Actions\Action::make('addOperationalAlert')),
                ApparatusWorkflowActions::dailyCheckout(Actions\Action::make('daily_checkout')),
                Actions\Action::make('checklistTemplate')
                    ->label('Edit checklist template')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->visible(fn (): bool => $this->checklistTemplateType() !== null)
                    ->url(fn (): ?string => ($type = $this->checklistTemplateType()) !== null
                        ? ChecklistTemplateResource::getUrl('editor', ['type' => $type])
                        : null),
                ApparatusWorkflowActions::masterRecord(Actions\Action::make('vehicle_record')),
                ApparatusWorkflowActions::linkVehicleRecord(Actions\Action::make('linkVehicleRecord')),
            ])->label('More')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            Actions\EditAction::make()->color('gray'),
        ];
    }

    /** The Daily Checkout checklist this unit resolves to, when the signed-in user may edit that template. */
    private function checklistTemplateType(): ?string
    {
        $record = $this->getRecord();
        if (! $record instanceof Apparatus || ! ChecklistTemplateAccess::canManage(auth()->user())) {
            return null;
        }
        try {
            $type = app(DailyCheckoutChecklistResolver::class)->checklistTypeFor($record);

            return in_array($type, app(ChecklistTemplateEditor::class)->types(), true) ? $type : null;
        } catch (Throwable) {
            return null;
        }
    }
}
