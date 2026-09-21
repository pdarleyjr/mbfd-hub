<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ApparatusInspectionExceptionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Apparatus $apparatus;

    private ApparatusInspection $inspection;

    private ApparatusInspectionException $exception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        Queue::fake();
        config()->set('google_sheets.apparatus_sync_enabled', false);
        $this->owner = User::factory()->create();
        $this->apparatus = Apparatus::query()->create([
            'unit_id' => 'E2', 'vehicle_number' => 'V-202', 'name' => 'Engine 2', 'type' => 'Engine',
            'status' => 'In Service', 'current_engine_hours' => 400, 'current_miles' => 1000,
            'vin' => 'PRIVATE-VIN', 'notes' => 'PRIVATE-FLEET-NOTE',
        ]);
        $this->inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->apparatus->id, 'actor_user_id' => $this->owner->id,
            'operator_name' => 'PRIVATE-PERSONNEL-NAME', 'rank' => 'Firefighter', 'shift' => 'A',
            'engine_hours' => 300, 'miles' => 900, 'results' => ['signature' => 'PRIVATE-SIGNATURE-PATH'],
            'review_status' => 'approved', 'processing_status' => 'accepted_with_exception', 'completed_at' => now(),
        ]);
        $this->exception = $this->makeException($this->inspection);
        $this->reviewEvent($this->exception, 'request_revision', 'Please recheck the hours.');
    }

    public function test_member_list_is_owner_scoped_and_allowlisted_with_exception_specific_reviewer_note(): void
    {
        $submitted = $this->makeException($this->inspection, 'revision_submitted', 'miles');
        $this->reviewEvent($submitted, 'request_revision', 'Recheck this second reading.');
        $this->reviewEvent($submitted, 'member_revision', 'PRIVATE-OTHER-EVENT-NOTE');
        foreach (['open', 'resolved', 'dismissed'] as $status) {
            $closedInspection = $this->inspection->replicate();
            $closedInspection->save();
            $this->makeException($closedInspection, $status);
        }
        $foreign = $this->inspection->replicate();
        $foreign->actor_user_id = User::factory()->create()->id;
        $foreign->save();
        $this->makeException($foreign);
        $legacy = $this->inspection->replicate();
        $legacy->actor_user_id = null;
        $legacy->save();
        $this->makeException($legacy);
        $this->actingAsCanonicalUser($this->owner);

        $response = $this->getJson('/api/public/inspection-revisions')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$submitted->id, $this->exception->id], array_column($response->json('data'), 'id'));
        $row = $response->json('data.1');
        $this->assertSame(['id', 'status', 'field', 'reason', 'submitted_value', 'current_value', 'reviewer_note', 'apparatus'], array_keys($row));
        $this->assertSame(['id', 'vehicle_number', 'name', 'unit_id'], array_keys($row['apparatus']));
        $this->assertSame('Please recheck the hours.', $row['reviewer_note']);
        $this->assertSame('300.0', $row['submitted_value']);
        $this->assertSame('400.0', $row['current_value']);
        $this->assertSame('V-202', $row['apparatus']['vehicle_number']);
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_owner_revision_and_exact_retry_return_only_receipt_and_preserve_original_evidence(): void
    {
        $this->actingAsCanonicalUser($this->owner);
        $payload = ['reason' => 'Rechecked the meter.', 'value' => 401.2, 'actor_user_id' => 999, 'status' => 'resolved'];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($this->revisionUrl(), $payload)->assertOk()
                ->assertExactJson(['id' => $this->exception->id, 'status' => 'revision_submitted']);
        }
        $this->assertDatabaseCount('apparatus_inspection_review_events', 2);
        $event = ApparatusInspectionReviewEvent::query()->latest('id')->firstOrFail();
        $this->assertSame($this->owner->id, $event->changed_by_user_id);
        $this->assertSame(['reason' => 'Rechecked the meter.', 'value' => 401.2], $event->metadata['revision']);
        $this->assertSame('300.0', $this->inspection->fresh()->engine_hours);
        $this->assertSame('300.0', $this->exception->fresh()->submitted_value);
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame('accepted_with_exception', $this->inspection->fresh()->processing_status);
        $this->postJson($this->revisionUrl(), ['reason' => 'Different revision.', 'value' => 402])->assertUnprocessable();
        $this->assertDatabaseCount('apparatus_inspection_review_events', 2);
    }

    public function test_another_canonical_member_cannot_list_or_revise_the_owner_evidence(): void
    {
        $this->actingAsCanonicalUser(User::factory()->create());
        $this->getJson('/api/public/inspection-revisions')->assertOk()->assertExactJson(['data' => []]);
        $this->postJson($this->revisionUrl(), ['reason' => 'Spoof owner.', 'value' => 401, 'actor_user_id' => $this->owner->id])->assertForbidden();
        $this->assertSame('revision_requested', $this->exception->fresh()->status);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
    }

    public function test_owner_cannot_revise_without_request_and_invalid_reading_has_no_effect(): void
    {
        $this->actingAsCanonicalUser($this->owner);
        $this->postJson($this->revisionUrl(), ['reason' => 'Invalid precision.', 'value' => 401.25])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->exception->update(['status' => 'open']);
        $this->postJson($this->revisionUrl(), ['reason' => 'No reviewer request.', 'value' => 401])->assertUnprocessable();
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
    }

    public function test_anonymous_and_unregistered_web_sessions_cannot_access_any_endpoint(): void
    {
        $this->assertAllEndpointsUnauthenticated();
        $reviewer = $this->reviewer();
        $this->actingAs($reviewer, 'web');
        $this->assertAllEndpointsUnauthenticated();
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
    }

    public function test_revoked_canonical_session_cannot_read_or_write(): void
    {
        $reviewer = $this->reviewer();
        $this->actingAsCanonicalUser($reviewer);
        $reviewer->increment('security_version');
        $this->assertAllEndpointsUnauthenticated();
        $this->assertSame('revision_requested', $this->exception->fresh()->status);
    }

    public function test_reconciliation_requires_current_admin_access_and_fleet_manage_capability(): void
    {
        $this->actingAsCanonicalUser($this->owner);
        $this->postJson($this->reconcileUrl(), $this->decision())->assertForbidden();
        $reviewer = User::factory()->create();
        $reviewer->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        $reviewer->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $this->actingAsCanonicalUser($reviewer);
        $this->postJson($this->reconcileUrl(), $this->decision())->assertForbidden();
        $reviewer->revokePermissionTo(Permission::findByName('admin.access', 'web'));
        $reviewer->givePermissionTo(Permission::findOrCreate('admin.fleet.manage', 'web'));
        $this->actingAsCanonicalUser($reviewer);
        $this->postJson($this->reconcileUrl(), $this->decision())->assertForbidden();
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
    }

    public function test_authorized_reconciliation_checks_staleness_and_retries_without_repeated_effects(): void
    {
        $reviewer = $this->reviewer();
        $this->actingAsCanonicalUser($reviewer);
        $this->postJson($this->reconcileUrl(), [...$this->decision(), 'expected_current_value' => 399])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_current_value');
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($this->reconcileUrl(), $this->decision())->assertOk()
                ->assertExactJson(['id' => $this->exception->id, 'status' => 'resolved']);
        }
        $this->assertSame('450.5', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame('300.0', $this->inspection->fresh()->engine_hours);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 2);
        $this->assertSame($reviewer->id, ApparatusInspectionReviewEvent::query()->latest('id')->firstOrFail()->changed_by_user_id);
    }

    private function reviewer(): User
    {
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.fleet.manage', 'web'),
        ]);

        return $reviewer;
    }

    private function makeException(ApparatusInspection $inspection, string $status = 'revision_requested', string $field = 'engine_hours'): ApparatusInspectionException
    {
        return ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_id' => $this->apparatus->id,
            'field' => $field, 'reason' => 'rollback', 'submitted_value' => 300,
            'baseline_value' => 400, 'authoritative_value' => 400, 'status' => $status,
            'metadata' => ['secret' => 'PRIVATE-METADATA'],
        ]);
    }

    private function reviewEvent(ApparatusInspectionException $exception, string $action, string $note): void
    {
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $exception->apparatus_inspection_id,
            'previous_status' => 'open', 'status' => 'revision_requested',
            'changed_by_user_id' => $this->owner->id, 'internal_note' => $note,
            'metadata' => ['action' => $action, 'exception_id' => $exception->id, 'secret' => 'PRIVATE-EVENT-METADATA'],
        ]);
    }

    private function revisionUrl(): string
    {
        return "/api/public/inspection-exceptions/{$this->exception->id}/revision";
    }

    private function reconcileUrl(): string
    {
        return "/api/apparatus-inspection-exceptions/{$this->exception->id}/reconcile";
    }

    private function decision(): array
    {
        return ['action' => 'correct', 'reason' => 'Verified reading.', 'expected_current_value' => 400, 'value' => 450.5];
    }

    private function assertAllEndpointsUnauthenticated(): void
    {
        $this->getJson('/api/public/inspection-revisions')->assertUnauthorized();
        $this->postJson($this->revisionUrl(), ['reason' => 'Recheck.', 'value' => 401])->assertUnauthorized();
        $this->postJson($this->reconcileUrl(), $this->decision())->assertUnauthorized();
    }
}
