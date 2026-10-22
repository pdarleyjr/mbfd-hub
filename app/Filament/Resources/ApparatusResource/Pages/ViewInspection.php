<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use Filament\Resources\Pages\Page;

/**
 * Former apparatus-scoped inspection review page. The route stays so every old
 * bookmark and notification keeps working, but the page itself no longer exists:
 * it authorizes the request exactly as before and redirects to the one canonical
 * inspection page. A URL fragment (#approval, #issue-12) survives the redirect.
 */
class ViewInspection extends Page
{
    protected static string $resource = ApparatusResource::class;

    protected static string $view = 'filament.resources.apparatus-resource.pages.view-inspection';

    public Apparatus $record;

    public ApparatusInspection $inspection;

    public function mount($record, $inspection): void
    {
        // $record may be an Apparatus model (Filament auto-resolves) or an ID
        $this->record = $record instanceof Apparatus ? $record : Apparatus::findOrFail($record);
        abort_unless(ApparatusResource::canView($this->record), 403);

        $inspectionId = $inspection instanceof ApparatusInspection ? $inspection->getKey() : $inspection;
        $this->inspection = ApparatusInspection::query()
            ->where(function ($scope): void {
                $scope->where('apparatus_id', $this->record->getKey())
                    ->orWhere('processed_apparatus_id', $this->record->getKey());
            })
            ->findOrFail($inspectionId);
        abort_unless(auth()->user()?->can('view', $this->inspection), 403);

        $this->redirect(InspectionWorkspace::url($this->inspection), navigate: false);
    }
}
