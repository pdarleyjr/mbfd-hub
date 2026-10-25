<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Fleet\VehicleIdentificationService;
use App\Services\Fleet\VehicleIdentificationSubmission;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Rules a to e: what the crew's answers may and may not do to the vehicle records. */
final class ResolutionTest extends IdentificationTestCase
{
    private function masterCount(): int
    {
        return UnitMasterVehicle::query()->count();
    }

    /**
     * Give E2's own master record these values, then cut E2 loose from it (a unit nobody matched yet).
     *
     * @param  array<string, mixed>  $values
     */
    private function unlinkedUnitWithMaster(array $values): UnitMasterVehicle
    {
        $master = $this->unitMaster();
        $master->forceFill($values + ['serial_number' => null, 'tag_number' => null])->save();
        $this->unlink($this->unit);

        return $master->fresh();
    }

    /** A unit with no vehicle number of its own, like FB6 in production, so a new record can name it. */
    private function withoutOwnNumber(): void
    {
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['vehicle_number' => null]);
    }

    /**
     * Answer the way a crew would: through the same cleaning as the checkout.
     *
     * @param  list<array<string, mixed>>  $answers
     */
    private function crewAnswers(array $answers, ?Apparatus $unit = null, ?User $crew = null, ?string $clientSubmissionId = null): bool
    {
        $unit ??= $this->unit;
        $section = app(VehicleIdentificationSubmission::class)->clean(['questions_version' => 'x', 'answers' => $answers], 'vehicle', strict: true)['section'];

        return app(VehicleIdentificationService::class)->submitStandalone($unit->fresh(), $section, $crew ?? $this->member, $clientSubmissionId ?? (string) Str::uuid());
    }

    private function eventsOf(string $event): int
    {
        return ApparatusIdentificationEvent::query()->where('event', $event)->count();
    }

    public function test_a_vin_that_equals_exactly_one_free_record_links_the_unit(): void
    {
        $master = $this->unlinkedUnitWithMaster(['serial_number' => self::VIN_A]);
        $before = $this->masterCount();

        $this->crewAnswers([
            $this->answer('vin', '1fTfw1et5 dfc-10312'),
            $this->answer('year', '2022'),
            $this->answer('plate', 'XE1234'),
        ]);

        $unit = $this->unit->fresh();
        $this->assertSame($master->id, $unit->unit_master_vehicle_id);
        $this->assertSame(Apparatus::MASTER_LINK_LINKED, $unit->master_link_status);
        $this->assertSame($before, $this->masterCount(), 'no second record');
        $matched = ApparatusIdentificationEvent::query()->where('event', 'matched')->sole();
        $this->assertSame('a', $matched->metadata['rule']);
        $this->assertSame($this->member->id, $matched->actor_user_id);
        $this->assertSame('2022', $master->fresh()->year, 'a blank year is filled from the answers');
        $this->assertSame('XE1234', $master->fresh()->tag_number, 'a blank plate is written at once, as the member');
        $filled = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('field', 'tag_number')->sole();
        $this->assertSame(['applied', 'XE1234', UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, $this->member->id], [$filled->status, $filled->new_value, $filled->source, $filled->user_id]);
        $this->assertSame(0, $this->eventsOf('master_created'));
        $this->assertSame(self::VIN_A, $unit->vin, 'the unit takes the record identity');
    }

    public function test_a_vin_with_a_contradicting_vehicle_number_links_nothing_and_goes_to_fleet(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->unlinkedUnitWithMaster(['serial_number' => self::VIN_A]);
        $before = $this->masterCount();

        // V-303 is E3's vehicle record; the VIN points at V-202.
        $this->crewAnswers([$this->answer('vin', self::VIN_A), $this->answer('vehicle_number', 'V-303')]);

        $unit = $this->unit->fresh();
        $this->assertNull($unit->unit_master_vehicle_id);
        $this->assertSame(Apparatus::MASTER_LINK_NEEDS_REVIEW, $unit->master_link_status);
        $this->assertStringContainsString('vehicle number V-303', (string) $unit->master_link_note);
        $this->assertStringContainsString('does not belong', (string) $unit->master_link_note);
        $this->assertSame($before, $this->masterCount());
        $this->assertSame(1, $this->eventsOf('conflict'));
        $this->assertSame(0, $this->eventsOf('matched'));
        Notification::assertSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_vin_held_by_another_unit_is_never_taken_over(): void
    {
        $this->frontline->fresh()->masterVehicle->forceFill(['serial_number' => self::VIN_B])->save();
        $this->unlinkedUnitWithMaster(['serial_number' => null]);

        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $unit = $this->unit->fresh();
        $this->assertNull($unit->unit_master_vehicle_id);
        $this->assertStringContainsString('already linked to E3', (string) $unit->master_link_note);
        $this->assertSame($this->frontline->fresh()->masterVehicle->id, $this->frontline->fresh()->unit_master_vehicle_id);
    }

    public function test_a_vehicle_number_with_the_same_records_plate_links_the_unit(): void
    {
        $master = $this->unlinkedUnitWithMaster(['tag_number' => 'XE1234']);

        $this->crewAnswers([$this->answer('vehicle_number', ' v-202 '), $this->answer('plate', 'xe-1234'), $this->unavailable('vin', 'not_accessible_now')]);

        $this->assertSame($master->id, $this->unit->fresh()->unit_master_vehicle_id);
        $this->assertSame('b', ApparatusIdentificationEvent::query()->where('event', 'matched')->sole()->metadata['rule']);
    }

    public function test_a_vehicle_number_alone_is_only_a_partial_match_until_something_backs_it(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $master = $this->unlinkedUnitWithMaster(['tag_number' => 'XE1234']);

        $this->crewAnswers([$this->answer('vehicle_number', 'V-202')]);
        $this->assertNull($this->unit->fresh()->unit_master_vehicle_id);
        $this->assertStringContainsString('nothing else the crew reported confirms it', (string) $this->unit->fresh()->master_link_note);
        Notification::assertSentTo($manager, NewSubmissionNotification::class);

        // The plate arrives at a later checkout: the earlier answer still counts.
        $this->crewAnswers([$this->answer('plate', 'XE1234')]);

        $this->assertSame($master->id, $this->unit->fresh()->unit_master_vehicle_id);
    }

    public function test_a_number_that_matches_with_a_different_vin_or_plate_is_not_linked_and_not_duplicated(): void
    {
        $this->unlinkedUnitWithMaster(['serial_number' => self::VIN_A, 'tag_number' => 'XE1234']);
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('vehicle_number', 'V-202'), $this->answer('vin', self::VIN_B)]);

        $unit = $this->unit->fresh();
        $this->assertNull($unit->unit_master_vehicle_id);
        $this->assertStringContainsString('does not match vehicle record V-202', (string) $unit->master_link_note);
        $this->assertSame($before, $this->masterCount(), 'a contradicting answer never creates a lookalike record');
        $this->assertSame(0, $this->eventsOf('master_created'));
    }

    public function test_no_candidate_at_all_creates_a_record_for_fleet_to_verify(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->unlinkedUnitWithMaster([]);
        $this->withoutOwnNumber();
        $before = $this->masterCount();

        $this->crewAnswers([
            $this->answer('vehicle_number', 'V-777'),
            $this->answer('vin', self::VIN_B),
            $this->answer('plate', 'NEW7777'),
            $this->answer('year', '2024'),
            $this->answer('make', 'Ford'),
            $this->answer('model', 'F-250'),
        ]);

        $this->assertSame($before + 1, $this->masterCount());
        $created = UnitMasterVehicle::query()->where('veh_number', 'V-777')->sole();
        $this->assertSame([self::VIN_B, 'NEW7777', '2024', 'Ford', 'F-250', 'vehicle', 'daily_checkout', true], [
            $created->serial_number, $created->tag_number, $created->year, $created->make, $created->model,
            $created->asset_category, $created->record_source, $created->needs_fleet_review,
        ]);
        $unit = $this->unit->fresh();
        $this->assertSame($created->id, $unit->unit_master_vehicle_id);
        $this->assertSame('V-777', $unit->vehicle_number);
        $this->assertSame(self::VIN_B, $unit->vin);
        $history = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $created->id)->where('field', 'record')->sole();
        $this->assertSame([UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, 'Created from Daily Checkout, pending Fleet verification', $this->member->id], [$history->source, $history->note, $history->user_id]);
        $this->assertSame(1, $this->eventsOf('master_created'));
        Notification::assertSentTo($manager, NewSubmissionNotification::class, function (NewSubmissionNotification $notification) use ($manager): bool {
            $payload = $notification->toDatabase($manager);

            return $payload['title'] === 'New vehicle record to verify for E2' && str_contains($payload['body'], 'V-777');
        });
    }

    public function test_a_boat_record_is_created_as_a_boat(): void
    {
        $boat = Apparatus::query()->create([
            'unit_id' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'Fire Boat 6', 'type' => 'Fire Boat', 'slug' => 'fb6',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'fireboat6',
        ]);

        app(VehicleIdentificationService::class)->submitStandalone($boat, app(VehicleIdentificationSubmission::class)->clean([
            'questions_version' => 'x',
            'answers' => [$this->answer('vin', 'MTK12345A515'), $this->unavailable('vehicle_number', 'location_unknown')],
        ], 'boat', strict: true)['section'], $this->member, (string) Str::uuid());

        $created = UnitMasterVehicle::query()->where('serial_number', 'MTK12345A515')->sole();
        $this->assertSame(['boat', null], [$created->asset_category, $created->veh_number], 'a strong identifier is enough; the number stays blank');
        $this->assertSame($created->id, $boat->fresh()->unit_master_vehicle_id);
    }

    public function test_near_misses_never_link_to_a_lookalike_record(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $original = $this->unlinkedUnitWithMaster(['serial_number' => self::VIN_A]);
        $this->withoutOwnNumber();
        $before = $this->masterCount();

        // "V202" is not "V-202": dashes are never stripped, so this is a different vehicle number and
        // matches no record. The unit is never linked to the lookalike; a record is created for Fleet to verify.
        $this->crewAnswers([$this->answer('vehicle_number', 'V202'), $this->answer('year', '2022')]);

        $unit = $this->unit->fresh();
        $created = UnitMasterVehicle::query()->where('veh_number', 'V202')->sole();
        $this->assertNotSame($original->id, $created->id);
        $this->assertSame($created->id, $unit->unit_master_vehicle_id);
        $this->assertSame($before + 1, $this->masterCount());
        $this->assertTrue($created->needs_fleet_review);
        $this->assertSame(self::VIN_A, $original->fresh()->serial_number, 'the lookalike record is untouched');
        $this->assertSame(1, $this->eventsOf('master_created'));
        Notification::assertSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_vehicle_number_alone_with_no_candidate_creates_a_record_for_fleet_to_verify(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->unlinkedUnitWithMaster([]);
        $this->withoutOwnNumber();
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('vehicle_number', 'V-777')]);

        $this->assertSame($before + 1, $this->masterCount());
        $created = UnitMasterVehicle::query()->where('veh_number', 'V-777')->sole();
        $this->assertSame([null, null, 'daily_checkout', true], [$created->serial_number, $created->tag_number, $created->record_source, $created->needs_fleet_review]);
        $this->assertSame($created->id, $this->unit->fresh()->unit_master_vehicle_id);
        $this->assertSame(Apparatus::MASTER_LINK_LINKED, $this->unit->fresh()->master_link_status);
        $history = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $created->id)->where('field', 'record')->sole();
        $this->assertSame('Created from Daily Checkout, pending Fleet verification', $history->note);
        $this->assertSame(1, $this->eventsOf('master_created'));
        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn (NewSubmissionNotification $n): bool => str_contains($n->toDatabase($manager)['title'], 'New vehicle record to verify'));
    }

    public function test_a_hull_id_alone_with_no_candidate_creates_a_boat_record_and_a_plate_alone_waits(): void
    {
        $boat = Apparatus::query()->create([
            'unit_id' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'Fire Boat 6', 'type' => 'Fire Boat', 'slug' => 'fb6',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'fireboat6',
        ]);
        $before = $this->masterCount();

        $submit = fn (array $answers): bool => app(VehicleIdentificationService::class)->submitStandalone($boat->fresh(), app(VehicleIdentificationSubmission::class)->clean([
            'questions_version' => 'x', 'answers' => $answers,
        ], 'boat', strict: true)['section'], $this->member, (string) Str::uuid());

        $submit([$this->answer('plate', 'FL1234AB')]);
        $this->assertSame($before, $this->masterCount(), 'a plate alone is not enough to create a record');
        $this->assertNull($boat->fresh()->unit_master_vehicle_id);

        $submit([$this->answer('vin', 'MTK12345A515')]);
        $created = UnitMasterVehicle::query()->where('serial_number', 'MTK12345A515')->sole();
        $this->assertSame(['boat', 'FL1234AB', true], [$created->asset_category, $created->tag_number, $created->needs_fleet_review], 'the earlier plate answer counts');
        $this->assertSame($created->id, $boat->fresh()->unit_master_vehicle_id);
    }

    public function test_a_match_that_would_replace_the_units_own_vehicle_number_goes_to_fleet(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        // E3's record holds the VIN, but E2 already has its own number V-202. E3 is not linked here.
        $other = $this->frontline->fresh()->masterVehicle;
        $other->forceFill(['serial_number' => self::VIN_B])->save();
        Apparatus::query()->whereKey($this->frontline->id)->toBase()->update(['unit_master_vehicle_id' => null, 'master_link_status' => 'needs_review']);
        $this->unlinkedUnitWithMaster([]);

        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $unit = $this->unit->fresh();
        $this->assertNull($unit->unit_master_vehicle_id);
        $this->assertSame('V-202', $unit->vehicle_number, 'the unit keeps its own number');
        $this->assertStringContainsString('own vehicle number is V-202, not V-303', (string) $unit->master_link_note);
        $this->assertSame(0, $this->eventsOf('matched'));
        Notification::assertSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_creation_that_would_replace_the_units_own_vin_goes_to_fleet(): void
    {
        $this->unlinkedUnitWithMaster([]);
        $this->withoutOwnNumber();
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['vin' => self::VIN_A]);
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $this->assertSame($before, $this->masterCount());
        $this->assertStringContainsString('own VIN/HIN', (string) $this->unit->fresh()->master_link_note);
    }

    public function test_placeholder_values_on_a_record_are_never_matched(): void
    {
        $this->unlinkedUnitWithMaster(['serial_number' => 'UNKNOWN', 'tag_number' => 'N/A']);
        $this->withoutOwnNumber();
        $this->frontline->fresh()->masterVehicle->forceFill(['serial_number' => '-'])->save();
        $before = $this->masterCount();

        // Even if a placeholder somehow reached a record, a real answer cannot equal it; and a record
        // holding a placeholder is not a candidate, so a real VIN with no match creates a record.
        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $this->assertSame($before + 1, $this->masterCount());
        $this->assertSame(self::VIN_B, UnitMasterVehicle::query()->latest('id')->first()->serial_number);
    }

    public function test_make_model_and_year_never_match_a_record_by_similarity(): void
    {
        $this->unlinkedUnitWithMaster(['make' => 'Pierce', 'model' => 'Enforcer', 'year' => '2022']);
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('make', 'Pierce'), $this->answer('model', 'Enforcer'), $this->answer('year', '2022')]);

        $this->assertNull($this->unit->fresh()->unit_master_vehicle_id);
        $this->assertSame($before, $this->masterCount());
        $this->assertSame(0, $this->eventsOf('matched'));
        $this->assertSame(0, $this->eventsOf('master_created'));
    }

    public function test_a_plate_that_matches_nothing_waits(): void
    {
        $this->unlinkedUnitWithMaster(['tag_number' => 'XE1234']);
        $before = $this->masterCount();

        $this->crewAnswers([$this->answer('plate', 'NOPE999')]);

        $this->assertNull($this->unit->fresh()->unit_master_vehicle_id);
        $this->assertSame($before, $this->masterCount(), 'a plate alone is not enough to create a record');
        $this->assertSame(0, $this->eventsOf('conflict'));
        $this->assertSame(0, $this->eventsOf('master_created'));
    }

    public function test_a_plate_that_matches_a_record_is_only_a_partial_match_for_fleet(): void
    {
        $this->unlinkedUnitWithMaster(['tag_number' => 'XE1234']);

        $this->crewAnswers([$this->answer('plate', 'XE1234')]);

        $unit = $this->unit->fresh();
        $this->assertNull($unit->unit_master_vehicle_id);
        $this->assertStringContainsString('Only the plate matches', (string) $unit->master_link_note);
        $this->assertSame(1, $this->eventsOf('conflict'));
    }

    public function test_a_linked_unit_fills_blank_fields_and_proposes_where_the_record_differs(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $captain = Apparatus::query()->create([
            'unit_id' => 'Captain 5', 'name' => 'Captain 5', 'designation' => 'Captain 5', 'type' => 'SUV', 'vehicle_number' => '22530',
            'status' => 'In Service', 'daily_checkout_requirement' => 'administrative', 'daily_checkout_template' => 'pending',
        ]);
        $master = $captain->fresh()->masterVehicle;
        $master->forceFill(['year' => '2022', 'make' => 'Ford', 'model' => 'F-250', 'tag_number' => 'XD1904', 'serial_number' => self::VIN_A])->save();
        \App\Models\UnitMasterVehicleChange::query()->create([
            'unit_master_vehicle_id' => $master->id, 'field' => 'tag_number', 'old_value' => 'XD1904', 'new_value' => 'XK4560',
            'status' => 'proposed', 'source' => UnitMasterVehicleChange::SOURCE_FY27,
        ]);

        // The crew reads a third plate: the record keeps XD1904, the crew's value is proposed, Fleet is told.
        $this->crewAnswers([$this->answer('plate', 'ZZ0001')], $captain);

        $this->assertSame('XD1904', $master->fresh()->tag_number);
        $proposal = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('new_value', 'ZZ0001')->sole();
        $this->assertSame(['proposed', UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, $this->member->id], [$proposal->status, $proposal->source, $proposal->user_id]);
        $conflict = ApparatusIdentificationEvent::query()->where('event', 'conflict')->sole();
        $this->assertSame('XD1904', $conflict->metadata['record_value']);
        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn (NewSubmissionNotification $n): bool => str_contains($n->toDatabase($manager)['body'], 'ZZ0001'));
    }

    public function test_a_crew_value_that_equals_the_record_is_only_confirmed(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $captain = Apparatus::query()->create([
            'unit_id' => 'Captain 5', 'name' => 'Captain 5', 'designation' => 'Captain 5', 'type' => 'SUV', 'vehicle_number' => '22530',
            'status' => 'In Service', 'daily_checkout_requirement' => 'administrative', 'daily_checkout_template' => 'pending',
        ]);
        $master = $captain->fresh()->masterVehicle;
        $master->forceFill(['year' => '2022', 'make' => 'Ford', 'model' => 'F-250', 'tag_number' => 'XD1904', 'serial_number' => self::VIN_A])->save();
        UnitMasterVehicleChange::query()->create([
            'unit_master_vehicle_id' => $master->id, 'field' => 'tag_number', 'old_value' => 'XD1904', 'new_value' => 'XK4560',
            'status' => 'proposed', 'source' => UnitMasterVehicleChange::SOURCE_FY27,
        ]);

        $this->crewAnswers([$this->answer('plate', 'xd-1904')], $captain);

        $this->assertSame(1, $this->eventsOf('confirmed'));
        $this->assertSame(0, $this->eventsOf('conflict'));
        $this->assertSame('XD1904', $master->fresh()->tag_number);
        Notification::assertNotSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_vin_another_record_already_carries_is_never_copied_onto_a_second_record(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->frontline->fresh()->masterVehicle->forceFill(['serial_number' => self::VIN_B])->save();
        $this->unitMaster()->forceFill(['serial_number' => null])->save();
        $this->assertContains('vin', $this->openFields($this->unit));

        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $this->assertNull($this->unitMaster()->serial_number, 'nothing was written');
        $this->assertSame(1, $this->eventsOf('conflict'));
        $this->assertSame(0, $this->eventsOf('master_filled'));
        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn (NewSubmissionNotification $n): bool => str_contains($n->toDatabase($manager)['body'], 'already has it'));
    }

    public function test_the_same_submission_never_creates_two_records(): void
    {
        $this->unlinkedUnitWithMaster([]);
        $this->withoutOwnNumber();
        $csid = (string) Str::uuid();
        $answers = [$this->answer('vehicle_number', 'V-777'), $this->answer('vin', self::VIN_B)];

        $this->assertFalse($this->crewAnswers($answers, clientSubmissionId: $csid));
        $this->assertTrue($this->crewAnswers($answers, clientSubmissionId: $csid));

        $this->assertSame(1, UnitMasterVehicle::query()->where('veh_number', 'V-777')->count());
        $this->assertSame(1, $this->eventsOf('master_created'));
    }
}
