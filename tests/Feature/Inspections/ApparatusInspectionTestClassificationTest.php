<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Filament\Resources\InspectionResource\Pages\ListInspections;
use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Jobs\AuditEquipmentAfterInspection;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusInspectionTestEvent;
use App\Models\User;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\Defects\DefectContinuityService;
use App\Services\Defects\DefectParticipants;
use App\Services\Inspections\ApparatusInspectionTestClassificationService;
use App\Services\Inspections\InspectionReviewService;
use App\Services\Inspections\IssueMessageService;
use App\Services\SnipeItService;
use App\Support\Fleet\ApparatusOverview;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ApparatusInspectionTestClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Apparatus $apparatus;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        Permission::findOrCreate('admin.fleet.manage', 'web');
        Permission::findOrCreate('admin.fleet.view', 'web');
        Permission::findOrCreate('admin.access', 'web');
        $this->manager = User::factory()->create(['account_status' => 'active']);
        $this->manager->givePermissionTo(['admin.access', 'admin.fleet.view', 'admin.fleet.manage']);
        $this->apparatus = Apparatus::create([
            'name' => 'Engine 1', 'unit_id' => 'E1', 'designation' => 'E1', 'type' => 'Engine',
            'vehicle_number' => 'TEST-101', 'status' => 'In Service',
            'current_engine_hours' => 100, 'current_miles' => 1000,
        ]);
    }

    public function test_classification_is_reversible_audited_and_does_not_rewrite_signed_observations(): void
    {
        $inspection = $this->inspection();
        $before = $inspection->getRawOriginal();
        $key = (string) Str::uuid();
        $service = app(ApparatusInspectionTestClassificationService::class);

        $service->exclude($inspection->id, $this->manager, 'Explicitly approved test submission.', $key);
        $service->exclude($inspection->id, $this->manager, 'Explicitly approved test submission.', $key);

        $this->assertSame(0, ApparatusInspection::query()->operational()->count());
        $this->assertSame(1, ApparatusInspection::query()->count());
        $this->assertSame(1, DB::table('apparatus_inspections')->count());
        $this->assertSame($inspection->id, ApparatusInspection::where('client_submission_id', $inspection->client_submission_id)->sole()->id);
        $this->assertDatabaseCount('apparatus_inspection_test_events', 1);
        $this->assertSame('Explicitly approved test submission.', $inspection->fresh()->test_exclusion_reason);
        foreach (['results', 'officer_signature', 'client_submission_id', 'submission_payload_hash', 'completed_at', 'review_status', 'processing_status'] as $field) {
            $this->assertSame($before[$field] ?? null, $inspection->fresh()->getRawOriginal($field), $field);
        }
        $this->assertSame(1000, $this->apparatus->fresh()->current_miles);

        $service->restore($inspection->id, $this->manager, 'User restored the retained inspection.', (string) Str::uuid());
        $this->assertSame(1, ApparatusInspection::query()->operational()->count());
        $this->assertSame(['excluded', 'restored'], $inspection->testClassificationEvents()->pluck('action')->all());
        $this->assertDatabaseCount('apparatus_inspection_test_events', 2);
        $service->exclude($inspection->id, $this->manager, 'Explicitly approved test submission.', $key);
        $this->assertNull($inspection->fresh()->test_excluded_at, 'Replaying the old exclusion must not undo a later restore.');
        $service->exclude($inspection->id, $this->manager, 'New approved exclusion after restore.', (string) Str::uuid());
        $this->assertNotNull($inspection->fresh()->test_excluded_at);
        $this->assertDatabaseCount('apparatus_inspection_test_events', 3);
    }

    public function test_latest_operational_inspection_skips_a_test_but_history_and_receipts_keep_it(): void
    {
        $earlier = $this->inspection(['completed_at' => now()->subDay()]);
        $test = $this->inspection(['completed_at' => now()]);
        app(ApparatusInspectionTestClassificationService::class)->exclude($test->id, $this->manager, 'Approved test.');

        $this->assertSame($earlier->id, ApparatusOverview::for($this->apparatus)->lastInspection?->id);
        $this->assertCount(2, $this->apparatus->inspections()->get());
        $this->assertSame($test->id, ApparatusInspection::findOrFail($test->id)->id);
        $this->assertSame($test->id, ApparatusInspection::where('client_submission_id', $test->client_submission_id)->sole()->id);
    }

    public function test_classification_refuses_meters_pending_effects_reviews_and_defect_dependencies(): void
    {
        $service = app(ApparatusInspectionTestClassificationService::class);
        $meter = $this->inspection(['miles' => 1001]);
        $pending = $this->inspection(['pending_effects' => ['defects' => [['item' => 'Radio', 'status' => 'Missing']]]]);
        $reviewed = $this->inspection();
        ApparatusInspectionReviewEvent::create(['apparatus_inspection_id' => $reviewed->id, 'previous_status' => 'pending_review', 'status' => 'approved']);
        $reported = $this->inspection();
        ApparatusDefect::withoutEvents(fn () => ApparatusDefect::create([
            'apparatus_id' => $this->apparatus->id, 'apparatus_inspection_id' => $reported->id,
            'compartment' => 'Cab', 'item' => 'Radio', 'issue_type' => 'missing', 'status' => 'open', 'resolved' => false,
        ]));

        foreach ([$meter, $pending, $reviewed, $reported] as $inspection) {
            try {
                $service->exclude($inspection->id, $this->manager, 'Requires separate impact review.');
                $this->fail('Dependent operational evidence must be reviewed before classification.');
            } catch (ValidationException) {
                $this->assertNull($inspection->fresh()->test_excluded_at);
            }
        }
        $this->assertDatabaseCount('apparatus_inspection_test_events', 0);
    }

    public function test_classification_requires_existing_fleet_authorization_and_a_reason(): void
    {
        $inspection = $this->inspection();
        try {
            app(ApparatusInspectionTestClassificationService::class)->exclude($inspection->id, User::factory()->create(), 'Unauthorized.');
            $this->fail('A member must not classify inspections.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(ValidationException::class);
        app(ApparatusInspectionTestClassificationService::class)->exclude($inspection->id, $this->manager, '');
    }

    public function test_classification_history_is_append_only(): void
    {
        $inspection = $this->inspection();
        app(ApparatusInspectionTestClassificationService::class)->exclude($inspection->id, $this->manager, 'Approved test.');
        $event = ApparatusInspectionTestEvent::sole();
        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_an_action_identifier_cannot_be_reused_with_a_different_reason_or_action(): void
    {
        $inspection = $this->inspection();
        $key = (string) Str::uuid();
        $service = app(ApparatusInspectionTestClassificationService::class);
        $service->exclude($inspection->id, $this->manager, 'Approved test.', $key);
        foreach ([['exclude', 'Changed reason.'], ['restore', 'Approved test.']] as [$method, $reason]) {
            try {
                $service->{$method}($inspection->id, $this->manager, $reason, $key);
                $this->fail('An existing action identifier must not authorize a different classification.');
            } catch (ValidationException) {
                $this->assertNotNull($inspection->fresh()->test_excluded_at);
            }
        }
        $this->assertDatabaseCount('apparatus_inspection_test_events', 1);
    }

    public function test_excluded_tests_have_explicit_admin_audit_access_without_appearing_in_the_default_list(): void
    {
        $operational = $this->inspection();
        $test = $this->inspection();
        app(ApparatusInspectionTestClassificationService::class)->exclude($test->id, $this->manager, 'Explicitly approved test.');
        $role = Role::findOrCreate('logistics_admin', 'web');
        foreach (['view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->manager->assignRole($role);
        $this->actingAs($this->manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ListInspections::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$operational])->assertCanNotSeeTableRecords([$test])
            ->filterTable('test_classification', 'test')
            ->assertCanSeeTableRecords([$test])->assertCanNotSeeTableRecords([$operational]);
        Livewire::test(ViewInspection::class, ['record' => $test->getRouteKey()])
            ->assertSuccessful()->assertSee('Excluded test')->assertSee('Explicitly approved test.');
        $this->get(route('employee.vehicle-inspections.pdf', $test))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_excluded_inspections_cannot_be_used_for_new_operational_work_even_from_a_stale_model(): void
    {
        $inspection = $this->inspection();
        app(ApparatusInspectionTestClassificationService::class)->exclude($inspection->id, $this->manager, 'Approved test.');
        $defect = ApparatusDefect::withoutEvents(fn () => ApparatusDefect::create([
            'apparatus_id' => $this->apparatus->id, 'compartment' => 'Cab', 'item' => 'Unrelated radio',
            'issue_type' => 'missing', 'status' => 'open', 'resolved' => false,
        ]));
        $operations = [
            fn () => app(ApparatusInspectionProcessingService::class)->process($inspection, $this->apparatus, null),
            fn () => app(InspectionReviewService::class)->approve($inspection->id, $this->manager),
            fn () => app(InspectionReviewService::class)->requestClarification($inspection->id, $this->manager, 'New question.'),
            fn () => app(IssueMessageService::class)->post($this->manager, ['inspection_id' => $inspection->id, 'body' => 'New work.', 'visibility' => 'internal']),
            fn () => app(DefectContinuityService::class)->recordFindings($inspection, $this->apparatus, [], [], []),
            fn () => app(DefectParticipants::class)->enroll($defect, $this->manager, 'reported', $inspection),
        ];
        foreach ($operations as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('An excluded test must not create new operational work.');
            } catch (ValidationException) {
                $this->assertNotNull($inspection->fresh()->test_excluded_at);
            }
        }
        $this->assertDatabaseCount('apparatus_issue_messages', 0);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
        $this->assertDatabaseCount('apparatus_defect_observations', 0);
        $this->assertDatabaseCount('apparatus_defect_participants', 0);
        $this->assertSame(1000, $this->apparatus->fresh()->current_miles);
    }

    public function test_a_queued_snipe_audit_does_not_certify_a_classified_test_on_retry(): void
    {
        $this->apparatus->update(['snipeit_asset_id' => 123]);
        $inspection = $this->inspection();
        app(ApparatusInspectionTestClassificationService::class)->exclude($inspection->id, $this->manager, 'Approved test.');
        $snipe = Mockery::mock(SnipeItService::class);
        $snipe->shouldNotReceive('getAssetsCheckedOutTo');

        (new AuditEquipmentAfterInspection($inspection->id, $this->apparatus->id))->handle($snipe);

        $this->assertNotNull($inspection->fresh()->officer_signature);
        $this->assertDatabaseCount('apparatus_inspections', 1);
    }

    private function inspection(array $attributes = []): ApparatusInspection
    {
        return ApparatusInspection::create(array_merge([
            'apparatus_id' => $this->apparatus->id, 'operator_name' => 'Test Inspector', 'rank' => 'Captain',
            'review_status' => 'approved', 'processing_status' => null, 'completed_at' => now(),
            'client_submission_id' => (string) Str::uuid(), 'submission_payload_hash' => str_repeat('a', 64),
            'officer_signature' => 'inspection-signatures/retained-source.png',
            'results' => [['id' => 'cab', 'name' => 'Cab', 'items' => [['id' => 'radio', 'name' => 'Radio', 'status' => 'Present']]]],
        ], $attributes));
    }
}
