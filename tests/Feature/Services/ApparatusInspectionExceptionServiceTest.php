<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusServiceTicket;
use App\Models\User;
use App\Services\ApparatusInspectionExceptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ApparatusInspectionExceptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Apparatus $apparatus;

    private ApparatusInspection $inspection;

    private User $member;

    private User $reviewer;

    private ApparatusInspectionExceptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
        config()->set('google_sheets.apparatus_sync_enabled', false);
        $this->member = User::factory()->create();
        $this->reviewer = User::factory()->create();
        $this->reviewer->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        Permission::findOrCreate('update_apparatus', 'web');
        $this->apparatus = Apparatus::query()->create([
            'unit_id' => 'E2', 'name' => 'Engine 2', 'type' => 'Engine', 'status' => 'In Service',
            'current_engine_hours' => 400, 'current_miles' => 1000,
        ]);
        $this->inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->apparatus->id, 'actor_user_id' => $this->member->id,
            'operator_name' => 'Original member', 'rank' => 'Firefighter', 'shift' => 'A',
            'engine_hours' => 300, 'miles' => 900, 'results' => [],
            'review_status' => 'approved', 'processing_status' => 'accepted_with_exception',
            'completed_at' => now(),
        ]);
        $this->service = app(ApparatusInspectionExceptionService::class);
    }

    public function test_accept_preserves_evidence_records_actor_and_before_after_and_is_idempotent(): void
    {
        $exception = $this->meterException('miles');
        $decision = ['action' => 'accept_submitted', 'reason' => 'Verified replacement odometer.', 'expected_current_value' => 1000];
        $this->service->reconcile($exception->id, $this->reviewer, $decision);
        $this->service->reconcile($exception->id, $this->reviewer, $decision);
        $this->assertSame(900, $this->apparatus->fresh()->current_miles);
        $this->assertSame(900, $this->inspection->fresh()->miles);
        $this->assertSame('900.0', $exception->fresh()->submitted_value);
        $this->assertSame('1000.0', $exception->fresh()->authoritative_value);
        $this->assertSame('accepted', $this->inspection->fresh()->processing_status);
        $event = ApparatusInspectionReviewEvent::sole();
        $this->assertSame($this->reviewer->id, $event->changed_by_user_id);
        $this->assertSame('Verified replacement odometer.', $event->internal_note);
        $this->assertSame(1000, $event->metadata['prior_authoritative_meters']['miles']);
        $this->assertSame(900, $event->metadata['result_authoritative_meters']['miles']);
        $this->assertNotNull($event->created_at);
    }

    public function test_stale_or_missing_expected_meter_blocks_every_decision_without_mutation(): void
    {
        $exception = $this->meterException();
        foreach (['accept_submitted', 'correct', 'dismiss', 'request_revision'] as $action) {
            foreach ([[], ['expected_current_value' => 399]] as $expected) {
                $this->assertValidationFailure(fn () => $this->service->reconcile($exception->id, $this->reviewer, [
                    'action' => $action, 'reason' => 'Reviewed reading.', 'value' => 450, ...$expected,
                ]));
            }
        }
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame('open', $exception->fresh()->status);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
    }

    public function test_outer_transaction_rollback_preserves_all_original_state(): void
    {
        $exception = $this->meterException();
        DB::beginTransaction();
        try {
            $this->service->reconcile($exception->id, $this->reviewer, [
                'action' => 'correct', 'reason' => 'Verified reading.', 'expected_current_value' => 400, 'value' => 450,
            ]);
            $this->assertSame('450.0', $this->apparatus->fresh()->current_engine_hours);
            $this->assertSame('resolved', $exception->fresh()->status);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame('open', $exception->fresh()->status);
        $this->assertSame('accepted_with_exception', $this->inspection->fresh()->processing_status);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_correction_checks_meter_precision_and_database_range_and_preserves_original(): void
    {
        $exception = $this->meterException();
        foreach ([-1, 10000000, 1.25, 'not a number', null] as $value) {
            $this->assertValidationFailure(fn () => $this->service->reconcile($exception->id, $this->reviewer, [
                'action' => 'correct', 'reason' => 'Correct a typo.', 'expected_current_value' => 400, 'value' => $value,
            ]));
        }
        $this->service->reconcile($exception->id, $this->reviewer, [
            'action' => 'correct', 'reason' => 'Correct a typo.', 'expected_current_value' => 400, 'value' => 450.5,
        ]);
        $this->assertSame('450.5', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame('300.0', $this->inspection->fresh()->engine_hours);
        $this->assertSame('300.0', $exception->fresh()->submitted_value);
    }

    public function test_null_baseline_requires_explicit_null_and_mileage_remains_integral(): void
    {
        $this->apparatus->update(['current_miles' => null]);
        $exception = $this->meterException('miles');
        foreach ([1.1, 2147483648] as $value) {
            $this->assertValidationFailure(fn () => $this->service->reconcile($exception->id, $this->reviewer, [
                'action' => 'correct', 'reason' => 'Verify baseline.', 'expected_current_value' => null, 'value' => $value,
            ]));
        }
        $this->service->reconcile($exception->id, $this->reviewer, [
            'action' => 'correct', 'reason' => 'Verify baseline.', 'expected_current_value' => null, 'value' => 100,
        ]);
        $this->assertSame(100, $this->apparatus->fresh()->current_miles);
    }

    public function test_only_authorized_reviewer_can_reconcile(): void
    {
        $exception = $this->meterException();
        try {
            $this->service->reconcile($exception->id, $this->member, ['action' => 'dismiss', 'reason' => 'Ignore it.', 'expected_current_value' => 400]);
            $this->fail('A normal member must not reconcile.');
        } catch (AuthorizationException) {
            $this->assertSame('open', $exception->fresh()->status);
            $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
        }
    }

    public function test_requested_owner_revision_is_append_only_and_still_requires_reconciliation(): void
    {
        $exception = $this->meterException();
        $revision = ['reason' => 'Read the meter again.', 'value' => 401.2];
        $this->assertValidationFailure(fn () => $this->service->submitRevision($exception->id, $this->member, $revision));
        $request = ['action' => 'request_revision', 'reason' => 'Please check the hours.', 'expected_current_value' => 400];
        $this->service->reconcile($exception->id, $this->reviewer, $request);
        $this->service->reconcile($exception->id, $this->reviewer, $request);
        try {
            $this->service->submitRevision($exception->id, $this->reviewer, $revision);
            $this->fail('Another actor must not revise the original member evidence.');
        } catch (AuthorizationException) {
            $this->assertSame('revision_requested', $exception->fresh()->status);
        }
        $this->service->submitRevision($exception->id, $this->member, $revision);
        $this->assertSame('revision_submitted', $exception->fresh()->status);
        $this->assertSame('accepted_with_exception', $this->inspection->fresh()->processing_status);
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame('300.0', $this->inspection->fresh()->engine_hours);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 2);
        $event = ApparatusInspectionReviewEvent::query()->latest('id')->firstOrFail();
        $this->assertSame($this->member->id, $event->changed_by_user_id);
        $this->assertSame(401.2, $event->metadata['revision']['value']);
        $this->service->submitRevision($exception->id, $this->member, $revision);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 2);
        $this->assertValidationFailure(fn () => $this->service->submitRevision($exception->id, $this->member, [
            ...$revision, 'value' => 402.2,
        ]));
        $this->service->reconcile($exception->id, $this->reviewer, [
            'action' => 'correct', 'reason' => 'Verified revised reading.', 'expected_current_value' => 400, 'value' => 401.2,
        ]);
        $this->assertSame('401.2', $this->apparatus->fresh()->current_engine_hours);
    }

    public function test_dismissal_does_not_resolve_a_real_defect_or_clear_other_exceptions(): void
    {
        [$exception, $defect] = $this->defectException();
        $this->meterException();
        $this->service->reconcile($exception->id, $this->reviewer, ['action' => 'dismiss', 'reason' => 'Duplicate classification request.']);
        $this->assertSame('dismissed', $exception->fresh()->status);
        $this->assertFalse($defect->fresh()->resolved);
        $this->assertSame('missing', $defect->fresh()->issue_type);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
        $this->assertSame('accepted_with_exception', $this->inspection->fresh()->processing_status);
    }

    public function test_classification_is_separate_from_issue_and_links_one_canonical_ticket(): void
    {
        [$exception, $defect] = $this->defectException();
        $data = ['action' => 'accept_submitted', 'reason' => 'Replace nonessential equipment.', 'operational_impact' => 'needs_repair',
            'service_ticket' => ['title' => 'Replace portable light', 'description' => 'Replace the missing portable light.', 'category' => 'other', 'priority' => 'routine']];
        $this->service->reconcile($exception->id, $this->reviewer, $data);
        $this->service->reconcile($exception->id, $this->reviewer, $data);
        $this->assertSame('needs_repair', $defect->fresh()->operational_impact);
        $this->assertSame('missing', $defect->fresh()->issue_type);
        $this->assertFalse($defect->fresh()->resolved);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
        $this->assertSame(ApparatusServiceTicket::sole()->id, $defect->fresh()->service_ticket_id);
        $this->assertDatabaseCount('apparatus_service_ticket_updates', 1);
    }

    public function test_out_of_service_requires_existing_apparatus_update_permission(): void
    {
        [$exception, $defect] = $this->defectException();
        $data = ['action' => 'accept_submitted', 'reason' => 'Critical equipment unavailable.', 'operational_impact' => 'out_of_service'];
        try {
            $this->service->reconcile($exception->id, $this->reviewer, $data);
            $this->fail('Review authority alone must not confer apparatus update permission.');
        } catch (AuthorizationException) {
            $this->assertSame('In Service', $this->apparatus->fresh()->status);
            $this->assertSame('unclassified', $defect->fresh()->operational_impact);
        }
        $this->reviewer->givePermissionTo('update_apparatus');
        $this->service->reconcile($exception->id, $this->reviewer, $data);
        $this->assertSame('Out of Service', $this->apparatus->fresh()->status);
        $this->assertSame('out_of_service', $defect->fresh()->operational_impact);
        $this->assertDatabaseHas('apparatus_operational_status_events', [
            'apparatus_id' => $this->apparatus->id, 'previous_status' => 'In Service', 'status' => 'Out of Service',
        ]);
    }

    public function test_failed_ticket_validation_rolls_back_classification_and_hold(): void
    {
        [$exception, $defect] = $this->defectException();
        $this->reviewer->givePermissionTo('update_apparatus');
        $this->assertValidationFailure(fn () => $this->service->reconcile($exception->id, $this->reviewer, [
            'action' => 'accept_submitted', 'reason' => 'Critical equipment unavailable.', 'operational_impact' => 'out_of_service',
            'service_ticket' => ['title' => 'bad'],
        ]));
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
        $this->assertSame('unclassified', $defect->fresh()->operational_impact);
        $this->assertSame('open', $exception->fresh()->status);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
        $this->assertDatabaseCount('apparatus_service_tickets', 0);
    }

    public function test_needs_admin_review_remains_pending_and_never_releases_existing_hold(): void
    {
        [$exception, $defect] = $this->defectException();
        $this->apparatus->update(['status' => 'Out of Service']);
        $this->service->reconcile($exception->id, $this->reviewer, [
            'action' => 'accept_submitted', 'reason' => 'Fleet must determine impact.', 'operational_impact' => 'needs_admin_review',
        ]);
        $this->assertSame('open', $exception->fresh()->status);
        $this->assertSame('accepted_with_exception', $this->inspection->fresh()->processing_status);
        $this->assertSame('Out of Service', $this->apparatus->fresh()->status);
        $this->assertSame('needs_admin_review', $defect->fresh()->operational_impact);
    }

    private function meterException(string $field = 'engine_hours'): ApparatusInspectionException
    {
        return ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $this->inspection->id, 'apparatus_id' => $this->apparatus->id,
            'field' => $field, 'reason' => 'rollback', 'submitted_value' => $field === 'miles' ? 900 : 300,
            'baseline_value' => $field === 'miles' ? 1000 : 400, 'authoritative_value' => $field === 'miles' ? 1000 : 400,
        ]);
    }

    /** @return array{ApparatusInspectionException, ApparatusDefect} */
    private function defectException(): array
    {
        $defect = ApparatusDefect::recordDefect($this->apparatus->id, 'Cab', 'Portable light', 'Missing', 'Not found.', null, $this->inspection->id);
        $exception = ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $this->inspection->id, 'apparatus_id' => $this->apparatus->id,
            'field' => 'defect:'.$defect->id, 'reason' => 'operational_impact_review', 'metadata' => ['defect_id' => $defect->id],
        ]);

        return [$exception, $defect];
    }

    private function assertValidationFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected validation to reject the decision.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
