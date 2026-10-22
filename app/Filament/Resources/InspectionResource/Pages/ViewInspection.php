<?php

namespace App\Filament\Resources\InspectionResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The one authoritative admin page for a daily inspection. Everything a reviewer
 * needs sits here: facts, holds and issues, the original checklist, the activity
 * timeline and the vehicle's recent history. The apparatus-scoped URL redirects here.
 */
class ViewInspection extends ViewRecord
{
    protected static string $resource = InspectionResource::class;

    protected static string $view = 'filament.resources.inspection-resource.pages.view-inspection';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Same gate as the former apparatus-scoped review page.
        $apparatus = $this->inspection()->getRelationValue('apparatus');
        abort_unless(! $apparatus instanceof Apparatus || ApparatusResource::canView($apparatus), 403);
    }

    public function getTitle(): string|Htmlable
    {
        $inspection = $this->inspection();

        return 'Inspection '.($inspection->inspection_reference ?: '#'.$inspection->getKey());
    }

    public function getHeading(): string|Htmlable
    {
        $inspection = $this->inspection();
        $designation = $inspection->designation_at_time ?: $inspection->getRelationValue('apparatus')?->designation;

        return 'Inspection: '.($designation ?: 'Apparatus');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $viewer = auth()->user();

        return [
            'workspace' => app(InspectionWorkspace::class)->build($this->inspection(), $viewer instanceof User ? $viewer : null),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveInspection')
                ->label('Approve inspection')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This accepts the inspection and applies the reported meter readings and defects to the apparatus. It does not change the unit\'s operational status unless you choose to below.')
                ->form([
                    Forms\Components\Toggle::make('mark_out_of_service')
                        ->label('Mark unit Out of Service')
                        ->helperText(fn (): string => $this->reportsCriticalDefects()
                            ? 'Missing or damaged equipment was reported. Review it, then confirm whether this unit should be taken Out of Service.'
                            : 'No missing or damaged equipment was reported. Leave off unless you have another reason to take the unit out of service.')
                        ->default(fn (): bool => $this->reportsCriticalDefects()),
                ])
                ->visible(fn (): bool => $this->isAwaitingDecision() && $this->canApproveInspection())
                ->action(function (array $data, ApparatusInspectionApprovalService $approvalService): void {
                    $reviewer = auth()->user();
                    abort_unless($reviewer instanceof User && $this->canApproveInspection(), 403);

                    $approvalService->approve(
                        (int) $this->inspection()->getKey(),
                        $reviewer,
                        (bool) ($data['mark_out_of_service'] ?? false),
                    );
                    $this->refreshInspection();

                    Notification::make()->success()->title('Inspection approved')->send();
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
                ->visible(fn (): bool => $this->isAwaitingDecision() && $this->canRejectInspection())
                ->action(function (array $data, ApparatusInspectionApprovalService $approvalService): void {
                    $reviewer = auth()->user();
                    abort_unless($reviewer instanceof User && $this->canRejectInspection(), 403);

                    $approvalService->reject(
                        (int) $this->inspection()->getKey(),
                        $reviewer,
                        trim($data['review_notes']),
                    );
                    $this->refreshInspection();

                    Notification::make()->success()->title('Inspection rejected')->send();
                }),
            Action::make('print')
                ->label('Print')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->extraAttributes(['onclick' => 'window.print(); return false;']),
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => route('employee.vehicle-inspections.pdf', $this->inspection()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => (bool) auth()->user()?->can('view', $this->inspection())),
        ];
    }

    private function inspection(): ApparatusInspection
    {
        $inspection = $this->getRecord();
        abort_unless($inspection instanceof ApparatusInspection, 404);

        return $inspection;
    }

    private function refreshInspection(): void
    {
        $this->record = $this->inspection()->refresh();
    }

    /** Waiting on a person: not yet decided and not held for a vehicle swap decision. */
    private function isAwaitingDecision(): bool
    {
        $inspection = $this->inspection();

        return $inspection->review_status === 'pending_review' && ! $inspection->isHeldForVehicleSwap();
    }

    /** Unprepared (legacy) evidence is treated as reporting critical defects; processed inspections default the choice off. */
    private function reportsCriticalDefects(): bool
    {
        $inspection = $this->inspection();
        if ($inspection->processing_status !== null) {
            return false;
        }

        $pendingEffects = $inspection->pending_effects;

        return $pendingEffects === null || (bool) ($pendingEffects['has_critical_defects'] ?? false);
    }

    private function canApproveInspection(): bool
    {
        return auth()->user()?->can('approve', $this->inspection()) ?? false;
    }

    private function canRejectInspection(): bool
    {
        return auth()->user()?->can('reject', $this->inspection()) ?? false;
    }
}
