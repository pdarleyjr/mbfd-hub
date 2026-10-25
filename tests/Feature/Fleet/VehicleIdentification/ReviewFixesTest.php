<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Fleet\VehicleIdentificationPlanner;
use App\Services\Fleet\VehicleIdentificationService;
use App\Services\Fleet\VehicleIdentificationSubmission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Independent-review fixes: no duplicate records, honest attribution, no hidden marks, no lost races. */
final class ReviewFixesTest extends IdentificationTestCase
{
    /** @param list<array<string, mixed>> $answers */
    private function crewAnswers(array $answers, ?Apparatus $unit = null, ?User $crew = null): void
    {
        $unit ??= $this->unit;
        $section = app(VehicleIdentificationSubmission::class)->clean(['questions_version' => 'x', 'answers' => $answers], 'vehicle', strict: true)['section'];

        app(VehicleIdentificationService::class)->submitStandalone($unit->fresh(), $section, $crew ?? $this->member, (string) Str::uuid());
    }

    private function masterCount(): int
    {
        return UnitMasterVehicle::query()->count();
    }

    private function events(string $event, ?string $field = null): int
    {
        return ApparatusIdentificationEvent::query()->where('event', $event)
            ->when($field !== null, fn ($query) => $query->where('field', $field))->count();
    }

    private function secondCrew(): User
    {
        return User::factory()->create(['name' => 'Second Crew', 'account_status' => AccountStatus::Active]);
    }

    /** E2 cut loose from its master (V-202), which keeps the given values. */
    private function unlinkedE2(array $masterValues = []): UnitMasterVehicle
    {
        $master = $this->unitMaster();
        $master->forceFill($masterValues + ['serial_number' => null, 'tag_number' => null])->save();
        $this->unlink($this->unit);

        return $master->fresh();
    }

    // 1. Rule d never duplicates the unit's own vehicle.

    public function test_a_unit_whose_own_number_is_on_another_record_is_not_given_a_second_record(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $existing = $this->master('V-505', ['serial_number' => null]);
        $this->unlinkedE2();
        $this->unlink($this->unit->fresh(), 'V-505');
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $this->assertSame($before, $this->masterCount(), 'no duplicate record');
        $unit = $this->unit->fresh();
        $this->assertNull($unit->unit_master_vehicle_id);
        $this->assertSame(Apparatus::MASTER_LINK_NEEDS_REVIEW, $unit->master_link_status);
        $this->assertStringContainsString('own vehicle number V-505 already belongs to another vehicle record', (string) $unit->master_link_note);
        $this->assertSame(0, $this->events('master_created'));
        $this->assertNotNull($existing->fresh());
        Notification::assertSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_unit_whose_own_vin_is_on_another_record_is_not_given_a_second_record(): void
    {
        $this->master('V-606', ['serial_number' => self::VIN_A]);
        $this->unlinkedE2();
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['vehicle_number' => null, 'vin' => self::VIN_A]);
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('vehicle_number', 'V-777')]);

        $this->assertSame($before, $this->masterCount());
        $this->assertStringContainsString('own VIN/HIN', (string) $this->unit->fresh()->master_link_note);
    }

    // 2. Each fill is credited to the member who gave the answer.

    public function test_an_earlier_crews_answer_is_credited_to_that_crew_when_a_later_answer_links_the_unit(): void
    {
        $first = $this->secondCrew();
        $master = $this->unlinkedE2(['serial_number' => self::VIN_A]);

        $this->crewAnswers([$this->answer('plate', 'XE1234')], crew: $first); // nothing to match on yet: waits
        $this->assertNull($this->unit->fresh()->unit_master_vehicle_id);
        $this->crewAnswers([$this->answer('vin', self::VIN_A)], crew: $this->member); // rule a links

        $this->assertSame($master->id, $this->unit->fresh()->unit_master_vehicle_id);
        $this->assertSame('XE1234', $master->fresh()->tag_number);
        $plate = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('field', 'tag_number')->where('source', 'daily_checkout')->sole();
        $this->assertSame($first->id, $plate->user_id, 'credited to the member who read the plate');
        $this->assertStringContainsString('Second Crew', (string) $plate->note);
        $this->assertStringNotContainsString('Swap Reporter', (string) $plate->note);
        $filled = ApparatusIdentificationEvent::query()->where('event', 'master_filled')->where('field', 'plate')->sole();
        $this->assertSame($first->id, $filled->actor_user_id);
        $this->assertSame($this->member->id, ApparatusIdentificationEvent::query()->where('event', 'matched')->sole()->actor_user_id, 'the link itself is the later crew\'s');
    }

    // 3. A record Fleet marked "not an apparatus" is never linked.

    public function test_rule_a_does_not_link_a_record_marked_not_an_apparatus(): void
    {
        $master = $this->unlinkedE2(['serial_number' => self::VIN_A]);
        $master->forceFill(['not_apparatus_at' => now()])->save();

        $this->crewAnswers([$this->answer('vin', self::VIN_A)]);

        $unit = $this->unit->fresh();
        $this->assertNull($unit->unit_master_vehicle_id);
        $this->assertStringContainsString('Fleet marked this record as not an apparatus', (string) $unit->master_link_note);
        $this->assertSame(0, $this->events('matched'));
    }

    public function test_rule_b_does_not_link_a_record_marked_not_an_apparatus(): void
    {
        $master = $this->unlinkedE2(['tag_number' => 'XE1234']);
        $master->forceFill(['not_apparatus_at' => now()])->save();

        $this->crewAnswers([$this->answer('vehicle_number', 'V-202'), $this->answer('plate', 'XE1234')]);

        $this->assertNull($this->unit->fresh()->unit_master_vehicle_id);
        $this->assertStringContainsString('Fleet marked this record as not an apparatus', (string) $this->unit->fresh()->master_link_note);
    }

    // 4. A linked unit is never asked for its vehicle number, and a number it reports is never closed silently.

    public function test_a_linked_unit_is_not_asked_for_its_vehicle_number_even_when_the_record_has_none(): void
    {
        $this->assertNotContains('vehicle_number', $this->openFields($this->unit));
        DB::table('unit_master_vehicles')->where('id', $this->unitMaster()->id)->update(['veh_number' => null]);

        $this->assertNotContains('vehicle_number', $this->openFields($this->unit));
        $this->assertContains('vehicle_number', $this->openFields($this->unlink($this->unit)), 'an unlinked unit is still asked');
    }

    public function test_a_number_a_linked_record_lacks_is_one_conflict_alert_not_a_silent_close(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        DB::table('unit_master_vehicles')->where('id', $this->unitMaster()->id)->update(['veh_number' => null]);

        $this->crewAnswers([$this->answer('vehicle_number', 'V-900')]);

        $this->assertNull($this->unitMaster()->veh_number, 'the link defines it; the answer never writes it');
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('field', 'veh_number')->where('status', 'proposed')->count());
        $this->assertSame(1, $this->events('conflict', 'vehicle_number'));
        Notification::assertSentToTimes($manager, NewSubmissionNotification::class, 1);
        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn (NewSubmissionNotification $n): bool => str_contains($n->toDatabase($manager)['body'], 'has no vehicle number'));
    }

    public function test_a_number_given_before_linking_that_the_record_lacks_alerts_fleet_once(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $master = $this->unlinkedE2(['serial_number' => self::VIN_A]);
        DB::table('unit_master_vehicles')->where('id', $master->id)->update(['veh_number' => null]);
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['vehicle_number' => null]);

        $this->crewAnswers([$this->answer('vehicle_number', 'V-900'), $this->answer('vin', self::VIN_A)]);

        $this->assertSame($master->id, $this->unit->fresh()->unit_master_vehicle_id);
        $this->assertNull($master->fresh()->veh_number);
        $this->assertSame(1, $this->events('conflict', 'vehicle_number'));
        Notification::assertSentToTimes($manager, NewSubmissionNotification::class, 1);
    }

    // 5. can_answer mirrors the endpoint.

    public function test_can_answer_is_false_for_an_active_account_without_an_employee_profile(): void
    {
        $planner = app(VehicleIdentificationPlanner::class);
        $account = User::factory()->create(['account_status' => AccountStatus::Active]);

        $this->assertTrue($planner->canAnswer($this->member, $this->unit));
        $this->assertFalse($planner->canAnswer($account, $this->unit));
        $this->assertFalse($planner->canAnswer($this->secondCrewWithoutAccess(), $this->unit));
        $this->assertTrue($planner->canAnswer($this->fleetOperationsManager(), $this->unit));

        $this->actingAsCanonicalUser($account);
        $this->getJson("/api/public/apparatuses/{$this->unit->id}/vehicle-identification")->assertForbidden();
    }

    private function secondCrewWithoutAccess(): User
    {
        return User::factory()->create(['account_status' => AccountStatus::Disabled]);
    }

    // 6. Races end in needs-review, never a 500.

    public function test_a_second_unit_with_a_vin_a_first_unit_just_created_is_sent_to_review_not_duplicated(): void
    {
        $this->unlinkedE2();
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['vehicle_number' => null]);
        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);
        $created = UnitMasterVehicle::query()->where('serial_number', self::VIN_B)->sole();
        $this->assertSame($created->id, $this->unit->fresh()->unit_master_vehicle_id);
        $other = $this->unlink($this->frontline->fresh(), null);
        Apparatus::query()->whereKey($other->id)->toBase()->update(['vehicle_number' => null]);
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('vin', self::VIN_B)], $other);

        $this->assertSame($before, $this->masterCount());
        $this->assertNull($other->fresh()->unit_master_vehicle_id);
        $this->assertStringContainsString('already linked to E2', (string) $other->fresh()->master_link_note);
    }

    public function test_losing_the_link_race_on_the_unique_index_becomes_a_review_not_an_error(): void
    {
        $master = $this->unlinkedE2(['serial_number' => self::VIN_A]);
        $stolen = false;
        $rival = $this->frontline->id;
        Apparatus::saving(function (Apparatus $unit) use (&$stolen, $master, $rival): void {
            if (! $stolen && $unit->unit_master_vehicle_id === $master->id) {
                $stolen = true; // another request links the same record at this very moment
                DB::table('apparatuses')->where('id', $rival)->update(['unit_master_vehicle_id' => $master->id]);
            }
        });

        $this->crewAnswers([$this->answer('vin', self::VIN_A)]);

        $this->assertTrue($stolen);
        $unit = $this->unit->fresh();
        $this->assertNull($unit->unit_master_vehicle_id);
        $this->assertSame(Apparatus::MASTER_LINK_NEEDS_REVIEW, $unit->master_link_status);
        $this->assertStringContainsString('linked to another unit at the same moment', (string) $unit->master_link_note);
    }

    public function test_a_busy_creation_lock_sends_the_unit_to_review(): void
    {
        $this->unlinkedE2();
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['vehicle_number' => null]);
        $held = Cache::lock('vehicle-identification-create:'.sha1((string) self::VIN_B), 60);
        $this->assertTrue($held->get());
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $this->assertSame($before, $this->masterCount());
        $this->assertStringContainsString('Another crew is creating this vehicle record right now', (string) $this->unit->fresh()->master_link_note);
        $held->release();
    }

    public function test_the_creation_lock_is_released_so_the_next_creation_goes_ahead(): void
    {
        $this->unlinkedE2();
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['vehicle_number' => null]);

        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $lock = Cache::lock('vehicle-identification-create:'.sha1((string) self::VIN_B), 5);
        $this->assertTrue($lock->get(), 'nothing is left holding the lock after the submission');
        $lock->release();
    }
}
