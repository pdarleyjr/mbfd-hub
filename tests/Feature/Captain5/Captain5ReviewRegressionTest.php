<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Captain5\Captain5ActingService;
use App\Services\Captain5\Captain5AdministrationService;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Captain5\Captain5Workspace;
use App\Services\Inspections\IssueMessageService;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/** Pins the fixes from the independent pre-release review of the Captain 5 branch. */
final class Captain5ReviewRegressionTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const RESCUE_ITEM = 'compartment_c-item-3';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00');
    }

    public function test_a_captain5_officer_replies_as_a_crew_member_on_their_own_inspection(): void
    {
        $acting = $this->officer('Lieutenant', 'Rescue Lieutenant');
        $this->actingAssignment($acting, $this->captain5Administrator());
        [$defect] = $this->reportMissing($this->rescue, $acting, [self::RESCUE_ITEM]);

        // The employee portal posts without a visibility, exactly like MyVehicleInspectionView does.
        $reply = app(IssueMessageService::class)->post($acting->fresh(), [
            'defect_id' => $defect->id, 'body' => 'Checked again, still missing.', 'client_message_id' => (string) Str::uuid(),
        ]);
        self::assertSame(ApparatusIssueMessage::CONTEXT_MEMBER, $reply->author_context);
        self::assertSame(ApparatusIssueMessage::VISIBILITY_MEMBER, $reply->visibility);
    }

    public function test_denied_seat_holders_are_never_notified_and_cannot_ask_to_act(): void
    {
        $admin = $this->captain5Administrator();
        $denied = $this->officer('Captain', 'Denied Captain');
        $this->seat($denied, 'Captain 5', 'A Shift');
        $other = $this->officer('Captain', 'Other Captain');
        $this->seat($other, 'Captain 5', 'B Shift');
        app(Captain5AdministrationService::class)->setOverride($admin, (int) $denied->employee_profile_id, 'deny', 'Under review');
        $this->forgetCaptain5Memo();

        [$defect] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), [self::RESCUE_ITEM]);
        $recipients = DB::table('inspection_notification_deliveries')->where('event_key', 'captain5:inspection:'.$defect->first_reported_inspection_id.':findings')
            ->pluck('recipient_user_id')->map(fn ($id): int => (int) $id)->all();
        self::assertNotContains((int) $denied->id, $recipients, 'A denied on-duty seat holder is skipped');
        self::assertSame([(int) $other->id], $recipients, 'Falls back to the eligible seat holders');

        $this->expectException(AuthorizationException::class);
        app(Captain5ActingService::class)->request($denied->fresh(), ['kind' => 'act', 'duration' => '12h']);
    }

    public function test_account_less_members_cannot_ask_to_act(): void
    {
        $noAccount = $this->officer('Lieutenant');
        User::query()->whereKey($noAccount->id)->update(['account_status' => 'disabled']);
        $this->forgetCaptain5Memo();
        $this->expectException(AuthorizationException::class);
        app(Captain5ActingService::class)->request($noAccount->fresh(), ['kind' => 'act', 'duration' => '12h']);
    }

    public function test_findings_recorded_on_a_reserve_truck_from_a_rescue_checklist_stay_in_scope(): void
    {
        $reserve = Apparatus::query()->create([
            'station_id' => $this->rescue->station_id, 'vin' => 'VIN-RSV', 'unit_id' => 'AT9', 'designation' => 'AT9',
            'name' => 'Air Truck 9', 'type' => 'Air Truck', 'vehicle_number' => 'V-AT9', 'status' => 'In Service',
        ]);
        $inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->rescue->id, 'processed_apparatus_id' => $reserve->id, 'operator_name' => 'Crew', 'rank' => 'Firefighter',
            'shift' => 'A', 'unit_number' => 'R2', 'review_status' => 'approved', 'processing_status' => 'accepted', 'completed_at' => now(),
        ]);
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $reserve->id, 'apparatus_inspection_id' => $inspection->id, 'first_reported_inspection_id' => $inspection->id,
            'compartment' => 'L2', 'item' => 'Bolt Cutters', 'item_key' => self::RESCUE_ITEM, 'status' => 'open', 'issue_type' => 'missing',
        ]);
        $this->forgetCaptain5Memo();

        self::assertTrue(app(Captain5Workspace::class)->openIssues()->contains('id', $defect->id));
    }

    public function test_out_of_scope_issues_never_push_in_scope_issues_out_of_the_queue(): void
    {
        [$rescueDefect] = $this->reportMissing($this->rescue, $this->newMember('Reporter'), [self::RESCUE_ITEM]);
        $this->reportAllPresent($this->unit, $this->newMember('Engine Crew'));
        $inspection = ApparatusInspection::query()->where('apparatus_id', $this->unit->id)->latest('id')->firstOrFail();
        foreach (range(1, 650) as $i) { // newer Fleet-only issues on the Engine
            DB::table('apparatus_defects')->insert([
                'apparatus_id' => $this->unit->id, 'apparatus_inspection_id' => $inspection->id, 'compartment' => 'Front Cab',
                'item' => 'Fleet item '.$i, 'item_key' => 'fleet-'.$i, 'compartment_key' => 'front_cab', 'status' => 'open',
                'issue_type' => 'missing', 'resolved' => false, 'action_status' => 'reported', 'severity' => 'routine', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->forgetCaptain5Memo();

        $workspace = app(Captain5Workspace::class);
        self::assertTrue($workspace->openIssues()->contains('id', $rescueDefect->id));
        $this->actAs($this->officer('Lieutenant'));
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonPath('issues_truncated', false)->assertJsonPath('counts.open', 1);
    }

    public function test_duty_date_is_correct_across_daylight_saving_changes(): void
    {
        $calendar = new DutyCalendar;
        // Spring forward 2027-03-14: 08:30 EDT is the new duty day; 07:30 EDT still the previous one.
        self::assertSame('2027-03-14', $calendar->dutyDate(CarbonImmutable::parse('2027-03-14 08:30:00', 'America/New_York'))->format('Y-m-d'));
        self::assertSame('2027-03-13', $calendar->dutyDate(CarbonImmutable::parse('2027-03-14 07:30:00', 'America/New_York'))->format('Y-m-d'));
        // Fall back 2026-11-01: 07:30 EST is still the previous duty day.
        self::assertSame('2026-10-31', $calendar->dutyDate(CarbonImmutable::parse('2026-11-01 07:30:00', 'America/New_York'))->format('Y-m-d'));
        self::assertSame('2026-11-01', $calendar->dutyDate(CarbonImmutable::parse('2026-11-01 08:00:00', 'America/New_York'))->format('Y-m-d'));
    }

    public function test_retrying_one_ledger_row_sends_only_that_notification(): void
    {
        $captain = $this->officer('Captain');
        $this->seat($captain, 'Captain 5', 'A Shift');
        $ems = $this->officer('Captain');
        $this->seat($ems, 'EMS', 'D Shift');
        $this->actAs($member = $this->newMember('Member'));
        $payload = $this->payload($this->rescue, 2010, 300.5);
        foreach ($payload['compartments'] as $ci => $compartment) {
            foreach ($compartment['items'] as $ii => $item) {
                if ($item['id'] === self::RESCUE_ITEM) {
                    $this->markItem($payload, $ci, $ii, 'Damaged', ['significant' => true]);
                }
            }
        }
        $this->submit($payload, $this->rescue)->assertSuccessful();
        $inspectionId = ApparatusInspection::query()->latest('id')->value('id');
        Notification::fake();

        self::assertTrue(app(Captain5Notifier::class)->redeliver('captain5:inspection:'.$inspectionId.':findings', Captain5Notifier::KIND_NEW_FINDINGS, (int) $captain->id));
        Notification::assertSentToTimes($captain, InspectionEventNotification::class, 1);
        Notification::assertSentTo($captain, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === Captain5Notifier::KIND_NEW_FINDINGS);
    }

    public function test_attachment_downloads_answer_404_for_anything_not_visible(): void
    {
        $this->actAs($this->officer('Lieutenant'));
        $this->get('/command-communications/attachments/'.str_repeat('A', 26))->assertNotFound();
    }
}
