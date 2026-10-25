<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use App\Services\Fleet\VehicleIdentificationService;
use App\Services\Fleet\VehicleIdentificationSubmission;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * A linked unit's crew answers: write what the record lacks, confirm what it has, accept what a
 * pending proposal already holds, and send only a real conflict to Fleet.
 */
final class AutoPairTest extends IdentificationTestCase
{
    /** @param list<array<string, mixed>> $answers */
    private function crewAnswers(array $answers, ?Apparatus $unit = null, ?User $crew = null): void
    {
        $unit ??= $this->unit;
        $section = app(VehicleIdentificationSubmission::class)->clean(['questions_version' => 'x', 'answers' => $answers], 'vehicle', strict: true)['section'];

        app(VehicleIdentificationService::class)->submitStandalone($unit->fresh(), $section, $crew ?? $this->member, (string) Str::uuid());
    }

    private function events(string $event, ?string $field = null): int
    {
        return ApparatusIdentificationEvent::query()->where('event', $event)
            ->when($field !== null, fn ($query) => $query->where('field', $field))->count();
    }

    public function test_blank_vin_plate_and_year_are_written_at_once_as_the_member_with_no_proposal_and_no_alert(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->unitMaster()->forceFill(['serial_number' => null])->save();

        $this->crewAnswers([$this->answer('vin', self::VIN_A), $this->answer('plate', 'XE-1234'), $this->answer('year', '2022')]);

        $master = $this->unitMaster();
        $this->assertSame([self::VIN_A, 'XE1234', '2022'], [$master->serial_number, $master->tag_number, $master->year]);
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('status', 'proposed')->count());
        foreach (['serial_number', 'tag_number', 'year'] as $column) {
            $change = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('field', $column)->where('source', 'daily_checkout')->sole();
            $this->assertSame(['applied', 'daily_checkout', $this->member->id], [$change->status, $change->source, $change->user_id], $column);
            $this->assertStringStartsWith('Reported on the unit page by Swap Reporter', (string) $change->note);
        }
        $this->assertSame(3, $this->events('master_filled'));
        $this->assertSame(self::VIN_A, $this->unit->fresh()->vin, 'the unit takes the shared VIN');
        Notification::assertNotSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_placeholder_on_the_record_is_replaced_like_a_blank(): void
    {
        $this->completeMasterExcept($this->unit);
        $this->unitMaster()->forceFill(['tag_number' => 'UNKNOWN', 'make' => 'N/A'])->save();

        $this->crewAnswers([$this->answer('plate', 'XE1234'), $this->answer('make', 'Pierce')]);

        $master = $this->unitMaster();
        $this->assertSame(['XE1234', 'Pierce'], [$master->tag_number, $master->make]);
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('status', 'proposed')->count());
    }

    public function test_a_vin_another_record_holds_is_a_conflict_not_a_fill(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->frontline->fresh()->masterVehicle->forceFill(['serial_number' => self::VIN_B])->save();
        $this->unitMaster()->forceFill(['serial_number' => null])->save();

        $this->crewAnswers([$this->answer('vin', '2c3c-dxct9eh 123456')]);

        $this->assertNull($this->unitMaster()->serial_number);
        $this->assertSame(1, $this->events('conflict', 'vin'));
        $this->assertSame(0, $this->events('master_filled'));
        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn (NewSubmissionNotification $n): bool => str_contains($n->toDatabase($manager)['body'], 'already has it'));
    }

    public function test_a_plate_another_record_holds_is_a_conflict_not_a_fill(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->frontline->fresh()->masterVehicle->forceFill(['tag_number' => 'XE1234'])->save();

        $this->crewAnswers([$this->answer('plate', 'xe 1234')]);

        $this->assertNull($this->unitMaster()->tag_number);
        $this->assertSame(1, $this->events('conflict', 'plate'));
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('status', 'proposed')->count());
        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn (NewSubmissionNotification $n): bool => str_contains($n->toDatabase($manager)['body'], 'already has it'));
    }

    public function test_the_same_value_only_confirms_and_closes_the_question(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $captain = $this->captainFive('XK4560');

        $this->crewAnswers([$this->answer('plate', 'xd-1904')], $captain);

        $this->assertSame(1, $this->events('confirmed', 'plate'));
        $this->assertSame('XD1904', $captain->fresh()->masterVehicle->tag_number);
        $this->assertSame('answered', ApparatusIdentificationQuestion::query()->where('apparatus_id', $captain->id)->sole()->state);
        $this->assertSame(1, UnitMasterVehicleChange::query()->where('status', 'proposed')->count(), 'the FY27 proposal is left for Fleet');
        $this->assertSame([], $this->openFields($captain));
        Notification::assertNotSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_crew_value_that_matches_the_pending_proposal_accepts_it_as_the_member(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $captain = $this->captainFive('XK4560');

        $this->crewAnswers([$this->answer('plate', 'xk 4560')], $captain);

        $master = $captain->fresh()->masterVehicle;
        $this->assertSame('XK4560', $master->tag_number);
        $proposal = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('new_value', 'XK4560')->sole();
        $this->assertSame(['accepted', $this->member->id], [$proposal->status, $proposal->decided_by_user_id]);
        $this->assertNotNull($proposal->decided_at);
        $this->assertStringContainsString('Confirmed by Swap Reporter on the Captain 5 page', (string) $proposal->note);
        $event = ApparatusIdentificationEvent::query()->where('event', 'corroborated')->sole();
        $this->assertSame(['plate', 'XK4560', 'XD1904'], [$event->field, $event->value, $event->metadata['previous_value']]);
        $this->assertSame(0, $this->events('conflict'));
        $this->assertSame('answered', ApparatusIdentificationQuestion::query()->where('apparatus_id', $captain->id)->sole()->state);
        $this->assertSame([], $this->openFields($captain), 'nothing left to ask');
        Notification::assertNotSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_pending_proposal_is_not_accepted_when_another_record_holds_that_plate(): void
    {
        $captain = $this->captainFive('XK4560');
        $this->frontline->fresh()->masterVehicle->forceFill(['tag_number' => 'XK4560'])->save();

        $this->crewAnswers([$this->answer('plate', 'XK4560')], $captain);

        $this->assertSame('XD1904', $captain->fresh()->masterVehicle->tag_number);
        $this->assertSame(0, $this->events('corroborated'));
        $this->assertSame('proposed', UnitMasterVehicleChange::query()->where('new_value', 'XK4560')->where('unit_master_vehicle_id', $captain->masterVehicle->id)->sole()->status);
    }

    public function test_another_value_is_a_real_conflict_proposed_once_with_one_alert(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $captain = $this->captainFive('XK4560');

        $this->crewAnswers([$this->answer('plate', 'ZZ0001')], $captain);

        $master = $captain->fresh()->masterVehicle;
        $this->assertSame('XD1904', $master->tag_number);
        $this->assertSame(1, UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('new_value', 'ZZ0001')->where('status', 'proposed')->count());
        $this->assertSame(1, $this->events('conflict', 'plate'));
        Notification::assertSentToTimes($manager, NewSubmissionNotification::class, 1);

        // Fleet asks again; another crew reads the same third plate: still one proposal, no second alert.
        app(VehicleIdentificationService::class)->reopen($captain->fresh(), 'plate', $this->fleetOperationsManager());
        $this->crewAnswers([$this->answer('plate', 'ZZ0001')], $captain);

        $this->assertSame(1, UnitMasterVehicleChange::query()->where('new_value', 'ZZ0001')->count());
        Notification::assertSentToTimes($manager, NewSubmissionNotification::class, 1);
    }

    public function test_replaying_the_same_submission_changes_nothing(): void
    {
        $this->unitMaster()->forceFill(['serial_number' => null])->save();
        $csid = (string) Str::uuid();
        $section = app(VehicleIdentificationSubmission::class)->clean(['questions_version' => 'x', 'answers' => [
            $this->answer('vin', self::VIN_A), $this->answer('plate', 'XE1234'),
        ]], 'vehicle', strict: true)['section'];
        $service = app(VehicleIdentificationService::class);

        $this->assertFalse($service->submitStandalone($this->unit->fresh(), $section, $this->member, $csid));
        $changes = UnitMasterVehicleChange::query()->count();
        $events = ApparatusIdentificationEvent::query()->count();
        $this->assertTrue($service->submitStandalone($this->unit->fresh(), $section, $this->member, $csid));

        $this->assertSame([$changes, $events], [UnitMasterVehicleChange::query()->count(), ApparatusIdentificationEvent::query()->count()]);
    }

    public function test_pairing_by_exact_vin_then_filling_the_rest_needs_no_fleet_step(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $master = $this->unitMaster();
        $master->forceFill(['serial_number' => self::VIN_A])->save();
        $this->unlink($this->unit);

        $this->crewAnswers([$this->answer('vin', self::VIN_A), $this->answer('plate', 'XE1234'), $this->answer('year', '2022')]);

        $this->assertSame($master->id, $this->unit->fresh()->unit_master_vehicle_id);
        $master = $master->fresh();
        $this->assertSame(['XE1234', '2022'], [$master->tag_number, $master->year]);
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('status', 'proposed')->count());
        Notification::assertNotSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_a_hull_id_alone_creates_and_links_a_record_and_tells_fleet_to_verify(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $unit = $this->unlink($this->unit);
        Apparatus::query()->whereKey($unit->id)->toBase()->update(['vehicle_number' => null]);

        $this->crewAnswers([$this->answer('vin', self::VIN_B)]);

        $created = UnitMasterVehicle::query()->where('serial_number', self::VIN_B)->sole();
        $this->assertSame([true, 'daily_checkout'], [$created->needs_fleet_review, $created->record_source]);
        $this->assertSame($created->id, $this->unit->fresh()->unit_master_vehicle_id);
        Notification::assertSentTo($manager, NewSubmissionNotification::class, fn (NewSubmissionNotification $n): bool => str_contains($n->toDatabase($manager)['title'], 'New vehicle record to verify'));
    }
}
