<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\ApparatusInspection;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Notifications\NewSubmissionNotification;
use App\Services\Fleet\VehicleIdentificationPlanner;
use App\Services\Fleet\VehicleIdentificationService;
use App\Services\Fleet\VehicleIdentificationSubmission;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/** Failure handling, the replay command, deletes, and units that have no vehicle number of their own. */
final class HardeningTest extends IdentificationTestCase
{
    /** @return array<string, array{Throwable}> */
    public static function concurrencyErrors(): array
    {
        return [
            'deadlock exception' => [new DeadlockException('Deadlock found when trying to get lock; try restarting transaction')],
            'postgres deadlock' => [new RuntimeException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR: deadlock detected')],
            'lock timeout' => [new RuntimeException('Lock wait timeout exceeded; try restarting transaction')],
            'lost connection' => [new RuntimeException('SQLSTATE[08006]: server closed the connection unexpectedly')],
        ];
    }

    #[DataProvider('concurrencyErrors')]
    public function test_a_concurrency_or_connection_error_propagates_to_the_checkout_transaction(Throwable $error): void
    {
        $this->mock(VehicleIdentificationService::class, fn ($mock) => $mock->shouldReceive('record')->once()->andThrow($error));
        $this->withoutExceptionHandling();

        try {
            $this->submitCheckout([$this->answer('plate', 'XE1234')]);
            $this->fail('The error must reach the outer transaction instead of being swallowed.');
        } catch (Throwable $caught) {
            $this->assertTrue($caught === $error || $caught->getPrevious() === $error || $caught::class === DeadlockException::class, get_class($caught).': '.$caught->getMessage());
        }
    }

    public function test_an_ordinary_error_is_swallowed_after_its_savepoint_rolled_back_and_the_checkout_is_saved(): void
    {
        $this->mock(VehicleIdentificationService::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andReturnUsing(function (): never {
                // Writes made before the failure must disappear with the savepoint.
                ApparatusIdentificationQuestion::query()->create(['apparatus_id' => $this->unit->id, 'field' => 'plate', 'state' => 'answered', 'answered_value' => 'XE1234']);

                throw new RuntimeException('boom');
            });
        });

        $this->submitCheckout([$this->answer('plate', 'XE1234')])->assertCreated();

        $this->assertSame(1, ApparatusInspection::query()->count());
        $this->assertSame(0, ApparatusIdentificationQuestion::query()->count(), 'the failed step left nothing behind');
        $this->assertNotNull(ApparatusInspection::query()->sole()->vehicle_identification, 'the answers stay with the checkout');
    }

    public function test_the_replay_command_records_answers_a_failed_step_lost_and_is_idempotent(): void
    {
        $real = app(VehicleIdentificationService::class);
        $this->mock(VehicleIdentificationService::class, fn ($mock) => $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('boom')));
        $csid = (string) Str::uuid();
        $this->submitCheckout([$this->answer('year', '2022'), $this->answer('plate', 'XE1234')], $csid)->assertCreated();
        $this->assertSame(0, ApparatusIdentificationEvent::query()->count());
        $this->app->instance(VehicleIdentificationService::class, $real);

        $this->artisan('fleet:replay-vehicle-identification', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, ApparatusIdentificationEvent::query()->count(), 'a dry run writes nothing');

        $this->artisan('fleet:replay-vehicle-identification')->assertSuccessful();

        $this->assertSame('2022', $this->unitMaster()->year);
        $this->assertSame(2, ApparatusIdentificationEvent::query()->where('event', 'answered')->count());
        $this->assertSame($csid, ApparatusIdentificationEvent::query()->where('event', 'answered')->first()->client_submission_id);
        $events = ApparatusIdentificationEvent::query()->count();

        $this->artisan('fleet:replay-vehicle-identification')->assertSuccessful();

        $this->assertSame($events, ApparatusIdentificationEvent::query()->count());
    }

    public function test_deleting_an_apparatus_is_not_blocked_by_its_identification_history(): void
    {
        $unit = $this->legacyApparatus('X 9', null);
        ApparatusIdentificationQuestion::query()->create(['apparatus_id' => $unit->id, 'field' => 'plate', 'state' => 'open']);
        ApparatusIdentificationEvent::query()->create(['apparatus_id' => $unit->id, 'field' => 'plate', 'event' => 'unavailable', 'reason' => 'location_unknown']);

        $unit->delete();

        $this->assertSame(0, ApparatusIdentificationQuestion::query()->where('apparatus_id', $unit->id)->count());
        $this->assertSame(0, ApparatusIdentificationEvent::query()->where('apparatus_id', $unit->id)->count());
    }

    public function test_the_captain_5_unit_is_found_with_a_narrow_query(): void
    {
        $captain = $this->captainFive();
        DB::enableQueryLog();

        $found = app(VehicleIdentificationPlanner::class)->captain5Unit();

        $this->assertSame($captain->id, $found?->id);
        $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'from "apparatuses"') || str_contains($sql, 'from `apparatuses`'));
        $this->assertCount(1, $selects);
        $this->assertStringContainsStringIgnoringCase('like', (string) $selects->first());
        $this->assertStringContainsString('limit', (string) $selects->first());
    }

    public function test_a_vin_or_plate_on_a_linked_unit_is_proposed_not_applied_and_announced_once(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->unitMaster()->forceFill(['serial_number' => null])->save();
        $answers = [$this->answer('vin', self::VIN_A), $this->answer('plate', 'XE1234'), $this->answer('year', '2022')];

        $this->crewAnswers($answers);

        $master = $this->unitMaster();
        $this->assertSame([null, null, '2022'], [$master->serial_number, $master->tag_number, $master->year]);
        $proposals = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('status', 'proposed')->pluck('new_value', 'field')->all();
        $this->assertEquals(['serial_number' => self::VIN_A, 'tag_number' => 'XE1234'], $proposals);
        $this->assertSame(2, ApparatusIdentificationEvent::query()->where('event', 'proposed')->count());
        Notification::assertSentToTimes($manager, NewSubmissionNotification::class, 1);

        // Fleet asks again and a second crew reads the same values: the proposals already exist.
        foreach (['vin', 'plate'] as $field) {
            app(VehicleIdentificationService::class)->reopen($this->unit->fresh(), $field, $this->fleetOperationsManager());
        }
        $this->crewAnswers([$this->answer('vin', self::VIN_A), $this->answer('plate', 'XE1234')]);

        $this->assertSame(2, UnitMasterVehicleChange::query()->where('status', 'proposed')->count(), 'no duplicate proposals');
        Notification::assertSentToTimes($manager, NewSubmissionNotification::class, 1);
    }

    public function test_the_vehicle_number_of_a_linked_unit_is_never_filled_from_an_answer(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $this->unitMaster()->forceFill(['year' => null])->save();
        $before = $this->unitMaster()->veh_number;

        app(\App\Services\Fleet\VehicleIdentificationResolver::class)->resolve(
            new \App\Services\Fleet\VehicleIdentificationLedger($this->unit->fresh(), $this->member, (string) Str::uuid(), null),
            ['vehicle_number' => 'V-999'],
        );

        $this->assertSame($before, $this->unitMaster()->veh_number);
        $this->assertSame('V-202', $this->unit->fresh()->vehicle_number);
        $this->assertSame(1, ApparatusIdentificationEvent::query()->where('event', 'conflict')->where('field', 'vehicle_number')->count());
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('field', 'veh_number')->where('status', 'proposed')->count());
        $this->assertNotNull($manager);
    }

    public function test_a_unit_with_no_vehicle_number_still_records_and_resolves_when_the_checkout_reports_a_vehicle(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        // The FB6 shape: no vehicle number, not linked. Its crew still names the vehicle at the confirmation step.
        $masterId = $this->unitMaster()->id;
        $this->unlink($this->unit);
        UnitMasterVehicle::query()->whereKey($masterId)->toBase()->update(['veh_number' => 'OLD-1']);
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['vehicle_number' => null]);
        $before = UnitMasterVehicle::query()->count();

        $payload = $this->payload(['vehicle_number' => 'FB-6', 'swap_answer' => 'swap_completed', 'note' => 'Named at the confirmation step.']);
        $payload['vehicle_identification'] = [
            'questions_version' => 'x',
            'answers' => [$this->answer('vehicle_number', 'FB-6'), $this->answer('vin', self::VIN_B)],
        ];
        $this->postJson($this->submissionUrl(), $payload)->assertCreated();

        $this->assertSame(['FB-6', self::VIN_B], ApparatusIdentificationQuestion::query()->where('state', 'answered')->orderBy('id')->pluck('answered_value')->all());
        $this->assertSame($before + 1, UnitMasterVehicle::query()->count(), 'the answers were resolved into a record for Fleet to verify');
        $unit = $this->unit->fresh();
        $this->assertSame('FB-6', $unit->vehicle_number);
        $this->assertNotNull($unit->unit_master_vehicle_id);
        $this->assertSame(0, ApparatusIdentificationEvent::query()->where('event', 'superseded')->count());
        Notification::assertSentTo($manager, NewSubmissionNotification::class);
    }

    /** @param list<array<string, mixed>> $answers */
    private function crewAnswers(array $answers): void
    {
        $section = app(VehicleIdentificationSubmission::class)->clean(['questions_version' => 'x', 'answers' => $answers], 'vehicle', strict: true)['section'];
        app(VehicleIdentificationService::class)->submitStandalone($this->unit->fresh(), $section, $this->member, (string) Str::uuid());
    }
}
