<?php

namespace App\Filament\Resources\InspectionResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\InspectionHoldActions;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Inspections\InspectionHold;
use App\Services\Inspections\InspectionReviewService;
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
                ->modalDescription('Approving accepts this inspection record and clears its holds. It does not certify that the unit is safe or ready for service, and it does not change the unit\'s operational status unless you choose to below.')
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('Note (optional)')
                        ->helperText('Kept in the review history.')
                        ->rows(3)
                        ->maxLength(2000),
                    Forms\Components\Toggle::make('mark_out_of_service')
                        ->label('Also mark unit Out of Service')
                        ->helperText('Off by default. Turn it on only if what this inspection shows means the unit should come out of service now.')
                        ->default(false)
                        ->visible(fn (): bool => $this->canUpdateApparatus()),
                ])
                ->visible(fn (): bool => $this->isAwaitingDecision() && $this->canApproveInspection())
                ->disabled(fn (): bool => $this->hasOpenMeterHold())
                ->tooltip(fn (): ?string => $this->hasOpenMeterHold() ? 'Decide the meter reading first.' : null)
                ->action(function (array $data): void {
                    $this->decide(
                        fn (User $reviewer) => app(InspectionReviewService::class)->approve(
                            (int) $this->inspection()->getKey(),
                            $reviewer,
                            $data['note'] ?? null,
                            $this->canUpdateApparatus() && (bool) ($data['mark_out_of_service'] ?? false),
                        ),
                        'Inspection approved',
                    );
                }),
            Action::make('requestClarification')
                ->label('Request clarification')
                ->icon('heroicon-o-question-mark-circle')
                ->color('gray')
                ->modalHeading('Ask the member for more information')
                ->modalDescription('The inspection keeps its status. The member gets your question and can answer from their inspection.')
                ->modalSubmitActionLabel('Send question')
                ->visible(fn (): bool => $this->isAwaitingDecision() && $this->isReviewer())
                ->mountUsing(fn (Forms\ComponentContainer $form) => $form->fill(['client_message_id' => (string) Str::uuid()]))
                ->form([
                    Forms\Components\Hidden::make('client_message_id'),
                    Forms\Components\Textarea::make('message')
                        ->label('Your question')
                        ->required()
                        ->rows(4)
                        ->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $this->decide(
                        fn (User $reviewer) => app(InspectionReviewService::class)->requestClarification(
                            (int) $this->inspection()->getKey(),
                            $reviewer,
                            (string) $data['message'],
                            $data['client_message_id'] ?? null,
                        ),
                        'Question sent to the member',
                    );
                }),
            Action::make('rejectInspection')
                ->label('Reject inspection')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The submitted evidence is kept, and any missing or damaged findings stay on record for follow-up. Meter readings that passed the checks were already applied; a reading held for review is not (the current reading is kept). A rejected checkout does not count as done, so the member will need to submit a new one. The member sees the reason you give below.')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason shared with the member')
                        ->helperText('Required. Write it so the member knows what to do next.')
                        ->required()
                        ->maxLength(2000)
                        ->rows(4),
                    Forms\Components\Textarea::make('internal_note')
                        ->label('Internal note (Logistics only)')
                        ->helperText('Optional. Never shown to the member.')
                        ->maxLength(2000)
                        ->rows(3),
                ])
                ->visible(fn (): bool => $this->isAwaitingDecision() && $this->canRejectInspection())
                ->action(function (array $data): void {
                    $this->decide(
                        fn (User $reviewer) => app(InspectionReviewService::class)->reject(
                            (int) $this->inspection()->getKey(),
                            $reviewer,
                            trim((string) $data['reason']),
                            filled($data['internal_note'] ?? null) ? trim((string) $data['internal_note']) : null,
                        ),
                        'Inspection rejected',
                    );
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

    public function correctSeverityAction(): Action
    {
        return DefectCorrectiveActions::correctSeverity(Action::make(DefectCorrectiveActions::CORRECT_SEVERITY), $this->defectResolver());
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

    /** True while a meter rollback hold is undecided; approval must wait for that decision. */
    private function hasOpenMeterHold(): bool
    {
        return InspectionHold::openQuery((int) $this->inspection()->getKey())
            ->whereIn('field', InspectionHold::METER_FIELDS)
            ->exists();
    }

    /** Out of Service is a separate decision: only people who may update the apparatus are offered it. */
    private function canUpdateApparatus(): bool
    {
        $inspection = $this->inspection();
        $apparatus = Apparatus::query()->find($inspection->processed_apparatus_id ?? $inspection->apparatus_id);

        return $apparatus instanceof Apparatus && (auth()->user()?->can('update', $apparatus) ?? false);
    }

    /**
     * Runs one reviewer decision and turns a refusal into a plain-language message.
     *
     * @param  Closure(User): mixed  $decision
     */
    private function decide(Closure $decision, string $success): void
    {
        $reviewer = auth()->user();
        abort_unless($reviewer instanceof User && $this->isReviewer(), 403);

        try {
            $decision($reviewer);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('That was not saved')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'Check the form and try again.'))->send();

            return;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('You do not have permission to do this.')->send();

            return;
        }
        $this->refreshInspection();
        Notification::make()->success()->title($success)->send();
    }

    // ------------------------------------------------ meter rollback hold decisions

    public function acceptMeterReadingAction(): Action
    {
        return InspectionHoldActions::accept(Action::make(InspectionHoldActions::ACCEPT), $this->holdResolver(), (int) $this->inspection()->getKey());
    }

    public function enterVerifiedReadingAction(): Action
    {
        return InspectionHoldActions::correct(Action::make(InspectionHoldActions::CORRECT), $this->holdResolver(), (int) $this->inspection()->getKey());
    }

    public function requestMeterClarificationAction(): Action
    {
        return InspectionHoldActions::clarify(Action::make(InspectionHoldActions::CLARIFY), $this->holdResolver(), (int) $this->inspection()->getKey());
    }

    public function keepCurrentReadingAction(): Action
    {
        return InspectionHoldActions::keep(Action::make(InspectionHoldActions::KEEP), $this->holdResolver(), (int) $this->inspection()->getKey());
    }

    /**
     * The meter exception a mounted action names, or null when none was named. An id that is
     * not a meter exception of this inspection is a 404, so a forged id never reaches the service.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function observedHold(array $arguments): ?ApparatusInspectionException
    {
        if (! isset($arguments['exception'])) {
            return null;
        }
        $hold = ApparatusInspectionException::query()
            ->where('apparatus_inspection_id', $this->inspection()->getKey())
            ->whereIn('field', InspectionHold::METER_FIELDS)
            ->find((int) $arguments['exception']);
        abort_unless($hold instanceof ApparatusInspectionException, 404);

        return $hold;
    }

    /** @return Closure(MountableAction): ?ApparatusInspectionException */
    private function holdResolver(): Closure
    {
        return fn (MountableAction $action): ?ApparatusInspectionException => $this->observedHold($action->getArguments());
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
