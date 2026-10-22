<?php

namespace App\Filament\Resources\InspectionResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use App\Services\Inspections\IssueMessageService;
use App\Support\Fleet\InspectionReviewers;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one authoritative admin page for a daily inspection. Everything a reviewer
 * needs sits here: facts, holds and issues, the original checklist, the activity
 * timeline and the vehicle's recent history. The apparatus-scoped URL redirects here.
 */
class ViewInspection extends ViewRecord
{
    protected static string $resource = InspectionResource::class;

    protected static string $view = 'filament.resources.inspection-resource.pages.view-inspection';

    /**
     * Unsent composer text per issue, keyed by defect id: ['body' => ..., 'visibility' => 'member'|'internal'].
     *
     * @var array<int|string, array<string, mixed>>
     */
    public array $issueDrafts = [];

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
            Action::make('replyToMember')
                ->label('Reply to member')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('gray')
                ->modalHeading('Reply to the member who submitted this inspection')
                ->modalDescription('The member can read this reply on their inspection and gets a notification. Use an issue card for staff-only notes.')
                ->modalSubmitActionLabel('Send reply')
                ->visible(fn (): bool => $this->isReviewer())
                ->mountUsing(fn (Forms\ComponentContainer $form) => $form->fill(['client_message_id' => (string) Str::uuid()]))
                ->form([
                    Forms\Components\Hidden::make('client_message_id'),
                    Forms\Components\Textarea::make('body')
                        ->label('Your reply')
                        ->required()
                        ->rows(4)
                        ->maxLength(IssueMessageService::MAX_BODY),
                ])
                ->action(function (array $data): void {
                    $this->sendMessage(
                        ['inspection_id' => $this->inspection()->getKey(), 'visibility' => ApparatusIssueMessage::VISIBILITY_MEMBER, 'body' => $data['body']],
                        $data['client_message_id'] ?? null,
                        'Reply sent to the member',
                    );
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

    // ------------------------------------------------ per-issue corrective actions

    public function acknowledgeIssueAction(): Action
    {
        return DefectCorrectiveActions::acknowledge(Action::make(DefectCorrectiveActions::ACKNOWLEDGE), $this->defectResolver());
    }

    public function updateIssueAction(): Action
    {
        return DefectCorrectiveActions::update(Action::make(DefectCorrectiveActions::UPDATE), $this->defectResolver());
    }

    public function resolveIssueAction(): Action
    {
        return DefectCorrectiveActions::resolve(Action::make(DefectCorrectiveActions::RESOLVE), $this->defectResolver());
    }

    public function dismissIssueAction(): Action
    {
        return DefectCorrectiveActions::dismiss(Action::make(DefectCorrectiveActions::DISMISS), $this->defectResolver());
    }

    public function reopenIssueAction(): Action
    {
        return DefectCorrectiveActions::reopen(Action::make(DefectCorrectiveActions::REOPEN), $this->defectResolver());
    }

    public function verifyCorrectionAction(): Action
    {
        return DefectCorrectiveActions::verify(Action::make(DefectCorrectiveActions::VERIFY), $this->defectResolver());
    }

    public function classifyImpactAction(): Action
    {
        return DefectCorrectiveActions::classify(Action::make(DefectCorrectiveActions::CLASSIFY), $this->defectResolver());
    }

    public function linkServiceTicketAction(): Action
    {
        return DefectCorrectiveActions::ticket(Action::make(DefectCorrectiveActions::TICKET), $this->defectResolver());
    }

    /**
     * Posts the composer text under an issue card. The client message id is generated in the
     * browser per composer, so a double click sends the same id and stores one message.
     */
    public function postIssueMessage(int $defectId, string $clientMessageId): void
    {
        $defect = $this->observedDefect(['defect' => $defectId]);
        abort_unless($defect instanceof ApparatusDefect, 404);
        $draft = $this->issueDrafts[$defectId] ?? [];
        $visibility = ($draft['visibility'] ?? null) === ApparatusIssueMessage::VISIBILITY_INTERNAL
            ? ApparatusIssueMessage::VISIBILITY_INTERNAL
            : ApparatusIssueMessage::VISIBILITY_MEMBER;

        $sent = $this->sendMessage(
            [
                'inspection_id' => $this->inspection()->getKey(),
                'defect_id' => $defect->getKey(),
                'visibility' => $visibility,
                'body' => (string) ($draft['body'] ?? ''),
            ],
            $clientMessageId,
            $visibility === ApparatusIssueMessage::VISIBILITY_INTERNAL ? 'Internal note saved' : 'Message sent to the member',
        );
        if ($sent) {
            unset($this->issueDrafts[$defectId]);
        }
    }

    /**
     * Resolves the issue a mounted action is about, or null when no issue was named. An id
     * that is not one of this inspection's observed issues on its physical vehicle is a 404:
     * a forged id can never reach the service.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function observedDefect(array $arguments): ?ApparatusDefect
    {
        if (! isset($arguments['defect'])) {
            return null;
        }
        $id = (int) $arguments['defect'];
        $inspection = $this->inspection();
        $physical = (int) ($inspection->processed_apparatus_id ?? $inspection->apparatus_id);

        $observed = ApparatusDefectObservation::query()
            ->where('apparatus_inspection_id', $inspection->getKey())
            ->where('apparatus_defect_id', $id)
            ->exists()
            || ApparatusDefect::query()->whereKey($id)->where('apparatus_inspection_id', $inspection->getKey())->exists();
        $defect = $observed ? ApparatusDefect::query()->where('apparatus_id', $physical)->find($id) : null;
        abort_unless($defect instanceof ApparatusDefect, 404);

        return $defect;
    }

    /** @return Closure(MountableAction): ?ApparatusDefect */
    private function defectResolver(): Closure
    {
        return fn (MountableAction $action): ?ApparatusDefect => $this->observedDefect($action->getArguments());
    }

    /** @param array<string, mixed> $payload */
    private function sendMessage(array $payload, ?string $clientMessageId, string $success): bool
    {
        $author = auth()->user();
        abort_unless($author instanceof User && $this->isReviewer(), 403);

        try {
            app(IssueMessageService::class)->post($author, [...$payload, 'client_message_id' => $clientMessageId]);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('That could not be sent')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'Write a message and try again.'))->send();

            return false;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('You do not have permission to do this.')->send();

            return false;
        }
        Notification::make()->success()->title($success)->send();

        return true;
    }

    private function isReviewer(): bool
    {
        $user = auth()->user();

        return $user instanceof User && InspectionReviewers::canReview($user);
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
