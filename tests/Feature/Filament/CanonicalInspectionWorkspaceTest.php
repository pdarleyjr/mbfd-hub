<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusInspectionExceptionResource\Pages\ListInspectionExceptions;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\InspectionsRelationManager;
use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Filament\Resources\StationResource\Pages\ViewStation;
use App\Filament\Resources\StationResource\RelationManagers\DailyCheckoutsRelationManager;
use App\Filament\Support\InspectionWorkspace;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\Station;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Notifications\NewSubmissionNotification;
use App\Services\Inspections\InspectionNotificationDispatcher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CanonicalInspectionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Apparatus $apparatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('2026-10-21 14:00:00');
        $this->station = Station::query()->create([
            'station_number' => 1, 'name' => 'Station 1', 'address' => '1051 Jefferson Avenue', 'is_active' => true,
        ]);
        $this->apparatus = $this->makeApparatus('E1', '1001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ routes */

    public function test_every_entry_point_links_to_the_canonical_inspection_route(): void
    {
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $inspection = $this->inspection();
        $defect = $this->defect(['first_reported_inspection_id' => $inspection->id]);
        $exception = $this->exception($inspection, ['field' => 'miles', 'reason' => 'rollback', 'blocks_acceptance' => true]);
        $canonical = route('filament.admin.resources.inspections.view', ['record' => $inspection->id]);
        $this->actAs($this->userWithRole('super_admin'));

        $this->assertSame($canonical, InspectionWorkspace::url($inspection));
        $this->assertSame($canonical.'#issue-9', InspectionWorkspace::url($inspection, 'issue-9'));

        $apparatusRows = Livewire::test(InspectionsRelationManager::class, [
            'ownerRecord' => $this->apparatus, 'pageClass' => ViewApparatus::class, 'lazy' => false,
        ])->call('loadTable');
        $this->assertSame($canonical, $apparatusRows->instance()->getTable()->getRecordUrl($inspection));
        $apparatusRows->assertTableActionHasUrl('view_results', $canonical, $inspection);

        Livewire::test(DailyCheckoutsRelationManager::class, [
            'ownerRecord' => $this->station, 'pageClass' => ViewStation::class, 'lazy' => false,
        ])->call('loadTable')->assertTableActionHasUrl('view_results', $canonical, $inspection);

        Livewire::test(ListInspectionExceptions::class)->call('loadTable')
            ->assertTableActionHasUrl('inspection', $canonical.'#hold-'.$exception->id, $exception);

        Livewire::test(ListDefects::class)->call('loadTable')
            ->assertTableActionHasUrl('source_inspection', $canonical.'#issue-'.$defect->id, $defect);

        $overview = Livewire::test(ViewApparatus::class, ['record' => $this->apparatus->getRouteKey()]);
        $this->assertStringContainsString('href="'.$canonical.'"', $overview->html());

        $checkouts = app(StationOperationsHubWidget::class)->getViewData()['department']['checkouts'];
        $this->assertSame($canonical, $checkouts[0]['url']);
    }

    public function test_a_new_checkout_alerts_only_reviewers_who_have_not_turned_it_off_and_reviewer_alerts_link_to_the_canonical_page(): void
    {
        Notification::fake();
        // A reviewer who turned "Every Daily Checkout" off keeps hearing nothing per checkout, whatever their old
        // `vehicle_inspections` row says. The category is in-app on by default (tests/Feature/Notifications/EveryDailyCheckoutAlertTest.php).
        $recipient = $this->userWithRole('super_admin');
        UserNotificationSubscription::query()->create([
            'user_id' => $recipient->id,
            'event_key' => User::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS,
            'database_enabled' => true, 'webpush_enabled' => false, 'email_enabled' => false,
        ]);
        UserNotificationSubscription::query()->create([
            'user_id' => $recipient->id,
            'event_key' => User::NOTIFICATION_PREFERENCE_DAILY_CHECKOUT_EVERY_SUBMISSION,
            'database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => false,
        ]);

        // A reviewer who never chose anything gets the in-app default.
        $optedIn = $this->userWithRole('super_admin');

        $inspection = $this->inspection();

        Notification::assertNotSentTo($recipient, NewSubmissionNotification::class);
        Notification::assertSentTo($optedIn, NewSubmissionNotification::class);
        // The alerts reviewers do get are built by the dispatcher and open the canonical page.
        $this->assertSame(
            route('filament.admin.resources.inspections.view', ['record' => $inspection->id], false),
            app(InspectionNotificationDispatcher::class)->adminUrl($inspection->id),
        );
    }

    public function test_the_apparatus_scoped_url_redirects_to_the_canonical_page(): void
    {
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $inspection = $this->inspection();
        $canonical = route('filament.admin.resources.inspections.view', ['record' => $inspection->id]);
        $this->actAs($this->userWithRole('super_admin'));

        $this->get(route('filament.admin.resources.apparatuses.view-inspection', [
            'record' => $this->apparatus->id,
            'inspection' => $inspection->id,
        ]))->assertRedirect($canonical);

        Livewire::test(\App\Filament\Resources\ApparatusResource\Pages\ViewInspection::class, [
            'record' => $this->apparatus, 'inspection' => $inspection,
        ])->assertRedirect($canonical);
    }

    public function test_the_apparatus_scoped_url_still_denies_a_user_without_apparatus_access(): void
    {
        $inspection = $this->inspection();
        $this->actAs($this->userWithPermissions('list_only', ['view_any_apparatus']));

        Livewire::test(\App\Filament\Resources\ApparatusResource\Pages\ViewInspection::class, [
            'record' => $this->apparatus, 'inspection' => $inspection,
        ])->assertForbidden();
    }

    public function test_a_user_without_inspection_access_is_denied_the_canonical_page(): void
    {
        $inspection = $this->inspection();
        $this->actAs($this->userWithPermissions('logistics_admin', []));

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])->assertForbidden();
    }

    /* ----------------------------------------------------------- sections */

    public function test_the_header_shows_physical_vehicle_member_time_status_and_counts(): void
    {
        $physical = $this->makeApparatus('E2', '1002');
        $inspection = $this->inspection([
            'processed_apparatus_id' => $physical->id,
            'processing_status' => 'held_for_review',
            'review_status' => 'pending_review',
            'inspection_reference' => 'INS-HEADER-01',
            'completed_at' => '2026-10-21 13:30:00',
        ]);
        $this->exception($inspection, ['field' => 'miles', 'reason' => 'rollback', 'blocks_acceptance' => true]);
        $this->defect(['apparatus_id' => $physical->id, 'apparatus_inspection_id' => $inspection->id]);
        $this->actAs($this->reviewer());

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('INS-HEADER-01')
            ->assertSee('Vehicle #1002')
            ->assertDontSee('Vehicle #1001')
            ->assertSee('Oct 21, 2026 9:30 AM EDT')
            ->assertSee('Pat Rivera')
            ->assertSee('Lieutenant')
            ->assertSee('Needs approval')
            ->assertSee('Station 1')
            ->assertSeeHtml('data-open-holds="1"')
            ->assertSeeHtml('data-open-issues="1"')
            ->assertDontSee('Employee ID');
    }

    public function test_employee_id_is_shown_only_to_viewers_who_can_view_employees(): void
    {
        $inspection = $this->inspection();
        $this->actAs($this->userWithPermissions('personnel_reader', [
            'view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection', 'admin.members.view',
        ]));

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Employee ID');
    }

    public function test_a_meter_rollback_hold_card_explains_the_decision(): void
    {
        $previous = $this->inspection(['inspection_reference' => 'INS-PREVIOUS-9', 'completed_at' => '2026-10-20 12:00:00', 'miles' => 10000]);
        $held = $this->inspection([
            'processing_status' => 'held_for_review', 'review_status' => 'pending_review',
            'miles' => 9000, 'completed_at' => '2026-10-21 12:00:00',
        ]);
        $this->exception($held, [
            'field' => 'miles', 'reason' => 'rollback', 'blocks_acceptance' => true,
            'submitted_value' => 9000, 'baseline_value' => 9500, 'authoritative_value' => 10000,
            'metadata' => [
                'previous_inspection_id' => $previous->id,
                'previous_reading_at' => '2026-10-20 12:00:00',
                'explanation' => 'Odometer was replaced after the dash cluster failed.',
            ],
        ]);
        $this->actAs($this->reviewer());

        $page = Livewire::test(ViewInspection::class, ['record' => $held->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Meter reading hold: Odometer miles')
            ->assertSee('Needs a decision')
            ->assertSee('10,000 mi')
            ->assertSee('9,000 mi')
            ->assertSee('-1,000 mi')
            ->assertSee('9,500 mi')
            ->assertSee('INS-PREVIOUS-9')
            ->assertSee('Oct 20, 2026 8:00 AM EDT')
            ->assertSee('Pat Rivera')
            ->assertSee('Odometer was replaced after the dash cluster failed.');

        $this->assertStringContainsString(
            route('filament.admin.resources.inspections.view', ['record' => $previous->id]),
            $page->html(),
        );
    }

    public function test_member_flag_and_significant_damage_cards_show_their_evidence(): void
    {
        $inspection = $this->inspection([
            'processing_status' => 'held_for_review', 'review_status' => 'pending_review',
            'review_flag_explanation' => 'The compartment latch felt loose all week.',
            'review_flag_items' => [['compartment_id' => 'cab', 'item_id' => 'cab-1', 'compartment' => 'Cab', 'item' => 'Portable radio']],
        ]);
        $damaged = $this->defect(['item' => 'Rescue saw', 'issue_type' => 'damaged', 'severity' => 'significant', 'apparatus_inspection_id' => $inspection->id]);
        $otherApparatus = $this->makeApparatus('E9', '1009');
        $foreign = $this->defect(['apparatus_id' => $otherApparatus->id, 'item' => 'Foreign pry bar']);
        $this->exception($inspection, ['field' => 'member_review_flag', 'reason' => 'member_flagged', 'blocks_acceptance' => true]);
        $this->exception($inspection, [
            'field' => 'significant_damage', 'reason' => 'new_significant_damage', 'blocks_acceptance' => true,
            'metadata' => ['defect_ids' => [$damaged->id, $foreign->id]],
        ]);
        $this->actAs($this->reviewer());

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Flagged by the member for further review')
            ->assertSee('The compartment latch felt loose all week.')
            ->assertSee('Cab / Portable radio')
            ->assertSee('New significant damage reported')
            ->assertSee('Rescue saw')
            ->assertSee('Significant damage')
            ->assertDontSee('Foreign pry bar');
    }

    public function test_one_card_per_defect_with_an_anchor_first_report_and_observation_trail(): void
    {
        $reporter = User::factory()->create(['name' => 'Jordan Ellis']);
        $first = $this->inspection(['inspection_reference' => 'INS-FIRST', 'completed_at' => '2026-10-18 12:00:00']);
        $second = $this->inspection(['inspection_reference' => 'INS-SECOND', 'completed_at' => '2026-10-19 12:00:00']);
        $third = $this->inspection(['inspection_reference' => 'INS-THIRD', 'completed_at' => '2026-10-20 12:00:00']);
        $defect = $this->defect([
            'item' => 'Thermal camera', 'issue_type' => 'missing', 'apparatus_inspection_id' => $first->id,
            'severity' => 'significant', 'action_status' => 'replacement_available',
            'first_reported_inspection_id' => $first->id, 'first_reported_by_user_id' => $reporter->id,
            'first_reported_at' => '2026-10-18 12:00:00',
        ]);
        foreach ([[$first, 'first_reported'], [$second, 'confirmed_again'], [$third, 'confirmed_again']] as [$inspection, $kind]) {
            ApparatusDefectObservation::query()->create([
                'apparatus_defect_id' => $defect->id, 'apparatus_inspection_id' => $inspection->id,
                'observation' => $kind, 'reported_status' => 'Missing', 'notes' => 'Still not on the rig.',
            ]);
        }
        $this->actAs($this->reviewer());

        $page = Livewire::test(ViewInspection::class, ['record' => $third->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('id="issue-'.$defect->id.'"')
            ->assertSee('Thermal camera')
            ->assertSee('Replacement available')
            ->assertSee('Significant damage')
            ->assertSee('Jordan Ellis')
            ->assertSee('Oct 18, 2026 8:00 AM')
            ->assertSee('Observed in')
            ->assertSee('3 inspections')
            ->assertSee('Confirmed again')
            ->assertSee('INS-FIRST')
            ->assertSee('INS-SECOND')
            ->assertSee('Still not on the rig.');

        $this->assertSame(1, preg_match_all('/data-defect-card[\s>]/', $page->html()));
        $this->assertStringContainsString(
            route('filament.admin.resources.inspections.view', ['record' => $first->id]).'#issue-'.$defect->id,
            $page->html(),
        );
    }

    public function test_the_checklist_is_shown_as_submitted_never_as_the_current_defect_state(): void
    {
        $inspection = $this->inspection(['results' => [
            ['name' => 'Cab', 'items' => [
                ['name' => 'Portable radio', 'status' => 'Missing', 'notes' => 'Not in the assigned mount.'],
                ['name' => 'Flashlight', 'status' => 'Present'],
            ]],
            ['name' => 'Rear compartment', 'items' => [['name' => 'Halligan', 'status' => 'Damaged', 'notes' => 'Bent tip.']]],
        ]]);
        $this->defect(['apparatus_inspection_id' => $inspection->id, 'action_status' => 'resolved']);
        $this->actAs($this->reviewer());

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Original checklist')
            ->assertSee('Rear compartment')
            ->assertSee('Not in the assigned mount.')
            ->assertSee('Missing')
            ->assertSee('Damaged')
            ->assertSee('Pass')
            ->assertSee('Bent tip.');
    }

    public function test_the_timeline_merges_history_in_order_and_labels_internal_notes(): void
    {
        $admin = $this->reviewer();
        $inspection = $this->inspection(['completed_at' => '2026-10-21 12:00:00']);
        $defect = $this->defect(['item' => 'Portable radio', 'apparatus_inspection_id' => $inspection->id]);

        $this->at('2026-10-21 13:00:00');
        ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $defect->id, 'apparatus_inspection_id' => $inspection->id, 'actor_user_id' => $admin->id,
            'event_type' => 'acknowledged', 'member_message' => 'We ordered a replacement radio.', 'internal_note' => 'PO 4471 with vendor.',
        ]);
        $this->at('2026-10-21 14:00:00');
        ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_defect_id' => $defect->id, 'author_user_id' => $admin->id,
            'visibility' => 'internal', 'body' => 'Vendor says two weeks.',
        ]);
        $this->at('2026-10-21 15:00:00');
        ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_defect_id' => $defect->id, 'author_user_id' => $admin->id,
            'visibility' => 'member', 'body' => 'Use the spare radio from Station 2.',
        ]);
        $this->at('2026-10-21 16:00:00');
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'previous_status' => 'pending_review', 'status' => 'approved',
            'internal_note' => 'Reviewed and accepted.', 'changed_by_user_id' => $admin->id, 'metadata' => [],
        ]);
        $this->actAs($admin);

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeInOrder([
                'Inspection submitted',
                'Acknowledged: Portable radio',
                'Message to member: We ordered a replacement radio.',
                'Internal note on Portable radio',
                'PO 4471 with vendor.',
                'Internal note',
                'Vendor says two weeks.',
                'Message visible to the member',
                'Use the spare radio from Station 2.',
                'Review: Pending Review to Approved',
                'Reviewed and accepted.',
            ]);
    }

    public function test_internal_notes_are_hidden_from_viewers_who_cannot_review(): void
    {
        $author = User::factory()->create();
        $inspection = $this->inspection();
        $defect = $this->defect(['apparatus_inspection_id' => $inspection->id]);
        ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_defect_id' => $defect->id, 'author_user_id' => $author->id,
            'visibility' => 'internal', 'body' => 'Do not share the vendor pricing.',
        ]);
        ApparatusIssueMessage::query()->create([
            'apparatus_inspection_id' => $inspection->id, 'apparatus_defect_id' => $defect->id, 'author_user_id' => $author->id,
            'visibility' => 'member', 'body' => 'Replacement arrives Friday.',
        ]);
        $this->actAs($this->userWithPermissions('view_only_inspections', ['view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection']));

        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Replacement arrives Friday.')
            ->assertDontSee('Do not share the vendor pricing.');
    }

    public function test_vehicle_history_lists_the_last_ten_inspections_of_the_physical_vehicle(): void
    {
        $physical = $this->makeApparatus('E2', '1002');
        foreach (range(1, 11) as $day) {
            $this->inspection([
                'processed_apparatus_id' => $physical->id,
                'inspection_reference' => sprintf('INS-E2-%02d', $day),
                'completed_at' => sprintf('2026-10-%02d 12:00:00', $day),
            ], $this->apparatus);
        }
        $this->inspection(['inspection_reference' => 'INS-OTHER-VEHICLE', 'completed_at' => '2026-10-15 12:00:00']);
        $current = ApparatusInspection::query()->where('inspection_reference', 'INS-E2-11')->sole();
        $this->actAs($this->reviewer());

        $page = Livewire::test(ViewInspection::class, ['record' => $current->getRouteKey()])->assertSuccessful();

        $page->assertSee('INS-E2-11')->assertSee('INS-E2-02')->assertSee('This inspection')
            ->assertDontSee('INS-E2-01')
            ->assertDontSee('INS-OTHER-VEHICLE');
        $this->assertStringContainsString(
            route('filament.admin.resources.inspections.view', ['record' => ApparatusInspection::query()->where('inspection_reference', 'INS-E2-05')->value('id')]),
            $page->html(),
        );
    }

    /* ------------------------------------------------------------ actions */

    public function test_approve_and_reject_are_hidden_for_accepted_inspections(): void
    {
        $accepted = $this->inspection();
        $held = $this->inspection(['processing_status' => 'held_for_review', 'review_status' => 'pending_review']);
        $this->actAs($this->reviewer());

        Livewire::test(ViewInspection::class, ['record' => $accepted->getRouteKey()])
            ->assertActionHidden('approveInspection')->assertActionHidden('rejectInspection')
            ->assertActionVisible('print')->assertActionVisible('downloadPdf');
        Livewire::test(ViewInspection::class, ['record' => $held->getRouteKey()])
            ->assertActionVisible('approveInspection')->assertActionVisible('rejectInspection');
    }

    /* ---------------------------------------------------------- dashboard */

    public function test_dashboard_groups_inspections_and_counts_each_issue_once(): void
    {
        $held = $this->inspection(['processing_status' => 'held_for_review', 'review_status' => 'pending_review']);
        $this->exception($held, ['field' => 'miles', 'reason' => 'rollback', 'blocks_acceptance' => true]);
        $this->exception($held, ['field' => 'member_review_flag', 'reason' => 'member_flagged', 'blocks_acceptance' => true]);
        $legacy = $this->inspection(['processing_status' => null, 'review_status' => 'pending_review']);
        $accepted = $this->inspection(['completed_at' => '2026-10-20 12:00:00']);
        $old = $this->inspection(['completed_at' => '2026-09-01 12:00:00']);

        $recurring = $this->defect(['item' => 'Thermal camera', 'apparatus_inspection_id' => $accepted->id, 'first_reported_inspection_id' => $accepted->id]);
        foreach ([$accepted, $old, $held] as $inspection) {
            ApparatusDefectObservation::query()->create([
                'apparatus_defect_id' => $recurring->id, 'apparatus_inspection_id' => $inspection->id,
                'observation' => 'confirmed_again', 'reported_status' => 'Missing',
            ]);
        }
        $escalated = $this->defect(['item' => 'Rescue saw', 'severity' => 'significant', 'action_status' => 'action_pending']);
        $acknowledgedSignificant = $this->defect(['item' => 'Hose reel', 'severity' => 'significant', 'action_status' => 'acknowledged', 'acknowledged_at' => now()]);
        $this->defect(['item' => 'Pike pole', 'action_status' => 'repair_scheduled']);
        $this->defect(['item' => 'Ladder belt', 'action_status' => 'resolved']);

        $queues = app(StationOperationsHubWidget::class)->getViewData()['department']['inspectionQueues'];

        $this->assertSame(2, $queues['approvals']['count']);
        $this->assertEqualsCanonicalizing([$held->id, $legacy->id], collect($queues['approvals']['rows'])->pluck('id')->all());
        $heldRow = collect($queues['approvals']['rows'])->firstWhere('id', $held->id);
        $this->assertStringContainsString('Needs approval · 2 holds', $heldRow['status']);
        $this->assertStringEndsWith('#approval', $heldRow['url']);

        $this->assertSame(2, $queues['issues']['count'], 'The recurring defect counts once, plus the unacknowledged significant one.');
        $this->assertEqualsCanonicalizing([$recurring->id, $escalated->id], collect($queues['issues']['rows'])->pluck('id')->all());
        $recurringRow = collect($queues['issues']['rows'])->firstWhere('id', $recurring->id);
        $this->assertStringEndsWith('/admin/inspections/'.$accepted->id.'#issue-'.$recurring->id, $recurringRow['url']);

        $this->assertSame(2, $queues['actions']['count']);
        $this->assertContains($acknowledgedSignificant->id, collect($queues['actions']['rows'])->pluck('id')->all());

        $this->assertSame(1, $queues['recent']['count'], 'Only the accepted inspection inside the last 7 days.');
        $this->assertSame($accepted->id, $queues['recent']['rows'][0]['id']);
    }

    public function test_dashboard_renders_the_four_inspection_groups(): void
    {
        $this->inspection(['processing_status' => 'held_for_review', 'review_status' => 'pending_review']);
        $this->actAs($this->reviewer());

        Livewire::test(StationOperationsHubWidget::class)
            ->assertSuccessful()
            ->assertSee('Inspections requiring approval')
            ->assertSee('New or escalated issues')
            ->assertSee('Open corrective actions')
            ->assertSee('Recently completed inspections')
            ->assertSeeHtml('data-inspection-queues');
    }

    /* ------------------------------------------------------------ helpers */

    private function at(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'America/New_York'));
    }

    private function makeApparatus(string $unit, string $vehicle): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $this->station->id, 'unit_id' => $unit, 'name' => 'Engine '.$unit, 'type' => 'Engine',
            'vehicle_number' => $vehicle, 'designation' => $unit, 'slug' => Str::slug('engine-'.$unit),
            'make' => 'Pierce', 'model' => 'Enforcer', 'year' => 2020, 'status' => 'In Service',
            'current_engine_hours' => 100.0, 'current_miles' => 10_000,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function inspection(array $overrides = [], ?Apparatus $apparatus = null): ApparatusInspection
    {
        $apparatus ??= $this->apparatus;

        return ApparatusInspection::query()->create(array_merge([
            'apparatus_id' => $apparatus->id,
            'client_submission_id' => (string) Str::uuid(),
            'inspection_reference' => 'INS-'.Str::upper(Str::random(8)),
            'operator_name' => 'Pat Rivera', 'rank' => 'Lieutenant', 'shift' => 'A',
            'unit_number' => $apparatus->unit_id, 'vehicle_number' => $apparatus->vehicle_number,
            'designation_at_time' => $apparatus->designation,
            'review_status' => 'approved', 'processing_status' => 'accepted',
            'engine_hours' => 101.5, 'miles' => 10010, 'results' => [],
            'completed_at' => now()->utc(),
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function defect(array $overrides = []): ApparatusDefect
    {
        return ApparatusDefect::query()->create(array_merge([
            'apparatus_id' => $this->apparatus->id, 'compartment' => 'Cab', 'item' => 'Portable radio',
            'issue_type' => 'missing', 'status' => 'open', 'reported_date' => now()->toDateString(),
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function exception(ApparatusInspection $inspection, array $overrides = []): ApparatusInspectionException
    {
        return ApparatusInspectionException::query()->create(array_merge([
            'apparatus_inspection_id' => $inspection->id,
            'apparatus_id' => $inspection->apparatus_id,
            'field' => 'miles', 'reason' => 'rollback', 'status' => 'open', 'blocks_acceptance' => false,
        ], $overrides));
    }

    private function reviewer(): User
    {
        return $this->userWithPermissions('logistics_admin', ['view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection']);
    }

    /** @param  list<string>  $permissions */
    private function userWithPermissions(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate($roleName, 'web');
        $role->givePermissionTo(array_map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'), $permissions));
        $user = User::factory()->create(['account_status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function userWithRole(string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(['account_status' => 'active']);
        $user->assignRole(Role::findOrCreate($roleName, 'web'));

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }
}
