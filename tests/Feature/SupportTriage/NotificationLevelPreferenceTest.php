<?php

declare(strict_types=1);

namespace Tests\Feature\SupportTriage;

use App\Filament\Pages\NotificationSettings;
use App\Filament\Resources\StationRequestResource\Pages\ListStationRequests;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\StationRequest;
use App\Models\User;
use App\Models\UserSupportAlertLevels;
use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\NewSubmissionNotification;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Services\Notifications\SupportAlertLevelFilter;
use App\Services\Notifications\SupportAlertLevelPreferences;
use App\Services\StationRequestSideEffectService;
use App\Services\SupportTriage\TriageProjector;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/**
 * Staff choose which Support Services priority levels (low, medium, high) notify them. The choice narrows only the
 * optional triage alerts (a significant-damage issue alert, a new station request alert). It never changes channels,
 * never touches approvals, holds or updates on a member's own report, and never hides work from the dashboards.
 */
final class NotificationLevelPreferenceTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private InspectionNotificationDispatcher $dispatcher;

    private ApparatusInspection $inspection;

    private User $reporter;

    private User $highOnly;

    private User $mediumHigh;

    private User $everything;

    private FakeProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = new FakeProjector;
        // The alert filter reads the one triage projection through this contract; the fake reads the same table.
        $this->app->instance(TriageProjector::class, $this->projector);

        $this->dispatcher = app(InspectionNotificationDispatcher::class);
        $rig = $this->rig();
        $this->reporter = $this->member('Riley Reporter');
        $this->inspection = $this->inspectionBy($rig, $this->reporter);

        $this->highOnly = $this->staff('High Only');
        $this->mediumHigh = $this->staff('Medium And High');
        $this->everything = $this->staff('Everything');
        $prefs = app(SupportAlertLevelPreferences::class);
        $prefs->save($this->highOnly, ['low' => false, 'medium' => false, 'high' => true]);
        $prefs->save($this->mediumHigh, ['low' => false, 'medium' => true, 'high' => true]);

        Notification::fake();
    }

    private function staff(string $name): User
    {
        $user = $this->reviewer('logistics_admin', $name);
        // Fleet reviewers with direct panel access need the fleet capability to review; station viewers need stations.view.
        $user->givePermissionTo(
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.fleet.manage', 'web'),
            Permission::findOrCreate('admin.stations.view', 'web'),
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function defectAt(string $level, string $item): ApparatusDefect
    {
        $defect = $this->openDefect($this->inspection->apparatus, $this->inspection, $this->reporter, $item);
        $this->project('apparatus_defect', (int) $defect->id, $level);

        return $defect;
    }

    private function project(string $type, int $id, string $level): void
    {
        DB::table('support_triage_levels')->insert([
            'subject_type' => $type,
            'subject_id' => $id,
            'auto_level' => $level,
            'level' => $level,
            'policy_version' => 1,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function request(string $number): StationRequest
    {
        $station = $this->inspection->apparatus->station;

        return StationRequest::query()->forceCreate([
            'request_number' => $number,
            'station_id' => $station->id,
            'requester_name_snapshot' => 'Riley Reporter',
            'request_type' => 'repair_service',
            'title' => 'Item '.$number,
            'description' => 'Needs attention.',
        ]);
    }

    /** @param list<User> $expected */
    private function assertDefectAlertGoesTo(ApparatusDefect $defect, array $expected): void
    {
        $key = 'defect:'.$defect->id.':escalated:'.$this->inspection->id;
        foreach ([$this->highOnly, $this->mediumHigh, $this->everything] as $staff) {
            $matches = fn (InspectionEventNotification $n): bool => $n->eventKey === $key;
            in_array($staff, $expected, false)
                ? Notification::assertSentTo($staff, InspectionEventNotification::class, $matches)
                : Notification::assertNotSentTo($staff, InspectionEventNotification::class, $matches);
        }
        $this->assertSame(
            collect($expected)->pluck('id')->sort()->values()->all(),
            DB::table('inspection_notification_deliveries')->where('event_key', $key)->pluck('recipient_user_id')->sort()->values()->all(),
            'only people who get the alert have a ledger row',
        );
    }

    public function test_each_defect_alert_reaches_exactly_the_staff_who_want_that_level(): void
    {
        $low = $this->defectAt('low', 'Door stop');
        $medium = $this->defectAt('medium', 'Thermal camera');
        $high = $this->defectAt('high', 'SCBA facepiece');

        foreach ([$low, $medium, $high] as $defect) {
            $this->dispatcher->issueEscalated($defect, $this->inspection);
        }

        $this->assertDefectAlertGoesTo($low, [$this->everything]);
        $this->assertDefectAlertGoesTo($medium, [$this->mediumHigh, $this->everything]);
        $this->assertDefectAlertGoesTo($high, [$this->highOnly, $this->mediumHigh, $this->everything]);
    }

    public function test_turning_low_off_suppresses_only_low_alerts(): void
    {
        $low = $this->defectAt('low', 'Door stop');
        $medium = $this->defectAt('medium', 'Thermal camera');

        $this->dispatcher->issueEscalated($low, $this->inspection);
        $this->dispatcher->issueEscalated($medium, $this->inspection);

        Notification::assertNotSentTo($this->mediumHigh, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains((string) $n->eventKey, 'defect:'.$low->id.':'));
        Notification::assertSentTo($this->mediumHigh, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => str_contains((string) $n->eventKey, 'defect:'.$medium->id.':'));
    }

    public function test_mandatory_alerts_ignore_the_level_choices(): void
    {
        // Approval is a reviewer decision: it reaches the staff member who turned every optional level off.
        $nothing = $this->staff('Nothing Optional');
        app(SupportAlertLevelPreferences::class)->save($nothing, ['low' => false, 'medium' => false, 'high' => false]);
        ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $this->inspection->id,
            'apparatus_id' => $this->inspection->apparatus_id,
            'field' => 'miles',
            'reason' => 'rollback',
            'status' => 'open',
            'blocks_acceptance' => true,
        ]);
        $defect = $this->defectAt('high', 'SCBA facepiece');

        $this->dispatcher->reviewRequired($this->inspection);
        $this->dispatcher->issueEscalated($defect, $this->inspection);

        foreach ([$nothing, $this->highOnly, $this->mediumHigh, $this->everything] as $staff) {
            Notification::assertSentTo($staff, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'inspection_review_required');
        }
        Notification::assertNotSentTo($nothing, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'issue_escalated');

        // An update on the member's own report is not a triage alert either.
        $memberHidden = $this->reporter;
        app(SupportAlertLevelPreferences::class)->save($memberHidden, ['low' => false, 'medium' => false, 'high' => false]);
        $this->dispatcher->inspectionDecided($this->inspection, 'approved');
        Notification::assertSentTo($memberHidden, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === 'inspection_decided');
    }

    public function test_defaults_keep_todays_behaviour_for_everyone(): void
    {
        $plain = $this->staff('Never Chose');
        $this->assertSame(['low' => true, 'medium' => true, 'high' => true], app(SupportAlertLevelPreferences::class)->for($plain));
        $this->assertSame(0, UserSupportAlertLevels::query()->where('user_id', $plain->id)->count(), 'no row is written for a default');

        $defect = $this->defectAt('low', 'Door stop');
        $this->dispatcher->issueEscalated($defect, $this->inspection);

        Notification::assertSentTo($plain, InspectionEventNotification::class);
        Notification::assertSentTo($this->everything, InspectionEventNotification::class);
    }

    public function test_a_defect_without_a_projection_row_is_computed_on_demand(): void
    {
        $defect = $this->openDefect($this->inspection->apparatus, $this->inspection, $this->reporter, 'SCBA facepiece');
        $this->projector->computeOnRefresh = 'high';

        $this->dispatcher->issueEscalated($defect, $this->inspection);

        $this->assertSame([(int) $defect->id], $this->projector->refreshed);
        $this->assertDefectAlertGoesTo($defect, [$this->highOnly, $this->mediumHigh, $this->everything]);
    }

    public function test_a_level_that_cannot_be_computed_reads_as_medium(): void
    {
        $defect = $this->openDefect($this->inspection->apparatus, $this->inspection, $this->reporter, 'Thermal camera');
        $this->projector->computeOnRefresh = null;
        $this->projector->throwOnRefresh = true;

        $this->dispatcher->issueEscalated($defect, $this->inspection);

        $this->assertSame('medium', app(SupportAlertLevelFilter::class)->levelFor('apparatus_defect', (int) $defect->id));
        $this->assertDefectAlertGoesTo($defect, [$this->mediumHigh, $this->everything]);
    }

    public function test_new_station_request_alerts_follow_the_request_level(): void
    {
        $low = $this->request('SR-1');
        $high = $this->request('SR-2');
        $unprojected = $this->request('SR-3');
        $this->project('station_request', (int) $low->id, 'low');
        $this->project('station_request', (int) $high->id, 'high');

        foreach ([$low, $high, $unprojected] as $request) {
            app(StationRequestSideEffectService::class)->requestCreated($request);
        }

        $sentFor = fn (User $staff, StationRequest $request): bool => Notification::sent($staff, NewSubmissionNotification::class)
            ->contains(fn (NewSubmissionNotification $n): bool => str_contains($this->eventKeyOf($n, $staff), ':'.$request->id.':'));

        $this->assertTrue($sentFor($this->everything, $low));
        $this->assertFalse($sentFor($this->mediumHigh, $low));
        $this->assertFalse($sentFor($this->highOnly, $low));

        foreach ([$this->highOnly, $this->mediumHigh, $this->everything] as $staff) {
            $this->assertTrue($sentFor($staff, $high));
        }

        // No projection yet reads as medium: nobody who wants medium loses it, and a high-only reader does not get a guess.
        $this->assertTrue($sentFor($this->mediumHigh, $unprojected));
        $this->assertTrue($sentFor($this->everything, $unprojected));
        $this->assertFalse($sentFor($this->highOnly, $unprojected));
    }

    public function test_the_level_filter_never_changes_which_channels_an_alert_uses(): void
    {
        $alert = fn (): NewSubmissionNotification => (new NewSubmissionNotification(
            submissionType: 'station_request',
            title: 'New Station Request',
            body: 'SR-9: Leaking faucet',
            actionUrl: '/admin/station-requests/9',
        ))->forTriageSubject('station_request', 9);
        $before = $alert()->via($this->mediumHigh);

        app(SupportAlertLevelPreferences::class)->save($this->mediumHigh, ['low' => true, 'medium' => false, 'high' => true]);
        $after = $alert()->via($this->mediumHigh);

        $this->assertSame($before, $after, 'a level choice never adds or removes a channel');
        $this->assertNotContains(BudgetedMailChannel::class, $after, 'no city email is introduced');
        $this->assertSame(['database'], $after);
    }

    private function eventKeyOf(NewSubmissionNotification $notification, User $staff): string
    {
        return (string) $notification->toDatabase($staff)['provenance']['event_key'];
    }

    // ------------------------------------------------------------------ settings page

    public function test_the_settings_page_saves_and_loads_the_three_levels(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->actingAs($this->everything);

        Livewire::test(NotificationSettings::class)
            ->assertSuccessful()
            ->assertFormFieldExists('support_alert_levels.low')
            ->assertFormFieldExists('support_alert_levels.medium')
            ->assertFormFieldExists('support_alert_levels.high')
            ->assertSee('Support Services alerts: priority levels')
            ->assertSee('always notify you')
            ->assertFormSet(['support_alert_levels' => ['low' => true, 'medium' => true, 'high' => true]])
            ->fillForm(['support_alert_levels' => ['low' => false, 'medium' => true, 'high' => true]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['low' => false, 'medium' => true, 'high' => true], app(SupportAlertLevelPreferences::class)->for($this->everything));

        Livewire::test(NotificationSettings::class)
            ->assertFormSet(['support_alert_levels' => ['low' => false, 'medium' => true, 'high' => true]]);

        // Back to all three on: the stored row goes away, so defaults can still improve later.
        Livewire::test(NotificationSettings::class)
            ->fillForm(['support_alert_levels' => ['low' => true, 'medium' => true, 'high' => true]])
            ->call('save');
        $this->assertSame(0, UserSupportAlertLevels::query()->where('user_id', $this->everything->id)->count());
    }

    public function test_a_member_with_no_triage_alerts_does_not_see_the_levels_section(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->actingAs($this->reporter);

        Livewire::test(NotificationSettings::class)
            ->assertSuccessful()
            ->assertFormFieldDoesNotExist('support_alert_levels.low')
            ->assertDontSee('priority levels');
    }

    public function test_saving_the_page_does_not_warn_about_the_levels_section(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->actingAs($this->highOnly);

        Livewire::test(NotificationSettings::class)
            ->call('save')
            ->assertNotified('Notification preferences saved');

        $this->assertSame(['low' => false, 'medium' => false, 'high' => true], app(SupportAlertLevelPreferences::class)->for($this->highOnly));
    }

    // ------------------------------------------------------------------ dashboards

    public function test_the_work_list_shows_every_request_whatever_the_staff_member_chose(): void
    {
        $admin = $this->reviewer('super_admin', 'Chief Admin');
        app(SupportAlertLevelPreferences::class)->save($admin, ['low' => false, 'medium' => false, 'high' => false]);
        $low = $this->request('SR-L');
        $high = $this->request('SR-H');
        $this->project('station_request', (int) $low->id, 'low');
        $this->project('station_request', (int) $high->id, 'high');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListStationRequests::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$low, $high]);
    }

    public function test_nothing_in_the_level_path_sends_or_listens_on_projection_changes(): void
    {
        $defect = $this->openDefect($this->inspection->apparatus, $this->inspection, $this->reporter, 'Door stop');
        Notification::fake();

        // Re-triaging (rewriting the projection row) is a plain table write: nothing is notified.
        $this->project('apparatus_defect', (int) $defect->id, 'low');
        DB::table('support_triage_levels')->where('subject_id', $defect->id)->update(['level' => 'high', 'updated_at' => now()]);
        app(SupportAlertLevelFilter::class)->filter(collect([$this->everything]), 'apparatus_defect', (int) $defect->id);

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('inspection_notification_deliveries')->count());
    }
}

/**
 * Stand-in for App\Services\SupportTriage\TriageProjector: levelFor reads the same support_triage_levels table the
 * real projection writes, and refreshDefect can add the row the way the real after-commit hook would.
 */
final class FakeProjector
{
    public ?string $computeOnRefresh = null;

    public bool $throwOnRefresh = false;

    /** @var list<int> */
    public array $refreshed = [];

    public function levelFor(string $subjectType, int $subjectId): ?string
    {
        $level = DB::table('support_triage_levels')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->value('level');

        return is_string($level) ? $level : null;
    }

    public function refreshDefect(ApparatusDefect $defect): void
    {
        $this->refreshed[] = (int) $defect->id;
        if ($this->throwOnRefresh) {
            throw new RuntimeException('projector down');
        }
        if ($this->computeOnRefresh !== null) {
            DB::table('support_triage_levels')->insert([
                'subject_type' => 'apparatus_defect',
                'subject_id' => $defect->id,
                'auto_level' => $this->computeOnRefresh,
                'level' => $this->computeOnRefresh,
                'policy_version' => 1,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
