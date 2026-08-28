<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class ViewInspection extends Page
{
    protected static string $resource = ApparatusResource::class;

    protected static string $view = 'filament.resources.apparatus-resource.pages.view-inspection';

    public Apparatus $record;

    public ApparatusInspection $inspection;

    public function mount($record, $inspection): void
    {
        // $record may be an Apparatus model (Filament auto-resolves) or an ID
        if ($record instanceof Apparatus) {
            $this->record = $record;
        } else {
            $this->record = Apparatus::findOrFail($record);
        }
        abort_unless(ApparatusResource::canView($this->record), 403);

        $inspectionId = $inspection instanceof ApparatusInspection ? $inspection->getKey() : $inspection;
        $this->inspection = ApparatusInspection::query()
            ->with(['defects', 'reviewEvents.changedByUser'])
            ->where('apparatus_id', $this->record->getKey())
            ->findOrFail($inspectionId);
        abort_unless(auth()->user()?->can('view', $this->inspection), 403);
    }

    public function getTitle(): string
    {
        $designation = $this->record->designation ?? $this->inspection->designation_at_time ?? 'Unknown';

        return "Inspection Results — {$designation}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveInspection')
                ->label('Approve inspection')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This applies the reported meter readings and defects to the apparatus. Critical defects will place it Out of Service.')
                ->visible(fn (): bool => $this->inspection->review_status === 'pending_review'
                    && $this->canApproveInspection())
                ->action(function (ApparatusInspectionApprovalService $approvalService): void {
                    $reviewer = auth()->user();
                    abort_unless($reviewer instanceof User && $this->canApproveInspection(), 403);

                    $approvalService->approve((int) $this->inspection->getKey(), $reviewer);
                    $this->inspection->refresh()->load(['defects', 'reviewEvents.changedByUser']);

                    Notification::make()
                        ->success()
                        ->title('Inspection approved')
                        ->send();
                }),
            Action::make('rejectInspection')
                ->label('Reject inspection')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('This retains the submitted evidence and records your required review note. It does not apply any reported apparatus effects.')
                ->form([
                    Forms\Components\Textarea::make('review_notes')
                        ->label('Review note')
                        ->required()
                        ->maxLength(2000)
                        ->rows(4),
                ])
                ->visible(fn (): bool => $this->inspection->review_status === 'pending_review'
                    && $this->canRejectInspection())
                ->action(function (array $data, ApparatusInspectionApprovalService $approvalService): void {
                    $reviewer = auth()->user();
                    abort_unless($reviewer instanceof User && $this->canRejectInspection(), 403);

                    $approvalService->reject(
                        (int) $this->inspection->getKey(),
                        $reviewer,
                        trim($data['review_notes']),
                    );
                    $this->inspection->refresh()->load(['defects', 'reviewEvents.changedByUser']);

                    Notification::make()
                        ->success()
                        ->title('Inspection rejected')
                        ->send();
                }),
            Action::make('print')
                ->label('Print Results')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->extraAttributes([
                    'onclick' => 'window.print(); return false;',
                ]),
            Action::make('back')
                ->label('Back to Apparatus')
                ->icon('heroicon-o-arrow-left')
                ->url(ApparatusResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    protected function getViewData(): array
    {
        $results = $this->inspection->results ?? [];
        $defects = $this->inspection->defects;
        $pendingEffects = $this->inspection->pending_effects;
        $pendingDefects = is_array($pendingEffects) && is_array($pendingEffects['defects'] ?? null)
            ? $pendingEffects['defects']
            : [];
        $pendingChecklistV2 = is_array($pendingEffects) && is_array($pendingEffects['checklist_v2'] ?? null)
            ? $pendingEffects['checklist_v2']
            : null;
        $reviewEvents = $this->inspection->reviewEvents;
        $currentDesignation = $this->record->designation ?? $this->inspection->designation_at_time ?? '—';

        // Compute stats
        $totalItems = 0;
        $presentCount = 0;
        $missingCount = 0;
        $damagedCount = 0;

        foreach ($results as $compartment) {
            foreach ($compartment['items'] ?? [] as $item) {
                $totalItems++;
                $status = $item['status'] ?? 'Present';
                if ($status === 'Present') {
                    $presentCount++;
                } elseif ($status === 'Missing') {
                    $missingCount++;
                } elseif ($status === 'Damaged') {
                    $damagedCount++;
                }
            }
        }

        return [
            'inspection' => $this->inspection,
            'apparatus' => $this->record,
            'currentDesignation' => $currentDesignation,
            'results' => $results,
            'defects' => $defects,
            'pendingDefects' => $pendingDefects,
            'pendingChecklistV2' => $pendingChecklistV2,
            'reviewEvents' => $reviewEvents,
            'totalItems' => $totalItems,
            'presentCount' => $presentCount,
            'missingCount' => $missingCount,
            'damagedCount' => $damagedCount,
        ];
    }

    private function canApproveInspection(): bool
    {
        return auth()->user()?->can('approve', $this->inspection) ?? false;
    }

    private function canRejectInspection(): bool
    {
        return auth()->user()?->can('reject', $this->inspection) ?? false;
    }
}
