<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Jobs\FanOutNotification;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\Station;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Fleet\CheckoutVehicleReport;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A queued checkout from a phone on the old app names a different truck. It is a swap, in the checkout's own transaction,
 * and the findings go on the truck the crew reported. Docs: apparatus-changeouts.md section 4.5.
 */
final class CheckoutReportedTruckTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Below the checkout's readings, so the readings are accepted on the reported truck.
        $this->reserve->forceFill(['current_miles' => 900, 'current_engine_hours' => 300])->save();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function oldPhonePayload(string $number = 'V-909', bool $withFinding = false, array $extra = ['swap_answer' => 'swap_completed']): array
    {
        return $this->payload(['vehicle_number' => $number, ...$extra], $withFinding);
    }

    private function inspection(): ApparatusInspection
    {
        return ApparatusInspection::query()->latest('id')->firstOrFail();
    }

    /** Findings can raise alerts of their own. This counts only the "Truck changed" one. */
    private function assertTruckChangedAlerts(int $times): void
    {
        $this->assertCount($times, Queue::pushed(FanOutNotification::class, static fn (FanOutNotification $job): bool => $job->categoryKey === User::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED));
    }

    public function test_it_commits_the_swap_and_processes_the_checkout_on_the_reported_truck(): void
    {
        $payload = $this->oldPhonePayload(withFinding: true, extra: ['swap_answer' => 'swap_completed', 'note' => 'E2 is in the shop.']);

        $this->postJson($this->submissionUrl(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted')->assertJsonMissingPath('vehicle_swap_pending');

        $new = $this->reserve->fresh();
        $old = $this->unit->fresh();
        $this->assertSame(['E2', 'In Service', 'required'], [$new->designation, $new->getAttribute('status'), $new->getAttributes()['daily_checkout_requirement']]);
        $this->assertSame('Out of Service', $old->getAttribute('status'));
        $this->assertSame('V-202', $old->vehicle_number, 'A truck keeps its number.');

        $this->assertSame(1020, $new->current_miles);
        $this->assertSame(1000, $old->current_miles, 'The old truck was not the one read.');
        $this->assertEquals(400, $old->current_engine_hours);
        $this->assertSame(1, ApparatusDefect::query()->where('apparatus_id', $new->id)->count());
        $this->assertSame(0, ApparatusDefect::query()->where('apparatus_id', $old->id)->count());

        $inspection = $this->inspection();
        $this->assertSame([$this->unit->id, $new->id, 'accepted', 'approved', 'E2', 'V-202'], [$inspection->apparatus_id, $inspection->processed_apparatus_id, $inspection->processing_status, $inspection->review_status, $inspection->designation_at_time, $inspection->vehicle_number]);

        $changeout = ApparatusChangeout::query()->sole();
        $this->assertSame([$this->unit->id, $new->id, 'E2', 'V-202', 'V-909', 'completed'], [$changeout->from_apparatus_id, $changeout->to_apparatus_id, $changeout->unit_designation, $changeout->from_vehicle_number, $changeout->to_vehicle_number, $changeout->status]);
        $this->assertSame('Reported at Daily Checkout by Swap Reporter. No equipment list was recorded. E2 is in the shop.', $changeout->note);
        $this->assertSame(0, $changeout->items()->count(), 'Nobody said what was taken or left.');
        $this->assertSame('daily_checkout', $changeout->move_audit['source']);
        $this->assertNotSame($inspection->client_submission_id, $changeout->client_submission_id);

        $event = ApparatusInspectionReviewEvent::query()->where('status', CheckoutVehicleReport::EVENT_CHANGED)->sole();
        $this->assertSame($changeout->id, $event->metadata['changeout_id']);
    }

    public function test_it_needs_no_swap_answer(): void
    {
        $this->postJson($this->submissionUrl(), $this->oldPhonePayload(extra: []))->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame('E2', $this->reserve->fresh()->designation);
    }

    public function test_admins_are_told_once_and_a_replay_creates_nothing(): void
    {
        $payload = $this->oldPhonePayload(withFinding: true);

        $first = $this->postJson($this->submissionUrl(), $payload)->assertCreated();
        $this->assertSame(1, ApparatusChangeout::query()->unacknowledged()->count(), 'The admin alert.');
        $this->assertTruckChangedAlerts(1);
        $rows = [Apparatus::query()->count(), ApparatusChangeout::query()->count(), ApparatusInspection::query()->count(), ApparatusDefect::query()->count(), ApparatusInspectionReviewEvent::query()->count()];

        $this->postJson($this->submissionUrl(), $payload)->assertOk()->assertJsonPath('id', $first->json('id'));

        $this->assertSame($rows, [Apparatus::query()->count(), ApparatusChangeout::query()->count(), ApparatusInspection::query()->count(), ApparatusDefect::query()->count(), ApparatusInspectionReviewEvent::query()->count()]);
        $this->assertTruckChangedAlerts(1);
        $this->assertSame('E2', $this->reserve->fresh()->designation);
    }

    public function test_it_completes_the_units_scheduled_change(): void
    {
        $alert = $this->swapAlert($this->unit);

        $this->postJson($this->submissionUrl(), $this->oldPhonePayload())->assertCreated();

        $this->assertSame('completed', $alert->fresh()->status);
        $this->assertSame($alert->id, ApparatusChangeout::query()->sole()->alert_id);
    }

    public function test_a_truck_holding_another_unit_trades_with_it(): void
    {
        $this->frontline->forceFill(['current_miles' => 900, 'current_engine_hours' => 300])->save();

        $this->postJson($this->submissionUrl(), $this->oldPhonePayload('V-303'))->assertCreated();

        $this->assertSame('E2', $this->frontline->fresh()->designation);
        $this->assertSame('E3', $this->unit->fresh()->designation);
        $this->assertSame('Out of Service', $this->unit->fresh()->getAttribute('status'));
        $this->assertSame('E3', ApparatusChangeout::query()->sole()->move_audit['displaced_unit']);
    }

    public function test_a_typed_unknown_number_creates_its_records(): void
    {
        $this->postJson($this->submissionUrl(), $this->oldPhonePayload('X-77'))->assertCreated();

        $truck = Apparatus::query()->where('vehicle_number', 'X-77')->sole();
        $this->assertSame(['E2', 'In Service'], [$truck->designation, $truck->getAttribute('status')]);
        $this->assertTrue(UnitMasterVehicle::query()->where('veh_number', 'X-77')->sole()->needs_fleet_review);
        $changeout = ApparatusChangeout::query()->sole();
        $this->assertFalse($changeout->to_vehicle_listed);
        $this->assertTrue($changeout->move_audit['new_truck_record']);
        $this->assertSame($truck->id, $this->inspection()->processed_apparatus_id);
    }

    public function test_a_failure_rolls_back_both_the_inspection_and_the_swap(): void
    {
        ApparatusInspectionReviewEvent::creating(static function (): void {
            throw new RuntimeException('The database went away.');
        });
        $inspections = ApparatusInspection::query()->count();

        try {
            $this->withoutExceptionHandling()->postJson($this->submissionUrl(), $this->oldPhonePayload(withFinding: true));
            $this->fail('The submission should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The database went away.', $exception->getMessage());
        }

        $this->assertSame($inspections, ApparatusInspection::query()->count());
        $this->assertSame(0, ApparatusChangeout::query()->count());
        $this->assertSame(0, ApparatusDefect::query()->count());
        $this->assertSame(['E2', 'In Service'], [$this->unit->fresh()->designation, $this->unit->fresh()->getAttribute('status')]);
        $this->assertSame('RE1', $this->reserve->fresh()->designation);
        Queue::assertNotPushed(FanOutNotification::class);
    }

    public function test_a_checkout_on_the_assigned_truck_is_unchanged(): void
    {
        $this->postJson($this->submissionUrl(), $this->payload(['vehicle_number' => 'V-202'], true))->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame(0, ApparatusChangeout::query()->count());
        $this->assertSame(0, ApparatusInspectionReviewEvent::query()->whereIn('status', [CheckoutVehicleReport::EVENT_CHANGED, CheckoutVehicleReport::EVENT_FLAGGED, CheckoutVehicleReport::EVENT_NAMED])->count());
        $this->assertSame($this->unit->id, $this->inspection()->processed_apparatus_id);
        $this->assertSame(1020, $this->unit->fresh()->current_miles);
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertTruckChangedAlerts(0);
    }

    public function test_an_ill_formed_number_is_flagged_not_refused(): void
    {
        foreach (['<b>9', str_repeat('A', 40)] as $number) {
            $this->postJson($this->submissionUrl(), $this->oldPhonePayload($number, extra: ['swap_answer' => 'unknown']))->assertCreated()->assertJsonPath('processing_status', 'accepted');

            $this->assertSame($this->unit->id, $this->inspection()->processed_apparatus_id);
            $event = ApparatusInspectionReviewEvent::query()->where('apparatus_inspection_id', $this->inspection()->id)->where('status', CheckoutVehicleReport::EVENT_FLAGGED)->sole();
            $this->assertStringContainsString('not a vehicle number', (string) $event->internal_note);
            $this->assertStringContainsString('Please review', (string) $event->internal_note);
        }

        $this->assertSame(0, ApparatusChangeout::query()->count());
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertSame('In Service', $this->unit->fresh()->getAttribute('status'));
    }

    public function test_a_row_that_just_changed_out_reporting_its_own_truck_moves_nothing(): void
    {
        $payload = $this->oldPhonePayload('V-202');
        $this->moveToReserve()->assertCreated();
        $location = $this->reserve->fresh()->designation;

        $this->postJson($this->submissionUrl(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame(1, ApparatusChangeout::query()->count());
        $this->assertSame($location, $this->reserve->fresh()->designation);
        $this->assertSame($this->unit->id, $this->inspection()->processed_apparatus_id);
        $this->assertSame('E2', $this->inspection()->designation_at_time);
    }

    public function test_the_truck_the_unit_already_moved_to_is_never_moved_to_twice(): void
    {
        $payload = $this->oldPhonePayload('V-909', withFinding: true);
        $this->moveToReserve()->assertCreated();

        $this->postJson($this->submissionUrl(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertSame(1, ApparatusChangeout::query()->count());
        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame($this->reserve->id, $this->inspection()->processed_apparatus_id);
        $this->assertSame(1, ApparatusDefect::query()->where('apparatus_id', $this->reserve->id)->count());
        $this->assertSame(1020, $this->reserve->fresh()->current_miles);
    }

    public function test_a_trade_back_is_not_replayed_from_a_row_that_now_holds_another_unit(): void
    {
        $payload = $this->oldPhonePayload('V-303');
        $this->frontline->forceFill(['current_miles' => 900, 'current_engine_hours' => 300])->save();
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->frontline->id])->assertCreated();

        $this->postJson($this->submissionUrl(), $payload)->assertCreated();

        $this->assertSame(1, ApparatusChangeout::query()->count());
        $this->assertSame('E2', $this->frontline->fresh()->designation);
        $this->assertSame('E3', $this->unit->fresh()->designation);
    }

    public function test_a_third_truck_from_a_row_with_no_unit_moves_nothing(): void
    {
        $known = $this->oldPhonePayload('V-303');
        $unknown = $this->oldPhonePayload('NOPE-1');
        $this->frontline->forceFill(['current_miles' => 900, 'current_engine_hours' => 300])->save();
        $this->moveToReserve()->assertCreated();

        $this->postJson($this->submissionUrl(), $known)->assertCreated();
        $this->assertSame($this->frontline->id, $this->inspection()->processed_apparatus_id);
        $this->assertSame('E3', $this->frontline->fresh()->designation);

        $this->postJson($this->submissionUrl(), $unknown)->assertCreated();
        $this->assertSame($this->unit->id, $this->inspection()->processed_apparatus_id);
        $this->assertSame(0, Apparatus::query()->where('vehicle_number', 'NOPE-1')->count());
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->where('status', CheckoutVehicleReport::EVENT_FLAGGED)->count());
        $this->assertSame(1, ApparatusChangeout::query()->count());
    }

    public function test_the_fire_boat_session_path_is_unaffected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31 09:00:00', 'America/New_York'));
        $this->actingAsCanonicalUser($this->member);
        $station = Station::query()->create(['station_number' => 6, 'name' => 'Station 6', 'address' => '123 Marine Drive', 'is_active' => true]);
        $boat = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'Fire Boat 6',
            'type' => 'Fire Boat', 'slug' => 'fb6', 'status' => 'In Service', 'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);
        $start = $this->postJson("/api/public/apparatuses/{$boat->id}/inspection-sessions")->assertCreated();
        $cookie = collect($start->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'daily_checkout_inspection_browser');
        $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        $contract = $start->json('inspection_session');
        $checklist = app(DailyCheckoutChecklistResolver::class)->resolve($boat)['checklist'];

        $this->postJson("/api/public/apparatuses/{$boat->id}/inspections", [
            'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => $contract['checklist_hash'],
            'operator_name' => 'Firefighter Example', 'rank' => 'Firefighter', 'shift' => 'A',
            'engine_hours' => 1, 'miles' => 1,
            'compartments' => array_map(static fn (array $compartment): array => [
                'id' => $compartment['id'], 'name' => $compartment['name'],
                'items' => array_map(static fn (array $item): array => ['id' => $item['id'], 'name' => $item['name'], 'status' => 'Present', 'notes' => null], $compartment['items']),
            ], $checklist['compartments']),
            'defects' => [],
            'field_values' => array_map(static fn (array $field): array => [
                'id' => $field['id'],
                'value' => match ($field['inputType']) {
                    'date' => '2026-08-31', 'number' => 1, 'checkbox' => true, default => 'Recorded',
                },
            ], $checklist['fields']),
            'scheduled_tasks' => array_map(static fn (array $task): array => ['id' => $task['id'], 'status' => 'Present', 'notes' => null], $contract['due_tasks']),
            'inspection_session_id' => $contract['id'],
            'inspection_session_token' => $contract['token'],
            'inspection_session_replay_key' => $contract['replay_key'],
        ])->assertCreated();

        $this->assertSame(0, ApparatusChangeout::query()->count());
        $this->assertSame(['Fire Boat 6', 'In Service'], [$boat->fresh()->designation, $boat->fresh()->getAttribute('status')]);
        $this->assertNotSame('pending_vehicle_swap', $this->inspection()->processing_status);
    }
}
