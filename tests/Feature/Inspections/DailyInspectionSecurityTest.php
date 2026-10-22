<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Filament\Employee\Pages\MyVehicleInspectionView;
use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Filament\Support\InspectionWorkspace;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\ApparatusInspectionExceptionService;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\InspectionReviewService;
use App\Services\Inspections\IssueMessageService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;
use Tests\TestCase;

/**
 * Independent security regression suite (Agent F) for the daily inspection
 * continuity feature: authentication, authorization, confidentiality of
 * internal notes, forged identifiers, input bounds and idempotency.
 */
final class DailyInspectionSecurityTest extends TestCase
{
    use BuildsDailyScenarioWorld;
    use RefreshDatabase;

    private const INTERNAL_MARKERS = [
        'INT-ACK-SECRET', 'INT-ACTION-SECRET', 'INT-MSG-SECRET', 'INT-DEFECT-MSG-SECRET', 'INT-REVIEW-SECRET',
        'INT-REJECT-SECRET', 'INT-CLASSIFY-SECRET', 'INT-RESOLVE-SECRET', 'INT-RECONCILE-SECRET',
    ];

    private User $alpha;

    private User $beta;

    private User $stranger;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->alpha = $this->newMember('Alpha Member');
        $this->beta = $this->newMember('Beta Member');
        $this->stranger = $this->newMember('Stranger Member');
        $this->reviewer = $this->newReviewer('Logistics Reviewer');
    }

    // ------------------------------------------------- unauthenticated access

    public function test_unauthenticated_requests_are_denied_on_every_surface(): void
    {
        Queue::fake();
        $inspection = $this->directInspection($this->alpha);
        $exception = ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_id' => $this->unit->id,
            'field' => 'miles', 'reason' => 'rollback', 'blocks_acceptance' => true,
            'submitted_value' => 900, 'authoritative_value' => 1000,
        ]);

        $this->getJson($this->checklistUrl())->assertUnauthorized();
        $this->postJson($this->submissionUrl(), [])->assertUnauthorized();
        $this->postJson("/api/apparatus-inspections/{$inspection->id}/approve")->assertUnauthorized();
        $this->postJson("/api/apparatus-inspections/{$inspection->id}/reject", ['review_notes' => 'x'])->assertUnauthorized();
        $this->postJson("/api/apparatus-inspection-exceptions/{$exception->id}/reconcile", [])->assertUnauthorized();

        foreach ([
            '/admin/inspections/'.$inspection->id,
            '/employee/my-vehicle-inspections/'.$inspection->id,
            '/employee/vehicle-inspections/'.$inspection->id.'/pdf',
            '/employee/vehicle-inspections/'.$inspection->id.'/images/signature',
        ] as $url) {
            $response = $this->get($url);
            $this->assertContains($response->getStatusCode(), [302, 401, 403], "{$url} must not render for a guest.");
            if ($response->getStatusCode() === 302) {
                $this->assertStringContainsString('login', (string) $response->headers->get('Location'), "{$url} must redirect a guest to sign in.");
            }
            $this->assertStringNotContainsString('Alpha Member', (string) $response->getContent());
        }
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
    }

    // ------------------------------------------- ordinary member cannot decide

    public function test_an_ordinary_member_cannot_approve_reject_reconcile_acknowledge_or_resolve_through_the_services(): void
    {
        Queue::fake();
        Notification::fake();
        $inspection = $this->heldFlaggedInspection();
        $hold = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->sole();
        $meter = $this->heldRollbackInspection();
        $defect = $this->openDefectFor($this->alpha);
        $events = ApparatusDefectEvent::query()->count();
        $reviewEvents = ApparatusInspectionReviewEvent::query()->count();
        $actions = app(DefectCorrectiveActionService::class);

        foreach ([$this->alpha, $this->stranger] as $member) {
            $this->assertDenied(fn () => app(InspectionReviewService::class)->approve($inspection->id, $member, 'x'));
            $this->assertDenied(fn () => app(InspectionReviewService::class)->reject($inspection->id, $member, 'Not allowed'));
            $this->assertDenied(fn () => app(InspectionReviewService::class)->requestClarification($inspection->id, $member, 'Question?'));
            $this->assertDenied(fn () => app(ApparatusInspectionExceptionService::class)->reconcile($meter['hold']->id, $member, [
                'action' => 'dismiss', 'reason' => 'nope', 'expected_current_value' => 1020,
            ]));
            $this->assertDenied(fn () => $actions->acknowledge($defect->id, $member, ['member_message' => 'hi']));
            $this->assertDenied(fn () => $actions->updateAction($defect->id, $member, ['action_status' => 'repair_scheduled']));
            $this->assertDenied(fn () => $actions->resolve($defect->id, $member, ['resolution_type' => 'other', 'resolution_notes' => 'done']));
            $this->assertDenied(fn () => $actions->dismiss($defect->id, $member, ['reason' => 'nah']));
            $this->assertDenied(fn () => $actions->reopen($defect->id, $member, ['reason' => 'nah']));
            $this->assertDenied(fn () => $actions->verifyCorrection($defect->id, $member));
            $this->assertDenied(fn () => $actions->classifyImpact($defect->id, $member, ['operational_impact' => 'out_of_service']));
            $this->assertDenied(fn () => $actions->linkTicket($defect->id, $member, []));
            // Members cannot write internal notes either.
            $this->assertDenied(fn () => app(IssueMessageService::class)->post($member, ['inspection_id' => $inspection->id, 'visibility' => 'internal', 'body' => 'x']));
        }

        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $this->assertSame('open', $hold->fresh()->status);
        $this->assertSame('open', $meter['hold']->fresh()->status);
        $this->assertFalse($defect->fresh()->resolved);
        $this->assertSame('reported', $defect->fresh()->action_status);
        $this->assertSame('In Service', $this->unit->fresh()->status);
        $this->assertSame($events, ApparatusDefectEvent::query()->count());
        $this->assertSame($reviewEvents, ApparatusInspectionReviewEvent::query()->count());
    }

    public function test_the_api_routes_refuse_members_and_view_only_staff(): void
    {
        Queue::fake();
        Notification::fake();
        $inspection = $this->heldFlaggedInspection();
        $meter = $this->heldRollbackInspection();
        $viewer = $this->newViewerOnly();

        foreach ([$this->alpha, $viewer] as $user) {
            $this->actAs($user);
            $this->postJson("/api/apparatus-inspections/{$inspection->id}/approve")->assertForbidden();
            $this->postJson("/api/apparatus-inspections/{$inspection->id}/reject", ['review_notes' => 'No.'])->assertForbidden();
            $this->postJson("/api/apparatus-inspection-exceptions/{$meter['hold']->id}/reconcile", [
                'action' => 'dismiss', 'reason' => 'No.', 'expected_current_value' => 1020,
            ])->assertForbidden();
        }
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $this->assertSame('open', $meter['hold']->fresh()->status);
    }

    public function test_view_only_staff_get_no_decision_action_on_the_admin_page_and_calling_one_changes_nothing(): void
    {
        Queue::fake();
        Notification::fake();
        $inspection = $this->heldFlaggedInspection();
        $viewer = $this->newViewerOnly();
        $this->actAs($viewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])->assertSuccessful();
        foreach ($page->instance()->getCachedHeaderActions() as $action) {
            if (in_array($action->getName(), ['print', 'downloadPdf'], true)) {
                continue;
            }
            $this->assertFalse($action->isVisible(), "Header action '{$action->getName()}' must be hidden from a non-reviewer.");
        }
        try {
            $page->callAction('approveInspection');
            $page->callAction('rejectInspection', data: ['review_notes' => 'No.']);
        } catch (\Throwable) {
            // A hidden action may throw or halt; what matters is that nothing was decided.
        }
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
        $this->assertSame('held_for_review', $inspection->fresh()->processing_status);
        $this->assertSame('open', ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->sole()->status);
    }

    public function test_a_plain_member_cannot_open_the_admin_page(): void
    {
        Queue::fake();
        Notification::fake();
        $inspection = $this->heldFlaggedInspection();

        foreach ([$this->alpha, $this->stranger] as $member) {
            $this->actAs($member);
            $response = $this->get('/admin/inspections/'.$inspection->id);
            $this->assertNotSame(200, $response->getStatusCode());
            $this->assertStringNotContainsString('Alpha Member', (string) $response->getContent());
        }
    }

    public function test_the_admin_livewire_surface_has_no_unreviewed_public_methods(): void
    {
        // Every public method of a Livewire page is callable from the browser. This guard forces
        // anyone adding one to ViewInspection to also add an authorization test for it here.
        $reflection = new ReflectionClass(ViewInspection::class);
        // Page lifecycle hooks, plus every public method that Livewire can be asked to run. Each action
        // builder and postIssueMessage is denied to a non-reviewer by
        // CanonicalInspectionActionsTest::test_every_public_page_method_is_refused_to_a_viewer_who_cannot_review.
        $known = [
            'mount', 'getTitle', 'getHeading',
            'acknowledgeIssueAction', 'updateIssueAction', 'resolveIssueAction', 'dismissIssueAction', 'reopenIssueAction',
            'verifyCorrectionAction', 'classifyImpactAction', 'linkServiceTicketAction',
            'acceptMeterReadingAction', 'enterVerifiedReadingAction', 'requestMeterClarificationAction', 'keepCurrentReadingAction',
            'postIssueMessage',
        ];
        $unknown = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === ViewInspection::class)
            ->map(static fn (ReflectionMethod $method): string => $method->getName())
            ->reject(static fn (string $name): bool => in_array($name, $known, true))
            ->values()->all();

        $this->assertSame([], $unknown, 'New public Livewire methods on ViewInspection need an explicit authorization test: '.implode(', ', $unknown));
    }

    // ----------------------------------------------- thread confidentiality

    public function test_a_member_cannot_read_or_write_another_members_inspection_or_issue_thread(): void
    {
        Queue::fake();
        Notification::fake();
        ['inspection' => $inspection, 'defect' => $defect] = $this->alphaReportsBatteryAndBetaConfirms();
        $messages = app(IssueMessageService::class);
        $messages->post($this->reviewer, ['inspection_id' => $inspection->id, 'visibility' => 'member', 'body' => 'GENERAL-MEMBER-VISIBLE']);
        $messages->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'ISSUE-MEMBER-VISIBLE']);

        // A stranger sees neither thread.
        $this->assertDenied(fn () => $messages->threadFor($this->stranger, $inspection));
        $this->assertDenied(fn () => $messages->threadFor($this->stranger, $defect));
        $this->assertDenied(fn () => $messages->post($this->stranger, ['inspection_id' => $inspection->id, 'body' => 'intrude']));
        $this->assertDenied(fn () => $messages->post($this->stranger, ['defect_id' => $defect->id, 'body' => 'intrude']));
        $this->assertFalse($messages->canPost($this->stranger, $defect));

        // Beta confirmed the same issue, so beta follows the issue thread but never alpha's inspection thread.
        $this->assertSame(['ISSUE-MEMBER-VISIBLE'], $messages->threadFor($this->beta, $defect)->pluck('body')->all());
        $this->assertDenied(fn () => $messages->threadFor($this->beta, $inspection));

        $this->actAs($this->stranger);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertForbidden();
        $this->get(route('employee.vehicle-inspections.pdf', $inspection))->assertForbidden();

        $this->actAs($this->beta);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertForbidden();
        $this->get(route('employee.vehicle-inspections.pdf', $inspection))->assertForbidden();

        $this->actAs($this->alpha);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()
            ->assertSee('GENERAL-MEMBER-VISIBLE')->assertSee('ISSUE-MEMBER-VISIBLE');
        $this->assertSame(2, ApparatusIssueMessage::query()->count(), 'Nothing was written by the refused attempts.');
    }

    public function test_tampering_with_the_livewire_inspection_id_cannot_reach_another_members_thread(): void
    {
        Queue::fake();
        Notification::fake();
        $own = $this->directInspection($this->alpha);
        $theirs = $this->directInspection($this->beta);

        $this->actAs($this->alpha);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $page = Livewire::test(MyVehicleInspectionView::class, ['inspection' => $own->id])->assertSuccessful();
        // The very render that follows the tampered property is refused.
        $page->set('inspectionId', $theirs->id)->assertForbidden();

        // A write attempted with the tampered property is refused as well, and stores nothing.
        $second = Livewire::test(MyVehicleInspectionView::class, ['inspection' => $own->id]);
        $second->set('inspectionReply', 'intrusion');
        $second->set('inspectionId', $theirs->id);
        $second->call('sendInspectionReply');
        $this->assertSame(0, ApparatusIssueMessage::query()->count());
    }

    public function test_internal_notes_never_reach_member_html_open_findings_the_pdf_view_or_notifications(): void
    {
        Queue::fake();
        Notification::fake();
        $setup = $this->internalNoteWorld();

        $this->actAs($this->alpha);
        foreach ([$setup['flagged'], $setup['rolledBack'], $setup['dismissed']] as $inspection) {
            $html = $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent();
            $this->assertNoInternalText($html, "member page for inspection {$inspection->id}");
        }
        $this->assertStringContainsString('MEMBER-VISIBLE-ACK', $this->get('/employee/my-vehicle-inspections/'.$setup['flagged']->id)->getContent());

        $json = $this->getJson($this->checklistUrl())->assertOk()->getContent();
        $this->assertNoInternalText((string) $json, 'GET checklist');

        // Beta confirmed the issue and follows it; beta's own page is equally clean.
        $this->actAs($this->beta);
        $this->assertNoInternalText($this->get('/employee/my-vehicle-inspections/'.$setup['betaInspection']->id)->assertOk()->getContent(), 'beta member page');

        // The PDF is rendered from the same view data the controller builds.
        $this->actAs($this->alpha);
        $captured = [];
        View::composer('pdf.apparatus-inspection', static function ($view) use (&$captured): void {
            $captured[] = $view->getData();
        });
        foreach ([$setup['flagged'], $setup['rolledBack'], $setup['dismissed']] as $inspection) {
            $this->get(route('employee.vehicle-inspections.pdf', $inspection))->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $this->assertCount(3, $captured, 'The PDF view must have been rendered for each inspection.');
        foreach ($captured as $data) {
            $this->assertNoInternalText(view('pdf.apparatus-inspection', $data)->render(), 'PDF view');
        }

        // Every notification payload, for every channel, is free of internal text.
        $inspected = 0;
        foreach ([$this->alpha, $this->beta, $this->reviewer] as $user) {
            foreach (Notification::sent($user, InspectionEventNotification::class) as $notification) {
                $payload = json_encode([
                    $notification->toDatabase($user),
                    $notification->toBudgetedEmail($user),
                    $notification->toWebPush($user, null)->toArray(),
                ], JSON_THROW_ON_ERROR);
                $this->assertNoInternalText($payload, "{$notification->kind} notification to {$user->name}");
                $inspected++;
            }
        }
        $this->assertGreaterThan(3, $inspected, 'The scenario must actually have produced notifications to inspect.');
    }

    public function test_open_findings_json_carries_member_visible_text_only_while_the_issue_is_open(): void
    {
        Queue::fake();
        Notification::fake();
        $defect = $this->openDefectFor($this->alpha);
        $actions = app(DefectCorrectiveActionService::class);
        $actions->acknowledge($defect->id, $this->reviewer, ['member_message' => 'MEMBER-VISIBLE-ACK', 'internal_note' => 'INT-ACK-SECRET']);
        app(IssueMessageService::class)->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'internal', 'body' => 'INT-DEFECT-MSG-SECRET']);
        app(IssueMessageService::class)->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'MEMBER-VISIBLE-LATEST']);

        $this->actAs($this->stranger);
        $response = $this->getJson($this->checklistUrl())->assertOk();
        $findings = $response->json('open_findings');
        $this->assertCount(1, $findings);
        $this->assertSame('MEMBER-VISIBLE-LATEST', $findings[0]['latest_member_update']['text']);
        $this->assertNoInternalText((string) $response->getContent(), 'GET checklist for an unrelated member');
        // No personnel, storage paths or workflow internals.
        foreach (['photo_path', 'photo', 'internal_note', 'first_reported_by', 'actor_user_id', 'operator_name'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $findings[0]);
        }
    }

    public function test_delivered_database_notifications_never_contain_internal_text(): void
    {
        // Real delivery: no Notification::fake and the synchronous test queue.
        $this->actAs($this->alpha);
        $payload = $this->payload(null, null, null);
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Battery gone.']);
        $this->submit($payload)->assertCreated();
        $defect = ApparatusDefect::query()->sole();

        $actions = app(DefectCorrectiveActionService::class);
        $actions->acknowledge($defect->id, $this->reviewer, ['member_message' => 'MEMBER-VISIBLE-ACK', 'internal_note' => 'INT-ACK-SECRET']);
        $actions->updateAction($defect->id, $this->reviewer, [
            'action_status' => 'repair_scheduled', 'member_message' => 'Repair is booked.', 'internal_note' => 'INT-ACTION-SECRET',
        ]);
        app(IssueMessageService::class)->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'member', 'body' => 'MEMBER-VISIBLE-NOTE']);
        app(IssueMessageService::class)->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'internal', 'body' => 'INT-DEFECT-MSG-SECRET']);
        $actions->resolve($defect->id, $this->reviewer, [
            'resolution_type' => 'repair_completed', 'resolution_notes' => 'Repair completed.', 'internal_note' => 'INT-RESOLVE-SECRET',
        ]);

        $rows = DB::table('notifications')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count(), 'Real delivery should have stored bell notifications.');
        $this->assertContains($this->alpha->id, $rows->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all());
        foreach ($rows as $row) {
            $this->assertNoInternalText((string) $row->data, 'notifications.data row '.$row->id);
        }
    }

    // ------------------------------------------------------- forged identifiers

    public function test_a_forged_defect_id_from_another_vehicle_is_rejected_by_messages_pages_and_the_workspace(): void
    {
        Queue::fake();
        Notification::fake();
        $inspection = $this->directInspection($this->alpha);
        $foreignDefect = ApparatusDefect::query()->create([
            'apparatus_id' => $this->frontline->id, 'compartment' => 'Cab', 'item' => 'Axe', 'status' => 'open', 'issue_type' => 'missing',
            'reported_date' => now()->toDateString(),
        ]);
        $messages = app(IssueMessageService::class);

        // A reviewer cannot attach another vehicle's issue to this inspection; a member cannot even probe it.
        try {
            $messages->post($this->reviewer, ['inspection_id' => $inspection->id, 'defect_id' => $foreignDefect->id, 'visibility' => 'member', 'body' => 'forged']);
            $this->fail('A defect of another apparatus must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('defect_id', $exception->errors());
        }
        $this->assertDenied(fn () => $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'defect_id' => $foreignDefect->id, 'body' => 'forged']));
        $this->assertSame(0, ApparatusIssueMessage::query()->count());

        // The member page refuses to write to an issue the inspection never observed.
        $this->actAs($this->alpha);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        Livewire::test(MyVehicleInspectionView::class, ['inspection' => $inspection->id])
            ->set('issueReplies.'.$foreignDefect->id, 'forged')
            ->call('sendIssueReply', $foreignDefect->id)
            ->assertForbidden();
        $this->assertSame(0, ApparatusIssueMessage::query()->count());

        // A hold whose stored metadata names a foreign defect never renders that defect on the admin page.
        ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_id' => $this->unit->id, 'field' => 'significant_damage',
            'reason' => 'new_significant_damage', 'blocks_acceptance' => true, 'metadata' => ['defect_ids' => [$foreignDefect->id]],
        ]);
        $workspace = app(InspectionWorkspace::class)->build($inspection->fresh(), $this->reviewer);
        $this->assertSame([], $workspace['defects']);

        // A meter decision made from another inspection's page is treated as not found.
        $other = $this->directInspection($this->beta);
        $exception = ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_id' => $this->unit->id, 'field' => 'miles', 'reason' => 'rollback',
            'blocks_acceptance' => true, 'submitted_value' => 900, 'authoritative_value' => 1000,
        ]);
        $this->expectException(ModelNotFoundException::class);
        app(ApparatusInspectionExceptionService::class)->reconcile($exception->id, $this->reviewer, [
            'action' => 'dismiss', 'reason' => 'x', 'expected_current_value' => 1000,
        ], $other->id);
    }

    public function test_submitted_identity_vehicle_and_status_fields_are_overwritten_or_ignored_by_the_server(): void
    {
        Queue::fake();
        Notification::fake();
        $this->actAs($this->alpha);
        $payload = $this->payload(null, 900, 401.2);
        $payload += [
            'actor_user_id' => $this->beta->id, 'employee_id' => $this->beta->employee_profile_id,
            'unit_number' => 'FORGED', 'vehicle_number' => 'FORGED',
            'apparatus_id' => $this->frontline->id, 'processed_apparatus_id' => $this->frontline->id,
            'review_status' => 'approved', 'processing_status' => 'accepted', 'inspection_reference' => 'INS-FORGED',
            'pending_effects' => ['defects' => []], 'review_flag_explanation' => 'forged', 'completed_at' => '2020-01-01 00:00:00',
        ];
        $payload['operator_name'] = 'Forged Name';
        $payload['rank'] = 'Forged Chief';

        $this->submit($payload)->assertCreated()
            ->assertJsonPath('processing_status', 'held_for_review')->assertJsonPath('review_status', 'pending_review');

        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame($this->alpha->id, $inspection->actor_user_id);
        $this->assertSame($this->alpha->employee_profile_id, $inspection->employee_id);
        $this->assertSame('Alpha Member', $inspection->operator_name);
        $this->assertSame('Firefighter', $inspection->rank);
        $this->assertSame($this->unit->id, $inspection->apparatus_id);
        $this->assertSame($this->unit->id, $inspection->processed_apparatus_id);
        $this->assertSame('V-202', $inspection->vehicle_number);
        $this->assertNotSame('INS-FORGED', $inspection->inspection_reference);
        $this->assertNull($inspection->review_flag_explanation);
        $this->assertGreaterThan(2020, $inspection->completed_at->year);
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
        $this->assertSame(5000, $this->frontline->fresh()->current_miles);
    }

    public function test_user_supplied_text_is_escaped_on_the_member_and_admin_pages(): void
    {
        Queue::fake();
        Notification::fake();
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $payload['review_flag'] = ['explanation' => '<script>alert("flag")</script>', 'items' => [$this->itemRef($payload)]];
        $this->markItem($payload, 0, 1, 'Missing', ['notes' => '<img src=x onerror=alert("note")>']);
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($payload)->assertCreated()->json('id'));
        $messages = app(IssueMessageService::class);
        $messages->post($this->reviewer, ['inspection_id' => $inspection->id, 'visibility' => 'member', 'body' => '<script>alert("reviewer")</script>']);
        $messages->post($this->reviewer, ['inspection_id' => $inspection->id, 'visibility' => 'internal', 'body' => '<script>alert("internal")</script>']);
        $messages->post($this->alpha, ['inspection_id' => $inspection->id, 'body' => '<script>alert("member")</script>']);

        $this->actAs($this->alpha);
        $member = $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->getContent();
        $this->actAs($this->reviewer);
        $admin = $this->get('/admin/inspections/'.$inspection->id)->assertOk()->getContent();

        foreach (['member page' => $member, 'admin page' => $admin] as $where => $html) {
            foreach (['alert("flag")', 'alert("note")', 'alert("reviewer")', 'alert("member")', 'alert("internal")'] as $payloadText) {
                $this->assertStringNotContainsString('<script>'.$payloadText, $html, "Unescaped script in the {$where}.");
                $this->assertStringNotContainsString('<img src=x onerror='.$payloadText, $html, "Unescaped markup in the {$where}.");
            }
        }
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;reviewer&quot;)', $member);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;internal&quot;)', $admin);
        $this->assertStringNotContainsString('alert(&quot;internal&quot;)', $member, 'Internal text never reaches the member page, escaped or not.');
    }

    // ------------------------------------------------------------- input bounds

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalidSubmissions')]
    public function test_invalid_review_flag_and_significance_input_is_a_422_with_no_side_effects(callable $mutate, string $errorKey): void
    {
        Queue::fake();
        Notification::fake();
        $this->actAs($this->alpha);
        $payload = $mutate($this->payload());

        $this->submit($payload)->assertUnprocessable()->assertJsonValidationErrors($errorKey);

        $this->assertSame(0, ApparatusInspection::query()->count());
        $this->assertSame(0, ApparatusDefect::query()->count());
        $this->assertSame(0, ApparatusInspectionException::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(1000, $this->unit->fresh()->current_miles);
    }

    /** @return array<string, array{0: callable, 1: string}> */
    public static function invalidSubmissions(): array
    {
        $flag = static fn (array $payload, array $flag): array => [...$payload, 'review_flag' => $flag];

        return [
            'oversized explanation' => [static fn (array $p): array => $flag($p, ['explanation' => str_repeat('x', 1001), 'items' => []]), 'review_flag.explanation'],
            'whitespace explanation' => [static fn (array $p): array => $flag($p, ['explanation' => "   \n\t ", 'items' => []]), 'review_flag.explanation'],
            'missing explanation' => [static fn (array $p): array => $flag($p, ['items' => []]), 'review_flag.explanation'],
            'unknown checklist item' => [static fn (array $p): array => $flag($p, ['explanation' => 'Look.', 'items' => [['compartment_id' => 'nope', 'item_id' => 'nope']]]), 'review_flag.items.0'],
            'item id from a different compartment' => [static fn (array $p): array => $flag($p, ['explanation' => 'Look.', 'items' => [
                ['compartment_id' => $p['compartments'][0]['id'], 'item_id' => $p['compartments'][1]['items'][0]['id']],
            ]]), 'review_flag.items.0'],
            'item without an id' => [static fn (array $p): array => $flag($p, ['explanation' => 'Look.', 'items' => [['compartment_id' => 'a']]]), 'review_flag.items.0.item_id'],
            'too many items' => [static fn (array $p): array => $flag($p, ['explanation' => 'Look.', 'items' => array_fill(0, 51, ['compartment_id' => 'a', 'item_id' => 'b'])]), 'review_flag.items'],
            'unexpected key inside the flag' => [static fn (array $p): array => $flag($p, ['explanation' => 'Look.', 'items' => [], 'severity' => 'critical']), 'review_flag'],
            'flag as a plain string' => [static fn (array $p): array => [...$p, 'review_flag' => 'please look'], 'review_flag'],
            'flag without processing version 1' => [static function (array $p): array {
                unset($p['processing_version']);

                return [...$p, 'review_flag' => ['explanation' => 'Look.', 'items' => []]];
            }, 'review_flag'],
            'non boolean significant flag' => [static function (array $p): array {
                $p['compartments'][0]['items'][0]['significant'] = 'maybe';

                return $p;
            }, 'compartments.0.items.0.significant'],
            'defect significance contradicts its checklist item' => [static function (array $p): array {
                $p['compartments'][0]['items'][0]['status'] = 'Damaged';
                $p['compartments'][0]['items'][0]['significant'] = true;
                $p['defects'][] = [
                    'compartment' => $p['compartments'][0]['name'], 'item' => $p['compartments'][0]['items'][0]['name'],
                    'status' => 'Damaged', 'notes' => null, 'significant' => false,
                ];

                return $p;
            }, 'defects.0.significant'],
        ];
    }

    public function test_a_review_flag_at_exactly_the_length_limit_is_accepted_and_held(): void
    {
        Queue::fake();
        Notification::fake();
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $payload['review_flag'] = ['explanation' => str_repeat('x', 1000), 'items' => [$this->itemRef($payload)]];

        $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review');
        $this->assertSame(1000, mb_strlen((string) ApparatusInspection::query()->sole()->review_flag_explanation));
    }

    // ------------------------------------------------------------- idempotency

    public function test_repeating_an_admin_action_with_the_same_idempotency_key_creates_one_event(): void
    {
        Queue::fake();
        Notification::fake();
        $defect = $this->openDefectFor($this->alpha);
        $actions = app(DefectCorrectiveActionService::class);

        $actions->acknowledge($defect->id, $this->reviewer, ['member_message' => 'Seen.'], 'key-ack');
        $actions->acknowledge($defect->id, $this->reviewer, ['member_message' => 'Seen.'], 'key-ack');
        $this->assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'acknowledged')->count());
        $this->assertSame(1, ApparatusIssueMessage::query()->where('apparatus_defect_id', $defect->id)->count());

        $actions->updateAction($defect->id, $this->reviewer, ['action_status' => 'repair_scheduled', 'member_message' => 'Booked.'], 'key-repair');
        $actions->updateAction($defect->id, $this->reviewer, ['action_status' => 'repair_scheduled', 'member_message' => 'Booked.'], 'key-repair');
        $this->assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'repair_scheduled')->count());

        // The same key on a different operation is a different action, not a silent no-op.
        $actions->classifyImpact($defect->id, $this->reviewer, ['operational_impact' => 'needs_repair'], 'key-ack');
        $this->assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'impact_classified')->count());

        $actions->resolve($defect->id, $this->reviewer, ['resolution_type' => 'other', 'resolution_notes' => 'Done'], 'key-resolve');
        $actions->resolve($defect->id, $this->reviewer, ['resolution_type' => 'other', 'resolution_notes' => 'Done'], 'key-resolve');
        $this->assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'resolved')->count());

        $this->expectException(ValidationException::class);
        $actions->acknowledge($defect->id, $this->reviewer, [], str_repeat('k', 49));
    }

    public function test_repeating_a_clarification_or_a_decision_does_not_duplicate_messages_or_events(): void
    {
        Queue::fake();
        Notification::fake();
        $inspection = $this->heldFlaggedInspection();
        $reviews = app(InspectionReviewService::class);
        $clientMessageId = '8d3f9d7c-1c1d-4b0e-9f0a-3b6f1b3f7e21';

        $reviews->requestClarification($inspection->id, $this->reviewer, 'Which gauge?', $clientMessageId);
        $reviews->requestClarification($inspection->id, $this->reviewer, 'Which gauge?', $clientMessageId);
        $this->assertSame(1, ApparatusIssueMessage::query()->where('apparatus_inspection_id', $inspection->id)->count());
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $inspection->id)->where('status', 'clarification_requested')->count());

        $reviews->approve($inspection->id, $this->reviewer, 'Fine.');
        $reviews->approve($inspection->id, $this->reviewer, 'Fine.');
        $reviews->reject($inspection->id, $this->reviewer, 'Too late, already approved.');
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $inspection->id)->where('status', 'approved')->count());
        $this->assertSame(0, ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $inspection->id)->where('status', 'rejected')->count());
        $this->assertSame('approved', $inspection->fresh()->review_status);
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{flagged: ApparatusInspection, rolledBack: ApparatusInspection, dismissed: ApparatusInspection, betaInspection: ApparatusInspection} */
    private function internalNoteWorld(): array
    {
        // Inspection 1 (alpha): missing battery AND a member flag, so it is held.
        $this->actAs($this->alpha);
        $one = $this->payload();
        $this->markItem($one, 0, 0, 'Missing', ['notes' => 'Battery gone.']);
        $one['review_flag'] = ['explanation' => 'Check the charger too.', 'items' => [$this->itemRef($one)]];
        $flagged = ApparatusInspection::query()->findOrFail($this->submit($one)->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'));
        $defect = ApparatusDefect::query()->sole();

        // Beta confirms the same issue (participant, own inspection).
        $this->travel(1)->days();
        $this->actAs($this->beta);
        $two = $this->payload(null, 1050, 405.0);
        $this->markItem($two, 0, 0, 'Missing', ['notes' => 'Still gone.']);
        $betaInspection = ApparatusInspection::query()->findOrFail($this->submit($two)->assertCreated()->json('id'));

        // Inspections 3 and 4 (alpha): mileage rollbacks, one rejected, one dismissed.
        $this->actAs($this->alpha);
        $rolledBack = ApparatusInspection::query()->findOrFail(
            $this->submit($this->payload(null, 900, 405.0))->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'),
        );
        $dismissed = ApparatusInspection::query()->findOrFail(
            $this->submit($this->payload(null, 950, 405.0))->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'),
        );

        $actions = app(DefectCorrectiveActionService::class);
        $messages = app(IssueMessageService::class);
        $reviews = app(InspectionReviewService::class);
        $actions->acknowledge($defect->id, $this->reviewer, ['member_message' => 'MEMBER-VISIBLE-ACK', 'internal_note' => 'INT-ACK-SECRET']);
        $actions->updateAction($defect->id, $this->reviewer, ['action_status' => 'replacement_available', 'member_message' => 'Replacement is ready.', 'internal_note' => 'INT-ACTION-SECRET']);
        $actions->classifyImpact($defect->id, $this->reviewer, ['operational_impact' => 'needs_repair', 'internal_note' => 'INT-CLASSIFY-SECRET']);
        $messages->post($this->reviewer, ['inspection_id' => $flagged->id, 'visibility' => 'internal', 'body' => 'INT-MSG-SECRET']);
        $messages->post($this->reviewer, ['defect_id' => $defect->id, 'visibility' => 'internal', 'body' => 'INT-DEFECT-MSG-SECRET']);
        $messages->post($this->reviewer, ['inspection_id' => $flagged->id, 'visibility' => 'member', 'body' => 'MEMBER-VISIBLE-NOTE']);
        $reviews->approve($flagged->id, $this->reviewer, 'INT-REVIEW-SECRET');

        $rollbackHold = ApparatusInspectionException::query()->where('apparatus_inspection_id', $rolledBack->id)->where('field', 'miles')->sole();
        app(ApparatusInspectionExceptionService::class)->reconcile($rollbackHold->id, $this->reviewer, [
            'action' => 'request_revision', 'reason' => 'Which odometer did you read?', 'expected_current_value' => 1050,
        ]);
        $reviews->reject($rolledBack->id, $this->reviewer, 'Please resubmit with the right odometer.', 'INT-REJECT-SECRET');

        $dismissHold = ApparatusInspectionException::query()->where('apparatus_inspection_id', $dismissed->id)->where('field', 'miles')->sole();
        app(ApparatusInspectionExceptionService::class)->reconcile($dismissHold->id, $this->reviewer, [
            'action' => 'dismiss', 'reason' => 'INT-RECONCILE-SECRET', 'expected_current_value' => 1050,
        ]);

        $actions->resolve($defect->id, $this->reviewer, ['resolution_type' => 'replacement_installed', 'resolution_notes' => 'Replacement installed.', 'internal_note' => 'INT-RESOLVE-SECRET']);

        return ['flagged' => $flagged, 'rolledBack' => $rolledBack, 'dismissed' => $dismissed, 'betaInspection' => $betaInspection];
    }

    private function assertNoInternalText(string $content, string $where): void
    {
        foreach (self::INTERNAL_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $content, "{$marker} leaked into {$where}.");
        }
    }

    private function assertDenied(callable $call): void
    {
        try {
            $call();
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The call should have been denied with an AuthorizationException.');
    }

    /** A processed inspection written directly (no HTTP session needed). */
    private function directInspection(User $owner, string $review = 'pending_review', ?string $processing = 'held_for_review'): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $this->unit->id, 'processed_apparatus_id' => $this->unit->id,
            'actor_user_id' => $owner->id, 'employee_id' => $owner->employee_profile_id,
            'operator_name' => $owner->name, 'rank' => 'Firefighter', 'shift' => 'A',
            'unit_number' => 'V-202', 'vehicle_number' => 'V-202', 'designation_at_time' => 'E2',
            'inspection_reference' => 'INS-DICF-'.random_int(1000, 9999),
            'review_status' => $review, 'processing_status' => $processing, 'results' => [], 'completed_at' => now(),
        ]);
    }

    private function heldFlaggedInspection(): ApparatusInspection
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $payload['review_flag'] = ['explanation' => 'Please look at the pump panel.', 'items' => [$this->itemRef($payload)]];

        return ApparatusInspection::query()->findOrFail(
            $this->submit($payload)->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'),
        );
    }

    /** @return array{inspection: ApparatusInspection, hold: ApparatusInspectionException} */
    private function heldRollbackInspection(): array
    {
        $this->actAs($this->alpha);
        $inspection = ApparatusInspection::query()->findOrFail(
            $this->submit($this->payload(null, 900, 401.2))->assertCreated()->assertJsonPath('processing_status', 'held_for_review')->json('id'),
        );

        return [
            'inspection' => $inspection,
            'hold' => ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->where('field', 'miles')->sole(),
        ];
    }

    private function openDefectFor(User $reporter): ApparatusDefect
    {
        $this->actAs($reporter);
        $payload = $this->payload(null, null, null);
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Battery gone.']);
        $this->submit($payload)->assertCreated();

        return ApparatusDefect::query()->latest('id')->firstOrFail();
    }

    /** @return array{inspection: ApparatusInspection, defect: ApparatusDefect} */
    private function alphaReportsBatteryAndBetaConfirms(): array
    {
        $this->actAs($this->alpha);
        $payload = $this->payload();
        $this->markItem($payload, 0, 0, 'Missing', ['notes' => 'Battery gone.']);
        $inspection = ApparatusInspection::query()->findOrFail($this->submit($payload)->assertCreated()->json('id'));
        $this->travel(1)->days();
        $this->actAs($this->beta);
        $again = $this->payload(null, 1040, 403.0);
        $this->markItem($again, 0, 0, 'Missing', ['notes' => 'Still gone.']);
        $this->submit($again)->assertCreated();

        return ['inspection' => $inspection, 'defect' => ApparatusDefect::query()->sole()];
    }
}
