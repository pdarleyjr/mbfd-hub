<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Filament\Support\HasNavigationParent;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\ApparatusInspectionExceptionService;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Services\Inspections\IssueMessageService;
use App\Services\Inspections\MemberInspectionConversation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Read-only detail of one Daily Checkout the signed-in member submitted.
 * There are no edit or delete actions: submitted evidence is immutable.
 */
class MyVehicleInspectionView extends Page implements HasNavigationParent
{
    use ResolvesCanonicalEmployee;

    protected static ?string $slug = 'my-vehicle-inspections/{inspection}';

    protected static string $view = 'filament.employee.pages.my-vehicle-inspection-view';

    protected static bool $shouldRegisterNavigation = false;

    public int $inspectionId;

    /** Draft reply on the general inspection thread. */
    public string $inspectionReply = '';

    /** @var array<int|string, string> draft replies on issue threads, keyed by defect id */
    public array $issueReplies = [];

    /** @var array<string, string> idempotency keys for in-flight replies ("inspection" or "issue-{id}") */
    public array $replyKeys = [];

    public function mount(int|string $inspection): void
    {
        $this->authenticatedEmployee();
        $this->inspectionId = (int) $this->authorizedInspection((int) $inspection)->getKey();
    }

    /** Reply on the general thread of this inspection. */
    public function sendInspectionReply(): void
    {
        [$user, $inspection] = $this->authorizedParticipant();
        $this->validate(['inspectionReply' => $this->replyRules()], [], ['inspectionReply' => 'message']);

        $this->deliver($user, 'inspection', ['inspection_id' => $inspection->getKey(), 'body' => $this->inspectionReply], 'inspectionReply');
        // A reply on the inspection thread answers any meter clarification waiting on this member:
        // hand it back to the reviewers' queue instead of leaving it on "Your input is requested".
        app(ApparatusInspectionExceptionService::class)->markMemberReplied((int) $inspection->getKey(), $user);
        $this->inspectionReply = '';
    }

    /** Reply on the thread of one issue this inspection reported or observed. */
    public function sendIssueReply(int $defectId): void
    {
        [$user, $inspection] = $this->authorizedParticipant();
        // Only issues shown on this inspection can be written to from this page.
        abort_unless(in_array($defectId, app(MemberInspectionConversation::class)->defectIds(app(EmployeeInspectionHistoryService::class)->detail($inspection)), true), 403);
        $this->validate(["issueReplies.{$defectId}" => $this->replyRules()], [], ["issueReplies.{$defectId}" => 'message']);

        $this->deliver($user, "issue-{$defectId}", [
            'inspection_id' => $inspection->getKey(),
            'defect_id' => $defectId,
            'body' => $this->issueReplies[$defectId] ?? '',
        ], "issueReplies.{$defectId}");
        unset($this->issueReplies[$defectId]);
    }

    public function getTitle(): string
    {
        return 'Vehicle Inspection';
    }

    public static function navigationParentPath(): string
    {
        return parse_url(MyVehicleInspections::getUrl(panel: 'employee'), PHP_URL_PATH) ?: '/employee/my-vehicle-inspections';
    }

    public static function navigationParentLabel(): string
    {
        return 'Back to my inspections';
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            MyVehicleInspections::getUrl(panel: 'employee') => 'My Vehicle Inspections',
            'Inspection',
        ];
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(route('employee.vehicle-inspections.pdf', ['inspection' => $this->inspectionId])),
            Action::make('print')
                ->label('Open printable PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(route('employee.vehicle-inspections.pdf', ['inspection' => $this->inspectionId, 'inline' => 1]))
                ->openUrlInNewTab(),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        // Ownership is re-checked on every render, not only on mount.
        $inspection = $this->authorizedInspection($this->inspectionId);
        $detail = app(EmployeeInspectionHistoryService::class)->detail($inspection);
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);

        return [
            'detail' => $detail,
            'conversation' => app(MemberInspectionConversation::class)->project($user, $inspection, $detail),
            'inspectionId' => $this->inspectionId,
        ];
    }

    /**
     * Every write re-checks that the signed-in user owns this inspection.
     *
     * @return array{0: User, 1: ApparatusInspection}
     */
    private function authorizedParticipant(): array
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);
        $inspection = $this->authorizedInspection($this->inspectionId);
        abort_unless(app(EmployeeInspectionHistoryService::class)->owns($user, $inspection), 403);

        return [$user, $inspection];
    }

    /** @return list<string> */
    private function replyRules(): array
    {
        return ['required', 'string', 'max:'.IssueMessageService::MAX_BODY];
    }

    /**
     * Post a member-visible reply. The client message id makes a double tap or a retry harmless.
     *
     * @param  array<string, mixed>  $data
     */
    private function deliver(User $user, string $slot, array $data, string $errorKey): void
    {
        $key = RateLimiter::attempt(
            'inspection-message:'.$user->getKey(),
            20,
            static fn (): bool => true,
            60,
        );
        if (! $key) {
            throw ValidationException::withMessages([$errorKey => 'You are sending messages too quickly. Wait a minute and try again.']);
        }

        $this->replyKeys[$slot] ??= (string) Str::uuid();

        try {
            // Visibility is omitted on purpose: a member can only write member-visible messages.
            app(IssueMessageService::class)->post($user, $data + ['client_message_id' => $this->replyKeys[$slot]]);
        } catch (AuthorizationException) {
            abort(403);
        }

        unset($this->replyKeys[$slot]);
        Notification::make()->success()->title('Message sent')->send();
    }

    private function authorizedInspection(int $id): ApparatusInspection
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);

        $inspection = ApparatusInspection::query()->findOrFail($id);
        abort_unless($user->can('viewOwn', $inspection), 403);

        return $inspection;
    }
}
