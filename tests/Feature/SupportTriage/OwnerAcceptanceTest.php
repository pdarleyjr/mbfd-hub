<?php

declare(strict_types=1);

namespace Tests\Feature\SupportTriage;

use App\Filament\Admin\Pages\EmployeeMessages;
use App\Filament\Employee\Pages\MyMessages;
use App\Filament\Resources\StationRequestResource\Pages\ViewStationRequest;
use App\Jobs\FanOutNotification;
use App\Jobs\SupportTriage\ReapplyTriagePolicy;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\Employee;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\Room;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\SupportTriage\SupportEquipmentRule;
use App\Models\SupportTriage\SupportTriageLevel;
use App\Models\User;
use App\Notifications\AiResponseNotification;
use App\Notifications\NewSubmissionNotification;
use App\Services\AiAutomation\AiAutomationSwitch;
use App\Services\AiAutomation\BacklogService;
use App\Services\AiAutomation\Domains\VehicleAdapter;
use App\Services\AiAutomation\IntakeProcessor;
use App\Services\AiAutomation\PlaybookRepository;
use App\Services\AiAutomation\ResponseComposer;
use App\Services\Dashboards\Sources\Station\StationRequestSource;
use App\Services\Dashboards\Sources\Vehicle\ApparatusDefectSource;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\SupportAlertLevelPreferences;
use App\Services\SupportTriage\EquipmentClassification as C;
use App\Services\SupportTriage\EquipmentRuleService;
use App\Services\SupportTriage\TriageProjector;
use App\Support\Dashboards\DashboardQuery;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Dashboards\StationDashboardFixtures;
use Tests\Feature\Dashboards\VehicleDashboardFixtures;
use Tests\Feature\EmployeeMessaging\BuildsEmployeeMessagingFixtures;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;
use Tests\TestCase;

/**
 * The owner's acceptance matrix (2026-11-03), end to end through the real services: the member's own submission
 * path (Daily Checkout API, station request API, Support Services messaging), the shared equipment catalog and
 * triage projection, the reply engine, the staff dashboard sources and the staff alert filter. Only mail is faked, so
 * any email would be caught. Item names are the ones on the real Daily Checkout checklists.
 */
final class OwnerAcceptanceTest extends TestCase
{
    use BuildsDailyScenarioWorld;
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;
    use StationDashboardFixtures;
    use VehicleDashboardFixtures;

    private User $alpha;

    private User $requester;

    private Employee $requesterEmployee;

    private Room $room;

    private ?User $vehicleStaff = null;

    private ?User $stationStaff = null;

    /** @var array<string, Apparatus> */
    private array $units = [];

    private int $seq = 0;

    /** @var list<array{0: string, 1: string}> notification class and channel, as actually delivered */
    private array $delivered = [];

    /** @var array<string, int>|null table => statements, only while a write audit is open */
    private ?array $writes = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Replies add a 300 line on Saturdays and Sundays; pin a Tuesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:00:00', 'America/New_York'));
        app(EquipmentRuleService::class)->seedDefaults();
        app(PlaybookRepository::class)->ensureSeeded();
        Mail::fake();
        $this->buildWorld();
        RateLimiter::clear('employee-logistics-message:1');

        $this->alpha = $this->newMember('Alpha Member');
        $home = $this->station(1);
        $this->requesterEmployee = Employee::query()->create([
            'employee_id' => '20991', 'name' => 'Firefighter Requester', 'rank' => 'Firefighter',
            'password' => Hash::make('test-password-only'), 'must_change_password' => false,
        ]);
        $this->requester = User::factory()->create([
            'employee_id' => $this->requesterEmployee->employee_id,
            'employee_profile_id' => $this->requesterEmployee->id,
        ]);
        $this->room = Room::query()->create(['station_id' => $home->id, 'name' => 'Kitchen', 'room_type' => 'kitchen']);

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            $this->delivered[] = [$event->notification::class, $event->channel];
        });
        DB::listen(function ($query): void {
            if ($this->writes !== null && preg_match('/^\s*(?:insert\s+(?:or\s+\w+\s+)?into|update|delete\s+from)\s+["`\[]?([a-z0-9_]+)/i', $query->sql, $m) === 1) {
                $this->writes[strtolower($m[1])] = ($this->writes[strtolower($m[1])] ?? 0) + 1;
            }
        });
    }

    // ===================================================================================== 1-5 vehicle: LOW and MEDIUM

    public function test_01_kitty_litter_is_low_and_asks_the_crew_to_look_then_call_station_2(): void
    {
        $this->on();
        $this->checkout('rescue', [['Kitty Litter']]);

        $defect = ApparatusDefect::query()->sole();
        $this->assertSame(C::LOW, $this->dashboardLevel($defect));
        $this->assertSame(
            "Please check around the station and other apparatus to make sure the Kitty Litter wasn't misplaced. If it's still missing, call or text before coming by Station 2 so we can check on a replacement.",
            $this->onlyReply(),
        );
        $this->assertNoEmailAnywhere();
    }

    public function test_02_inexpensive_hardware_is_low_with_the_same_look_then_call_guidance(): void
    {
        $this->on();
        $this->checkout('engine2', [['Hose Clamps']]);

        $this->assertSame(C::LOW, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $reply = $this->onlyReply();
        $this->assertStringContainsString("Hose Clamps wasn't misplaced", $reply);
        $this->assertStringContainsString('call or text before coming by Station 2', $reply);
        $this->assertNoEmailAnywhere();
    }

    public function test_03_a_dewalt_battery_is_low_and_points_to_chargers_other_tools_and_the_station_blower(): void
    {
        $this->on();
        Queue::fake();
        $this->checkout('engine', [['Hose Clamps', 'Missing', ['as' => 'DeWalt Battery']]]);
        $this->processAll();

        $this->assertSame(C::LOW, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $reply = $this->onlyReply();
        $this->assertStringContainsString('chargers', $reply);
        $this->assertStringContainsString('other DeWalt tools', $reply);
        $this->assertStringContainsString('station blower', $reply);
        $this->assertStringContainsString('call or text before coming by Station 2', $reply);
        $this->assertNoEmailAnywhere();
    }

    public function test_04_an_expensive_power_tool_is_medium_and_asks_to_confirm_a_replacement_first(): void
    {
        $this->on();
        $this->checkout('engine2', [['Bandsaw']]);

        $this->assertSame(C::MEDIUM, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $reply = $this->onlyReply();
        $this->assertStringContainsString('Call or text before coming by Station 2', $reply);
        $this->assertStringContainsString('check whether a replacement is available', $reply);
        $this->assertNoEmailAnywhere();
    }

    public function test_05_a_gas_meter_missing_or_damaged_is_medium_with_coordination(): void
    {
        $this->on();
        $this->checkout('engine2', [['Gas Meter']]);
        $this->assertSame(C::MEDIUM, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $missing = $this->onlyReply();
        $this->assertStringContainsString('Coordinate a replacement with Support Services or the appropriate equipment technician', $missing);
        $this->assertStringContainsString('Call or text first', $missing);

        $this->checkout('engine', [['Dreager Gas Meter', 'Damaged', ['notes' => 'Display is cracked.']]]);
        $levels = ApparatusDefect::query()->orderBy('id')->get()->map(fn (ApparatusDefect $d): string => $this->dashboardLevel($d))->all();
        $this->assertSame([C::MEDIUM, C::MEDIUM], $levels);
        $this->assertSame(2, $this->assistantMessages()->count());
        $this->assertStringContainsString('Coordinate a replacement', (string) $this->assistantMessages()->last()->body);
        $this->assertNoEmailAnywhere();
    }

    // ===================================================================================== 6-10 HIGH protective equipment

    public function test_06_a_complete_scba_is_high_and_the_officer_comes_first(): void
    {
        $this->on();
        $this->checkout('engine2', [['SCBA']]);

        $defect = ApparatusDefect::query()->sole();
        $this->assertSame(C::HIGH, $this->dashboardLevel($defect));
        $this->assertSame('Acknowledged. Notify your officer immediately and coordinate with Support Services or 300 for further direction.', $this->onlyReply());
        $this->assertSame('critical', AiIntakeEvent::query()->sole()->risk_tier);
        $this->assertNoEmailAnywhere();
    }

    public function test_07_a_facepiece_is_high(): void
    {
        $this->on();
        $this->checkout('ladder1', [['SCBA Facemask']]);

        $this->assertSame(C::HIGH, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $this->assertStringStartsWith('Acknowledged. Notify your officer immediately', $this->onlyReply());
        $this->assertNoEmailAnywhere();
    }

    public function test_08_a_hood_is_high(): void
    {
        $this->on();
        Queue::fake();
        $this->checkout('engine', [['Hose Clamps', 'Damaged', ['as' => 'Hood', 'notes' => 'Torn at the neck.']]]);
        $this->processAll();

        $this->assertSame(C::HIGH, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $this->assertStringStartsWith('Acknowledged. Notify your officer immediately', $this->onlyReply());
        $this->assertNoEmailAnywhere();
    }

    public function test_09_a_damaged_helmet_is_high(): void
    {
        $this->on();
        $this->checkout('engine2', [['TRT Helmets', 'Damaged', ['notes' => 'Shell is cracked.']]]);

        $this->assertSame(C::HIGH, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $this->assertStringStartsWith('Acknowledged. Notify your officer immediately', $this->onlyReply());
        $this->assertNoEmailAnywhere();
    }

    public function test_10_bunker_coat_and_pants_damaged_are_high_with_one_reply(): void
    {
        $this->on();
        Queue::fake();
        $this->checkout('engine', [
            ['Hose Clamps', 'Damaged', ['as' => 'Bunker coat', 'notes' => 'Liner is torn.']],
            ['Hose Roller', 'Damaged', ['as' => 'Bunker pants', 'notes' => 'Knee is worn through.']],
        ]);
        $this->processAll();

        foreach (ApparatusDefect::query()->orderBy('id')->get() as $defect) {
            $this->assertSame(C::HIGH, $this->dashboardLevel($defect), $defect->item);
        }
        $this->assertSame(2, ApparatusDefect::query()->count());
        $this->assertStringStartsWith('Acknowledged. Notify your officer immediately', $this->onlyReply());
        $this->assertNoEmailAnywhere();
    }

    // ===================================================================================== 11-15 SCBA parts, cylinders, Lifepak, Rescue

    public function test_11_an_scba_accessory_is_medium_and_goes_to_the_air_tech(): void
    {
        $this->on();
        $this->checkout('ladder1', [['SCBA Hoses/Regulator']]);

        $this->assertSame(C::MEDIUM, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $this->assertStringContainsString('Coordinate with the on-duty Air Tech', $this->onlyReply());
        $this->assertNoEmailAnywhere();
    }

    public function test_12_a_cylinder_is_medium_and_goes_to_the_air_tech(): void
    {
        $this->on();
        $this->checkout('engine2', [['SCBA Bottles']]);

        $this->assertSame(C::MEDIUM, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $reply = $this->onlyReply();
        $this->assertStringContainsString('Coordinate with the on-duty Air Tech to have the item checked or swapped out', $reply);
        $this->assertStringNotContainsString('Remove the leaking cylinder', $reply);
        $this->assertNoEmailAnywhere();
    }

    public function test_13_a_leaking_cylinder_is_medium_with_removal_then_air_tech_and_danger_words_make_it_high(): void
    {
        $this->on();
        Queue::fake(); // the second report is renamed before it is answered
        $this->checkout('engine2', [['SCBA Bottles', 'Damaged', ['notes' => 'The valve is leaking air.']]]);
        $this->processAll();

        $this->assertSame(C::MEDIUM, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $reply = $this->onlyReply();
        $this->assertStringContainsString('Remove the leaking cylinder from use', $reply);
        $this->assertStringContainsString('contact the on-duty Air Tech', $reply);
        $this->assertSame('elevated', AiIntakeEvent::query()->sole()->risk_tier);

        // The same cylinder with a danger phrase is HIGH for staff and critical in the ledger.
        $this->checkout('engine', [['Hose Clamps', 'Damaged', ['as' => 'SCBA Bottle', 'notes' => 'It is leaking badly and unsafe to carry.']]]);
        $this->processAll();
        $high = ApparatusDefect::query()->orderByDesc('id')->first();
        $this->assertSame(C::HIGH, $this->dashboardLevel($high));
        $this->assertSame('critical', AiIntakeEvent::query()->orderByDesc('id')->first()->risk_tier);
        $this->assertStringContainsString('Remove the leaking cylinder', (string) $this->assistantMessages()->last()->body);
        $this->assertNoEmailAnywhere();
    }

    public function test_14_a_lifepak_is_medium_for_captain_5_and_a_significant_one_reads_as_readiness(): void
    {
        $this->on();
        $this->checkout('engine2', [['Life-Pak']]);
        $this->assertSame(C::MEDIUM, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $this->assertSame('Acknowledged. Coordinate with Captain 5 to arrange a replacement or pickup. Confirm availability before coming by.', $this->onlyReply());

        $this->checkout('engine', [['LifePak', 'Damaged', ['significant' => true, 'notes' => 'Will not power on.']]]);
        $significant = ApparatusDefect::query()->orderByDesc('id')->first();
        $this->assertSame(C::HIGH, $this->dashboardLevel($significant));
        $this->assertSame('Acknowledged. Notify your officer and 300 right away.', (string) $this->assistantMessages()->last()->body);
        $this->assertNoEmailAnywhere();
    }

    public function test_15_splints_and_backboards_on_rescue_go_to_captain_5_without_touching_ems_or_captain_5_records(): void
    {
        $this->on();
        Queue::fake();
        $this->checkout('rescue', [['Backboard / Scoop Stretcher'], ['Cardboard Splints']]);
        $emsLike = $this->forbiddenTables();
        $this->assertNotEmpty($emsLike, 'The audit must have something to guard.');

        $before = $this->countsOf($emsLike);
        $this->audit(fn () => $this->processAll());
        $written = array_intersect(array_keys($this->lastWrites), $emsLike);

        $this->assertSame([], array_values($written), 'The reply wrote to: '.implode(', ', $written));
        $this->assertSame($before, $this->countsOf($emsLike));
        foreach (ApparatusDefect::query()->get() as $defect) {
            $this->assertSame(C::LOW, $this->dashboardLevel($defect));
        }
        $this->assertSame('Acknowledged. Contact Captain 5 to coordinate a replacement and pickup.', $this->onlyReply());
        $this->assertNoEmailAnywhere();
    }

    // ===================================================================================== 16-18 support and station

    public function test_16_a_bunker_gear_drop_off_message_is_simply_acknowledged(): void
    {
        $this->on();
        $member = $this->messagingMember('Gear Member');

        $this->service()->startThread($member, 'I dropped off my bunker gear at Station 2 for cleaning.', null, $this->rid());

        $reply = EmployeeLogisticsMessage::query()->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->sole();
        $this->assertSame('Acknowledged.', $reply->body);
        $this->assertNoEmailAnywhere();
    }

    public function test_17_a_station_air_conditioning_request_is_medium_with_a_receipt(): void
    {
        $this->on();
        $id = $this->stationRequestViaApi('Dayroom A/C is not working', 'The dayroom is very hot and the air conditioner will not cool.', 'Air conditioner');

        $this->assertSame(C::MEDIUM, $this->stationDashboardLevel($id));
        $update = StationRequestUpdate::query()->where('station_request_id', $id)->autoReceipts()->sole();
        $this->assertStringStartsWith('Acknowledged.', (string) $update->public_note);
        $this->assertSame('Support Services', $update->senderLabel());
        $this->assertSame('normal', StationRequest::query()->findOrFail($id)->priority, 'The member priority is untouched.');
        $this->assertNoEmailAnywhere();
    }

    public function test_18_station_electrical_sparking_is_high_and_tells_the_member_to_call_the_officer_and_300(): void
    {
        $this->on();
        $id = $this->stationRequestViaApi('Kitchen outlet is sparking', 'The wall outlet by the stove is sparking when the toaster is plugged in.', 'Wall outlet');

        $this->assertSame(C::HIGH, $this->stationDashboardLevel($id));
        $note = (string) StationRequestUpdate::query()->where('station_request_id', $id)->autoReceipts()->sole()->public_note;
        $this->assertSame('Acknowledged. If there is an immediate hazard, notify your officer and 300 right away.', $note);
        $this->assertSame('critical', AiIntakeEvent::query()->where('source_type', 'station_request')->sole()->risk_tier);
        $this->assertNoEmailAnywhere();
    }

    // ===================================================================================== 19-22 aggregation, repeats, admin changes

    public function test_19_mixed_findings_use_the_highest_level_and_send_one_reply(): void
    {
        $this->on();
        $this->checkout('engine2', [['Hose Clamps'], ['Gas Meter'], ['SCBA']]);

        $levels = ApparatusDefect::query()->orderBy('id')->get()->mapWithKeys(fn (ApparatusDefect $d): array => [$d->item => $this->dashboardLevel($d)])->all();
        $this->assertEquals(['Hose Clamps' => C::LOW, 'Gas Meter' => C::MEDIUM, 'SCBA' => C::HIGH], $levels);
        $this->assertCount(1, $this->assistantMessages());
        $this->assertSame(1, AiIntakeEvent::query()->count());
        $this->assertSame('critical', AiIntakeEvent::query()->sole()->risk_tier);
        $this->assertStringStartsWith('Acknowledged. Notify your officer immediately', $this->onlyReply());
        $this->assertSame(1, $this->aiBells()->count());
        $this->assertNoEmailAnywhere();
    }

    public function test_20_an_old_defect_seen_again_adds_no_second_defect_reply_or_bell(): void
    {
        $this->on();
        $first = $this->checkout('engine2', [['Hose Clamps']]);
        $this->assertCount(1, $this->assistantMessages());
        $triageBefore = SupportTriageLevel::query()->get()->toArray();
        $bellsBefore = $this->aiBells()->count();
        $notificationsBefore = DB::table('notifications')->count();

        $second = $this->checkout('engine2', [['Hose Clamps', 'Missing', ['notes' => 'Still gone.']]]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, ApparatusDefect::query()->count(), 'One continuing issue, not two.');
        $this->assertCount(1, $this->assistantMessages());
        $this->assertSame($bellsBefore, $this->aiBells()->count());
        $this->assertSame($notificationsBefore, DB::table('notifications')->count(), 'No other bell appeared either.');
        $this->assertSame(AiIntakeEvent::STATE_SKIPPED, AiIntakeEvent::query()->where('source_id', $second->id)->sole()->state);
        $this->assertEquals($triageBefore, SupportTriageLevel::query()->get()->toArray(), 'The projection did not move.');
        $this->assertNoEmailAnywhere();
    }

    public function test_21_an_admin_change_from_low_to_medium_reaches_open_issues_on_reapply_and_keeps_overrides(): void
    {
        $this->on();
        Queue::fake();
        $this->checkout('rescue', [['Kitty Litter']]);
        $this->checkout('engine2', [['Hose Clamps']]);
        $this->processAll();
        $litter = ApparatusDefect::query()->where('item', 'Kitty Litter')->sole();
        $clamps = ApparatusDefect::query()->where('item', 'Hose Clamps')->sole();
        $this->assertSame([C::LOW, C::LOW], [$this->dashboardLevel($litter), $this->dashboardLevel($clamps)]);
        app(TriageProjector::class)->setOverride('apparatus_defect', (int) $clamps->id, C::HIGH, $this->vehicleStaff());

        $rule = SupportEquipmentRule::query()->where('name', 'Absorbents and kitty litter')->sole();
        app(EquipmentRuleService::class)->setLevel($rule, C::MEDIUM, $this->vehicleStaff());
        $rule2 = SupportEquipmentRule::query()->where('name', 'Shovels, brooms and hand tools')->sole();
        app(EquipmentRuleService::class)->setLevel($rule2, C::MEDIUM, $this->vehicleStaff());
        $this->assertSame(C::LOW, $this->dashboardLevel($litter), 'Nothing moves until the administrator applies the change.');

        $notifications = DB::table('notifications')->count();
        $deliveries = DB::table('inspection_notification_deliveries')->count();
        $this->reapply();

        $this->assertSame(C::MEDIUM, $this->dashboardLevel($litter->refresh()));
        $this->assertSame(C::HIGH, $this->dashboardLevel($clamps->refresh()), 'The staff override survives the reapply.');
        $this->assertSame($notifications, DB::table('notifications')->count());
        $this->assertSame($deliveries, DB::table('inspection_notification_deliveries')->count());
        $this->assertNoEmailAnywhere();

        // The next new report of an item in the changed rule (another unit's hose clamps) is answered as MEDIUM.
        $this->checkout('engine', [['Hose Clamps']]);
        $this->processAll();
        $this->assertStringContainsString('check whether a replacement is available', (string) $this->assistantMessages()->last()->body);
    }

    public function test_22_an_edited_response_message_is_used_by_the_very_next_reply(): void
    {
        $this->on();
        $this->checkout('engine2', [['Hose Clamps']]);
        $this->assertStringContainsString('wasn\'t misplaced', $this->onlyReply());

        $playbook = \App\Models\AiAutomation\AiAutomationPlaybook::query()->where('key', 'missing_low')->sole();
        app(PlaybookRepository::class)->update($playbook, 'Look in the supply closet first, then text Support Services about the {item}.', $this->vehicleStaff());

        $this->checkout('engine', [['Hose Clamps']]);

        $this->assertSame('Look in the supply closet first, then text Support Services about the Hose Clamps.', (string) $this->assistantMessages()->last()->body);
        $this->assertSame(2, $this->assistantMessages()->count());
        $this->assertNoEmailAnywhere();
    }

    // ===================================================================================== 23-24 staff alert levels

    public function test_23_a_staff_member_with_low_off_and_high_on_gets_only_the_eligible_alerts(): void
    {
        Notification::fake();
        Queue::fake();
        $staff = $this->alertStaff('Low Off High On', ['low' => false, 'medium' => false, 'high' => true]);

        $low = $this->stationRequestViaApi('Out of paper towels', 'Please send paper towels and trash bags.', 'Paper towels');
        $this->fanOut();
        $medium = $this->stationRequestViaApi('Dayroom A/C is not working', 'The dayroom is very hot.', 'Air conditioner');
        $this->fanOut();
        $high = $this->stationRequestViaApi('Kitchen outlet is sparking', 'The outlet is sparking.', 'Wall outlet');
        $this->fanOut();

        $this->assertSame([C::LOW, C::MEDIUM, C::HIGH], [$this->stationDashboardLevel($low), $this->stationDashboardLevel($medium), $this->stationDashboardLevel($high)]);
        $this->assertSame([$high], $this->alertedRequests($staff));
        $this->assertCount(3, StationRequest::query()->get(), 'The work list still has every request.');
        $this->assertSame(3, count($this->stationRows()));
    }

    public function test_24_two_staff_members_with_different_choices_each_get_their_own_alerts(): void
    {
        Notification::fake();
        Queue::fake();
        $highOnly = $this->alertStaff('High Only', ['low' => false, 'medium' => false, 'high' => true]);
        $mediumHigh = $this->alertStaff('Medium And High', ['low' => false, 'medium' => true, 'high' => true]);
        $everything = $this->alertStaff('Everything', ['low' => true, 'medium' => true, 'high' => true]);

        $low = $this->stationRequestViaApi('Out of paper towels', 'Please send paper towels and trash bags.', 'Paper towels');
        $this->fanOut();
        $medium = $this->stationRequestViaApi('Dayroom A/C is not working', 'The dayroom is very hot.', 'Air conditioner');
        $this->fanOut();
        $high = $this->stationRequestViaApi('Kitchen outlet is sparking', 'The outlet is sparking.', 'Wall outlet');
        $this->fanOut();

        $this->assertSame([$high], $this->alertedRequests($highOnly));
        $this->assertSame([$medium, $high], $this->alertedRequests($mediumHigh));
        $this->assertSame([$low, $medium, $high], $this->alertedRequests($everything));
    }

    // ===================================================================================== 25 OFF mid-flight

    public function test_25_off_during_queued_replies_and_a_backlog_run_writes_nothing_but_the_ledger(): void
    {
        $this->on();
        Queue::fake();
        $this->checkout('engine2', [['Hose Clamps']]);
        $this->stationRequestViaApi('Dayroom A/C is not working', 'The dayroom is very hot.', 'Air conditioner');
        $this->service()->startThread($this->messagingMember('Quiet Member'), 'Do you have spare folding chairs?', null, $this->rid());
        $ids = AiIntakeEvent::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(3, $ids);

        app(AiAutomationSwitch::class)->turnOff(null, 'console');
        $this->audit(function () use ($ids): void {
            foreach ($ids as $id) {
                app(IntakeProcessor::class)->process($id);
            }
        });

        $this->assertSame(['ai_intake_events'], array_keys($this->lastWrites));
        $this->assertCount(0, $this->assistantMessages());
        $this->assertSame(0, $this->aiBells()->count());

        // A backlog run that was started, then stopped by OFF, writes nothing either.
        $switch = app(AiAutomationSwitch::class);
        AiIntakeEvent::query()->delete();
        $backlog = app(BacklogService::class);
        $run = $backlog->preview(null);
        $this->assertGreaterThan(0, $run->preview['would_send']);
        $switch->turnOn(null, 'console');
        $this->assertTrue($backlog->start($run->fresh()));
        $epoch = $switch->epoch();
        $switch->turnOff(null, 'console');
        $this->audit(function () use ($backlog, $run, $epoch): void {
            $this->assertFalse($backlog->processBatch((int) $run->id, $epoch));
        });
        $this->assertSame([], array_values(array_diff(array_keys($this->lastWrites), ['ai_backlog_runs', 'ai_intake_events'])));
        $this->assertCount(0, $this->assistantMessages());
        $this->assertSame(0, StationRequestUpdate::query()->autoReceipts()->count());
    }

    // ===================================================================================== 26 compatibility

    public function test_26_assistant_rows_render_on_current_pages_and_the_api_only_adds_fields(): void
    {
        $this->on();
        $inspection = $this->checkout('engine2', [['Hose Clamps']]);
        $stationRequestId = $this->stationRequestViaApi('Dayroom A/C is not working', 'The dayroom is very hot.', 'Air conditioner');
        $supportMember = $this->messagingMember('Pages Member');
        $message = $this->service()->startThread($supportMember, 'Do you have spare folding chairs for the open house?', null, $this->rid());
        $this->assertSame(['vehicle' => 1, 'station' => 1, 'support' => 1], $this->assistantCounts());

        // Member pages.
        $this->actAs($this->alpha);
        $this->get('/employee/my-vehicle-inspections/'.$inspection->id)->assertOk()->assertSee('Support Services');
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->actingAs($supportMember);
        Livewire::test(MyMessages::class)->call('openThread', $message->thread_id)->assertSee('Support Services')->assertDontSee('AI Response');

        // Staff pages.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->stationStaff());
        Livewire::test(ViewStationRequest::class, ['record' => $stationRequestId])->assertSuccessful()->assertSee('Support Services (automated)');
        $this->actingAs($this->logisticsResponder());
        Livewire::test(EmployeeMessages::class)->call('openThread', $message->thread_id)->assertSee('Support Services (automated)');

        // The station API keeps every field it always had and only adds new ones.
        $this->actingAsCanonicalUser($this->requester);
        $list = $this->getJson('/api/public/stations/'.$this->station(1)->id.'/requests')->assertOk()->json('data.0');
        foreach (['id', 'request_number', 'title', 'status', 'priority', 'updates', 'items', 'acknowledged_at', 'current_public_response'] as $key) {
            $this->assertArrayHasKey($key, $list, "{$key} must still be there");
        }
        foreach ($list['updates'] as $update) {
            foreach (['id', 'status', 'public_note', 'created_at'] as $key) {
                $this->assertArrayHasKey($key, $update);
            }
        }
        $this->assertArrayNotHasKey('changed_by_user_id', $list['updates'][0]);
        $this->assertArrayNotHasKey('metadata', $list['updates'][0]);
        $this->assertArrayHasKey('ai_receipt', $list);

        // The additive migrations leave every new column nullable, so an older image keeps inserting.
        foreach ([
            ['station_request_updates', 'automation_intake_id'], ['station_supply_requests', 'ai_receipt_text'], ['station_supply_requests', 'ai_receipt_intake_id'],
            ['employee_logistics_messages', 'ai_intake_event_id'], ['employee_logistics_threads', 'last_assistant_message_id'],
        ] as [$table, $column]) {
            $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);
            $this->assertNotNull($definition, "{$table}.{$column}");
            $this->assertTrue((bool) $definition['nullable'], "{$table}.{$column} must be nullable");
        }
        $this->assertNotNull($message->id);
    }

    // ===================================================================================== 27-28 no email, no other workflow writes

    public function test_27_nothing_in_the_new_code_can_send_email(): void
    {
        $this->on();
        Queue::fake();
        $this->checkout('engine2', [['Hose Clamps'], ['SCBA']]);
        $this->stationRequestViaApi('Kitchen outlet is sparking', 'The outlet is sparking.', 'Wall outlet');
        $this->service()->startThread($this->messagingMember('Mail Member'), 'Where do I pick up a key?', null, $this->rid());
        $this->processAll();
        $this->reapply();
        $backlog = app(BacklogService::class);
        $backlog->preview($this->vehicleStaff());

        $this->assertNoEmailAnywhere();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(['database'], (new AiResponseNotification('x', null))->via($this->alpha));

        // And by construction: none of the new engine, triage or job code references a mail channel or a Mailable.
        $files = [];
        foreach (['app/Services/AiAutomation', 'app/Services/SupportTriage', 'app/Jobs/AiAutomation', 'app/Jobs/SupportTriage', 'app/Services/Notifications/SupportAlertLevelFilter.php', 'app/Services/Notifications/SupportAlertLevelPreferences.php'] as $path) {
            $absolute = base_path($path);
            $files = [...$files, ...(is_dir($absolute) ? array_map(fn ($f): string => $f->getPathname(), iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)))) : [$absolute])];
        }
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $source = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/Mail::|MailMessage|Mailable|BudgetedMailChannel|SendBudgetedEmail|Notification::route\(/', $source, $file);
        }
    }

    public function test_28_the_automation_writes_no_ems_roster_personnel_narcotics_or_captain_5_records(): void
    {
        $this->on();
        Queue::fake();
        $this->checkout('rescue', [['Backboard / Scoop Stretcher'], ['Kitty Litter']]);
        $this->checkout('engine2', [['Life-Pak'], ['SCBA Bottles'], ['SCBA']]);
        $this->stationRequestViaApi('Kitchen outlet is sparking', 'The outlet is sparking.', 'Wall outlet');
        $this->service()->startThread($this->messagingMember('Audit Member'), 'Do you have spare folding chairs?', null, $this->rid());
        $forbidden = $this->forbiddenTables();
        $this->assertNotEmpty($forbidden);
        $before = $this->countsOf($forbidden);

        $this->audit(function (): void {
            $this->processAll();
            $this->reapply();
            app(TriageProjector::class)->previewReapply();
            app(BacklogService::class)->preview(null);
        });

        $this->assertSame([], array_values(array_intersect(array_keys($this->lastWrites), $forbidden)));
        $this->assertSame($before, $this->countsOf($forbidden));
        $this->assertNotEmpty($this->lastWrites, 'The audit really ran.');
        $unexpected = array_diff(array_keys($this->lastWrites), [
            'ai_intake_events', 'apparatus_issue_messages', 'station_request_updates', 'employee_logistics_messages', 'employee_logistics_threads',
            'notifications', 'support_triage_levels', 'ai_backlog_runs', 'ai_model_calls', 'sessions', 'cache', 'cache_locks', 'jobs',
        ]);
        $this->assertSame([], array_values($unexpected), 'Tables written by the automation outside the expected set: '.implode(', ', $unexpected));
    }

    // ===================================================================================== 29-30 replay and agreement

    public function test_29_an_offline_replayed_checkout_makes_one_inspection_one_defect_one_reply_and_one_bell(): void
    {
        $this->on();
        $this->actAs($this->alpha);
        $payload = $this->payload($this->unit, 1020, 401.2);
        [$c, $i] = $this->itemIndex($payload, 'Hose Clamps');
        $this->markItem($payload, $c, $i, 'Missing', ['notes' => 'Not in the compartment.']);

        // The phone queued it offline and replays it three times; a second device sends it once more.
        $this->submit($payload)->assertCreated();
        $this->submit($payload)->assertOk();
        $this->submit($payload)->assertOk();
        $this->actAs($this->alpha);
        $this->submit($payload)->assertOk();

        $this->assertSame(1, ApparatusInspection::query()->count());
        $this->assertSame(1, ApparatusDefect::query()->count());
        $this->assertSame(1, SupportTriageLevel::query()->count());
        $this->assertCount(1, $this->assistantMessages());
        $this->assertSame(1, AiIntakeEvent::query()->count());
        $this->assertSame(1, $this->aiBells()->count());
        $this->assertNoEmailAnywhere();
    }

    public function test_30_the_live_reply_and_the_backlog_preview_agree_for_the_same_record_and_policy_version(): void
    {
        // Submitted while OFF, so the same checkout is a backlog candidate.
        $this->checkout('engine2', [['Gas Meter']]);
        $this->checkout('engine', [['SCBA']]);
        $this->assertSame(0, AiIntakeEvent::query()->count());

        $backlog = app(BacklogService::class);
        $run = $backlog->preview(null);
        $preview = $run->preview;
        $this->assertSame(2, $preview['would_send']);
        $this->assertSame(['critical' => 1, 'routine' => 1], $this->sorted($preview['risk']));

        // The live composition of the very same facts, under the same policy version.
        $version = app(\App\Services\SupportTriage\Contracts\EquipmentClassifier::class)->policyVersion();
        $adapter = app(VehicleAdapter::class);
        $live = [];
        foreach (ApparatusInspection::query()->orderBy('id')->get() as $inspection) {
            $facts = $adapter->facts(new AiIntakeEvent([
                'domain' => 'vehicle', 'source_type' => 'apparatus_inspection', 'source_id' => $inspection->id, 'event_key' => 'received', 'origin' => 'live', 'epoch' => 1, 'state' => 'queued',
            ]));
            $live[] = app(ResponseComposer::class)->compose($facts, CarbonImmutable::now(), true);
        }
        $this->assertSame(['routine', 'critical'], array_map(fn ($reply): string => $reply->riskTier, $live));
        $this->assertEqualsCanonicalizing(array_keys($preview['replies']), array_map(fn ($reply): string => $reply->text, $live));
        foreach (SupportTriageLevel::query()->get() as $row) {
            $this->assertSame($version, (int) $row->policy_version);
        }

        // And what the backlog actually posts is what it previewed.
        $this->on();
        Queue::fake();
        $this->assertTrue($backlog->start($run->fresh()));
        $epoch = app(AiAutomationSwitch::class)->epoch();
        for ($n = 0; $n < 10 && $backlog->processBatch((int) $run->id, $epoch); $n++) {
        }
        $sent = AiIntakeEvent::query()->where('state', AiIntakeEvent::STATE_SENT)->get();
        $this->assertCount(2, $sent);
        $this->assertEqualsCanonicalizing(array_keys($preview['replies']), $sent->pluck('reply_text')->all());
        $this->assertEqualsCanonicalizing(['routine', 'critical'], $sent->pluck('risk_tier')->all());
        $this->assertSame(0, $this->aiBells()->count(), 'Backlog replies stay silent.');
        $this->assertNoEmailAnywhere();
    }

    public function test_31_a_checklist_item_named_out_of_service_tags_is_a_routine_low_item_not_a_hazard(): void
    {
        $this->on();
        $this->checkout('engine2', [['Gaskets/Out of Service Tags']]);

        $this->assertSame(C::LOW, $this->dashboardLevel(ApparatusDefect::query()->sole()));
        $this->assertStringContainsString("wasn't misplaced", $this->onlyReply());
        $this->assertNotSame('critical', AiIntakeEvent::query()->sole()->risk_tier);

        // What the member writes about the item is still checked for danger words.
        $this->checkout('engine', [['Gaskets/Out of Service Tags', 'Damaged', ['notes' => 'Smoke was coming from the compartment.']]]);
        $this->assertSame(C::HIGH, $this->dashboardLevel(ApparatusDefect::query()->orderByDesc('id')->first()));
    }

    // ============================================================================================ helpers

    /** The queued "Update open issues" job, run now (Queue::fake holds dispatchSync too). */
    private function reapply(): void
    {
        (new ReapplyTriagePolicy)->handle(app(TriageProjector::class));
    }

    private int $fannedOut = 0;

    /** Runs the staff alert fan-out jobs a request queued, on the web guard a queue worker would use. */
    private function fanOut(): void
    {
        Auth::shouldUse('web');
        $jobs = Queue::pushed(FanOutNotification::class)->values();
        foreach ($jobs->slice($this->fannedOut) as $job) {
            $job->handle(app(NotificationDispatchService::class));
        }
        $this->fannedOut = $jobs->count();
    }

    private function on(): void
    {
        app(AiAutomationSwitch::class)->turnOn(null, 'console');
    }

    /** @var array<string, int> */
    private array $lastWrites = [];

    /** Runs the work with a write audit open and keeps the tables it wrote (statement counts) in $lastWrites. */
    private function audit(callable $work): void
    {
        $this->writes = [];
        try {
            $work();
        } finally {
            $this->lastWrites = $this->writes ?? [];
            $this->writes = null;
        }
    }

    private function unitFor(string $template): Apparatus
    {
        if ($template === 'engine2') {
            return $this->unit;
        }
        if ($template === 'engine') {
            return $this->frontline;
        }

        return $this->units[$template] ??= Apparatus::query()->create([
            'station_id' => $this->unit->station_id, 'vin' => 'VIN-ACC-'.$template, 'assignment' => 'Station 2', 'unit_id' => strtoupper(substr($template, 0, 3)).'9',
            'designation' => strtoupper(substr($template, 0, 3)).'9', 'name' => ucfirst($template).' 9', 'type' => ucfirst(preg_replace('/\d+/', '', $template)),
            'vehicle_number' => 'V-ACC-'.$template, 'status' => 'In Service', 'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => $template, 'current_engine_hours' => 100, 'current_miles' => 100,
        ]);
    }

    /**
     * Submits one Daily Checkout through the real endpoint. A finding is [checklist item name, status, options];
     * options may carry notes, significant, and `as` (the member typed another item name than the checklist one, so
     * the real name is rewritten before it is sent).
     *
     * @param  list<array{0: string, 1?: string, 2?: array<string, mixed>}>  $findings
     */
    private function checkout(string $template, array $findings, ?User $member = null): ApparatusInspection
    {
        $unit = $this->unitFor($template);
        $this->actAs($member ?? $this->alpha);
        $payload = $this->payload($unit, (int) $unit->fresh()->current_miles + 20, (float) $unit->fresh()->current_engine_hours + 1.2);
        $relabel = [];
        foreach ($findings as $finding) {
            [$c, $i] = $this->itemIndex($payload, $finding[0]);
            $options = $finding[2] ?? [];
            if (isset($options['as'])) {
                $relabel[$finding[0]] = (string) $options['as'];
            }
            unset($options['as']);
            $this->markItem($payload, $c, $i, $finding[1] ?? 'Missing', $options + ['notes' => $options['notes'] ?? 'Not in the compartment.']);
        }

        $inspection = ApparatusInspection::query()->findOrFail($this->submit($payload, $unit)->assertCreated()->json('id'));
        $this->relabelDefects($unit, $relabel);

        return $inspection;
    }

    /**
     * The checklists name no hood, bunker coat or DeWalt battery, and the endpoint only accepts checklist names, so
     * for those the stored issue is renamed (as the existing end-to-end suite does) and re-projected before the reply.
     *
     * @param  array<string, string>  $names  checklist name => name to report
     */
    private function relabelDefects(Apparatus $unit, array $names): void
    {
        foreach ($names as $from => $to) {
            $defect = ApparatusDefect::query()->where('apparatus_id', $unit->id)->where('item', $from)->orderByDesc('id')->firstOrFail();
            $defect->forceFill(['item' => $to])->saveQuietly();
            app(TriageProjector::class)->refreshDefect($defect->refresh());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int}
     */
    private function itemIndex(array $payload, string $name): array
    {
        foreach ($payload['compartments'] as $c => $compartment) {
            foreach ($compartment['items'] as $i => $item) {
                if ($item['name'] === $name && ! array_key_exists('value', $item) && $item['status'] === 'Present') {
                    return [$c, $i];
                }
            }
        }
        $this->fail("Checklist item '{$name}' not found as a plain item.");
    }

    /** Runs every queued ledger row (the jobs are held with Queue::fake) in id order. */
    private function processAll(): void
    {
        foreach (AiIntakeEvent::query()->whereIn('state', AiIntakeEvent::OPEN_STATES)->orderBy('id')->pluck('id') as $id) {
            app(IntakeProcessor::class)->process((int) $id);
        }
    }

    private function assistantMessages(): \Illuminate\Database\Eloquent\Collection
    {
        return ApparatusIssueMessage::query()->where('author_context', 'assistant')->orderBy('id')->get();
    }

    private function onlyReply(): string
    {
        $messages = $this->assistantMessages();
        $this->assertCount(1, $messages, 'Exactly one automated reply.');

        return (string) $messages[0]->body;
    }

    /** @return array{vehicle: int, station: int, support: int} */
    private function assistantCounts(): array
    {
        return [
            'vehicle' => $this->assistantMessages()->count(),
            'station' => StationRequestUpdate::query()->autoReceipts()->count(),
            'support' => EmployeeLogisticsMessage::query()->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->count(),
        ];
    }

    private function aiBells(): \Illuminate\Database\Query\Builder
    {
        return DB::table('notifications')->where('type', AiResponseNotification::class);
    }

    private function assertNoEmailAnywhere(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        foreach ($this->delivered as [$class, $channel]) {
            if ($class === AiResponseNotification::class) {
                $this->assertSame('database', $channel, 'An AI reply may only ring the in-app bell.');
            }
        }
        $this->assertSame([], array_filter($this->delivered, static fn (array $d): bool => $d[0] === AiResponseNotification::class && $d[1] !== 'database'));
    }

    // ---- staff views

    private function vehicleStaff(): User
    {
        return $this->vehicleStaff ??= $this->persona('admin', ['admin.access', 'admin.fleet.view', 'admin.fleet.manage']);
    }

    private function stationStaff(): User
    {
        return $this->stationStaff ??= $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
    }

    /** The level the vehicle dashboard shows for a defect. */
    private function dashboardLevel(ApparatusDefect $defect): string
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (app(ApparatusDefectSource::class)->rows($this->vehicleStaff(), DashboardQuery::now(), 200) as $row) {
            if ((int) $row->id === (int) $defect->id) {
                return (string) $row->severity;
            }
        }
        $this->fail("Defect {$defect->id} is not on the dashboard.");
    }

    /** @return list<\App\Support\Dashboards\DashboardIssue> */
    private function stationRows(): array
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // A member API call leaves the default guard on the one it used; staff permissions are web-guard permissions.
        \Illuminate\Support\Facades\Auth::shouldUse('web');

        return array_values(app(StationRequestSource::class)->rows($this->stationStaff(), DashboardQuery::now(), 200));
    }

    private function stationDashboardLevel(int $requestId): string
    {
        foreach ($this->stationRows() as $row) {
            if ((int) $row->id === $requestId) {
                return (string) $row->severity;
            }
        }
        $this->fail("Station request {$requestId} is not on the dashboard.");
    }

    // ---- station requests and alerts

    private function stationRequestViaApi(string $title, string $description, string $item): int
    {
        $this->actingAsCanonicalUser($this->requester);
        $response = $this->postJson('/api/public/station_request', [
            'client_submission_id' => (string) Str::uuid(),
            'station_id' => $this->station(1)->id,
            'room_id' => $this->room->id,
            'requested_by_employee_id' => $this->requesterEmployee->id,
            'request_type' => 'repair_service',
            'subject_type' => 'room',
            'title' => $title,
            'description' => $description,
            'priority' => 'normal',
            'items' => [['item_name' => $item, 'category' => 'appliance', 'quantity' => 1, 'reason' => 'Damaged/Broken', 'condition' => 'needs_repair']],
        ]);

        return (int) $response->assertCreated()->json('data.id');
    }

    /** @param array{low: bool, medium: bool, high: bool} $levels */
    private function alertStaff(string $name, array $levels): User
    {
        $staff = $this->newReviewer($name);
        $staff->givePermissionTo(Permission::findOrCreate('admin.stations.view', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $staff = $staff->fresh();
        app(SupportAlertLevelPreferences::class)->save($staff, $levels);

        return $staff;
    }

    /** @return list<int> station request ids this person was alerted about, in id order */
    private function alertedRequests(User $staff): array
    {
        $ids = [];
        foreach (Notification::sent($staff, NewSubmissionNotification::class) as $notification) {
            $key = (string) $notification->toDatabase($staff)['provenance']['event_key'];
            if (preg_match('/:(\d+):created$/', $key, $m) === 1) {
                $ids[] = (int) $m[1];
            }
        }
        sort($ids);

        return $ids;
    }

    // ---- audit helpers

    /** @return list<string> */
    private function forbiddenTables(): array
    {
        $tables = array_map(static fn ($t): string => is_array($t) ? (string) $t['name'] : (string) $t, Schema::getTables());

        return array_values(array_filter($tables, static fn (string $t): bool => preg_match('/(^|_)(ems|narcotic|narcotics|roster|personnel|kiosk)(_|$)|captain5|captain_5/i', $t) === 1));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function countsOf(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $values
     * @return array<string, int>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
