<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\UnitMasterVehicleChange;
use App\Services\DailyCheckoutChecklistResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CheckoutSubmissionTest extends IdentificationTestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_answers_are_recorded_with_their_checkout_and_fill_the_vehicle_record(): void
    {
        $csid = (string) Str::uuid();

        $this->submitCheckout([
            $this->answer('plate', 'xe 1234'),
            $this->answer('year', '2022'),
            $this->answer('make', 'Pierce'),
            $this->answer('model', 'Enforcer'),
        ], $csid)->assertCreated();

        $inspection = ApparatusInspection::query()->where('client_submission_id', $csid)->sole();
        $questions = ApparatusIdentificationQuestion::query()->where('apparatus_id', $this->unit->id)->pluck('answered_value', 'field')->all();
        $this->assertEquals(['plate' => 'XE1234', 'year' => '2022', 'make' => 'Pierce', 'model' => 'Enforcer'], $questions);
        $this->assertSame(
            ['answered' => 4, 'master_filled' => 4],
            ApparatusIdentificationEvent::query()->where('apparatus_id', $this->unit->id)->selectRaw('event, count(*) as total')->groupBy('event')->pluck('total', 'event')->map(fn ($n): int => (int) $n)->all(),
        );
        $answered = ApparatusIdentificationEvent::query()->where('event', 'answered')->where('field', 'plate')->sole();
        $this->assertSame($this->member->id, $answered->actor_user_id);
        $this->assertSame($inspection->id, $answered->apparatus_inspection_id);
        $this->assertSame($csid, $answered->client_submission_id);

        $master = $this->unitMaster();
        $this->assertSame(['XE1234', '2022', 'Pierce', 'Enforcer'], [$master->tag_number, $master->year, $master->make, $master->model], 'a blank plate, year, make and model are written at once');
        $filled = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('source', UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT)->where('field', 'make')->sole();
        $this->assertSame($this->member->id, $filled->user_id);
        $this->assertStringStartsWith('Reported in Daily Checkout by Swap Reporter on ', (string) $filled->note);
        $this->assertSame('applied', $filled->status);
        $plate = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('field', 'tag_number')->sole();
        $this->assertSame(['applied', 'XE1234', UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, $this->member->id], [$plate->status, $plate->new_value, $plate->source, $plate->user_id], 'attributed to the answering member, never proposed');
        $unit = $this->unit->fresh();
        $this->assertSame(['Pierce', 'Enforcer', 2022], [$unit->make, $unit->model, (int) $unit->year], 'shared fields sync to the apparatus');
        $this->assertSame([], $this->openFields($this->unit));
    }

    public function test_a_replayed_submission_records_nothing_twice(): void
    {
        $payload = $this->checkout([$this->answer('plate', 'XE1234'), $this->unavailable('year', 'not_accessible_now')]);

        $this->postJson($this->submissionUrl(), $payload)->assertCreated();
        $events = ApparatusIdentificationEvent::query()->count();
        $this->assertGreaterThan(0, $events);
        $this->postJson($this->submissionUrl(), $payload)->assertOk()->assertJsonPath('id', ApparatusInspection::query()->sole()->id);

        $this->assertSame($events, ApparatusIdentificationEvent::query()->count());
        $this->assertSame(1, ApparatusInspection::query()->count());
        $this->assertSame(1, ApparatusIdentificationEvent::query()->where('event', 'answered')->count());
        $this->assertSame(1, ApparatusIdentificationEvent::query()->where('event', 'unavailable')->count());
    }

    public function test_the_answers_are_part_of_the_replay_hash(): void
    {
        $payload = $this->checkout([$this->answer('plate', 'XE1234')]);
        $this->postJson($this->submissionUrl(), $payload)->assertCreated();

        $payload['vehicle_identification']['answers'][0]['value'] = 'ZZ9999';

        $this->postJson($this->submissionUrl(), $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT');
        $this->assertSame('XE1234', ApparatusIdentificationQuestion::query()->where('field', 'plate')->sole()->answered_value);
    }

    public function test_the_cleaned_section_is_kept_on_the_inspection_with_its_kind(): void
    {
        $payload = $this->checkout([$this->answer('plate', 'xe 1234'), ['field' => 'odometer', 'value' => '1']]);
        $this->postJson($this->submissionUrl(), $payload)->assertCreated();

        $stored = ApparatusInspection::query()->sole()->vehicle_identification;

        $this->assertSame('vehicle', $stored['kind']);
        $this->assertSame([['field' => 'plate', 'value' => 'XE1234']], $stored['answers']);
    }

    public function test_a_replay_is_cleaned_with_the_kind_it_was_stored_with(): void
    {
        // A 12 character hull ID is a real value for a boat and is dropped for a road vehicle.
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['type' => 'Rescue Boat']);
        $payload = $this->checkout([$this->answer('vin', 'MTK12345A515')]);
        $this->postJson($this->submissionUrl(), $payload)->assertCreated();
        $this->assertSame('boat', ApparatusInspection::query()->sole()->vehicle_identification['kind']);

        // Fleet corrects the unit's type afterwards; the retry of the same queued payload must still match.
        Apparatus::query()->whereKey($this->unit->id)->toBase()->update(['type' => 'Engine']);
        $this->postJson($this->submissionUrl(), $payload)->assertOk();

        $this->assertSame(1, ApparatusInspection::query()->count());
    }

    public function test_malformed_items_are_dropped_and_never_reject_the_checkout(): void
    {
        $this->submitCheckout([
            ['field' => 'odometer', 'value' => '1234'],
            $this->answer('vin', 'SHORT'),
            $this->answer('plate', '!!'),
            $this->answer('year', 'abcd'),
            $this->answer('year', '1899'),
            $this->unavailable('make', 'other'),
            $this->unavailable('model', 'because'),
            ['field' => 'model'],
            'not even an object',
        ])->assertCreated();

        $this->assertSame(0, ApparatusIdentificationEvent::query()->count());
        $this->assertSame(0, ApparatusIdentificationQuestion::query()->count());
        $this->assertSame(1, ApparatusInspection::query()->count());
    }

    public function test_a_section_of_the_wrong_shape_never_rejects_the_checkout(): void
    {
        foreach (['garbage', 7, ['answers' => 'nope'], ['answers' => [null, 5]], ['questions_version' => ['x']]] as $section) {
            $payload = $this->payload();
            $payload['vehicle_identification'] = $section;

            $this->postJson($this->submissionUrl(), $payload)->assertCreated();
        }

        $this->assertSame(0, ApparatusIdentificationEvent::query()->count());
        $this->assertSame(5, ApparatusInspection::query()->count());
    }

    public function test_a_valid_item_survives_next_to_a_bad_one(): void
    {
        $this->submitCheckout([$this->answer('vin', 'NOPE'), $this->answer('plate', 'XE1234')])->assertCreated();

        $this->assertSame(['plate'], ApparatusIdentificationQuestion::query()->pluck('field')->all());
    }

    public function test_a_checkout_without_the_section_behaves_exactly_as_before(): void
    {
        $this->postJson($this->submissionUrl(), $this->payload())->assertCreated();

        $this->assertSame(0, ApparatusIdentificationEvent::query()->count());
        $this->assertSame(0, ApparatusIdentificationQuestion::query()->count());
    }

    public function test_temporary_reasons_ask_again_and_permanent_reasons_close_the_question(): void
    {
        $this->submitCheckout([
            $this->unavailable('plate', 'not_accessible_now'),
            $this->unavailable('year', 'location_unknown'),
            $this->unavailable('make', 'other', "  Under\n a cover  "),
            $this->unavailable('model', 'not_present'),
        ])->assertCreated();

        $states = ApparatusIdentificationQuestion::query()->pluck('state', 'field')->all();
        $this->assertSame(['plate' => 'open', 'year' => 'open', 'make' => 'open', 'model' => 'not_applicable'], $states);
        $this->assertSame(['plate', 'year', 'make'], $this->openFields($this->unit), 'temporary reasons are asked again next checkout');
        $other = ApparatusIdentificationEvent::query()->where('field', 'make')->where('event', 'unavailable')->sole();
        $this->assertSame('other', $other->reason);
        $this->assertSame('Under a cover', $other->note);
        $this->assertSame('not_present', ApparatusIdentificationQuestion::query()->where('field', 'model')->value('last_reason'));
        $this->assertSame(['permanent' => true], ApparatusIdentificationEvent::query()->where('field', 'model')->value('metadata'));
    }

    public function test_a_later_answer_replaces_the_temporary_reason(): void
    {
        $this->submitCheckout([$this->unavailable('plate', 'location_unknown')])->assertCreated();
        $this->assertSame('location_unknown', ApparatusIdentificationQuestion::query()->where('field', 'plate')->value('last_reason'));
        $this->unit->refresh();

        $second = $this->payload();
        $second['vehicle_identification'] = ['questions_version' => 'x', 'answers' => [$this->answer('plate', 'XE1234')]];
        $second['engine_hours'] = 402.0;
        $second['miles'] = 1030;
        foreach ($second['field_values'] as &$field) {
            if ($field['id'] === 'mileage') {
                $field['value'] = 1030;
            }
        }
        unset($field);
        $this->postJson($this->submissionUrl(), $second)->assertCreated();

        $question = ApparatusIdentificationQuestion::query()->where('field', 'plate')->sole();
        $this->assertSame(['answered', 'XE1234', null], [$question->state, $question->answered_value, $question->last_reason]);
    }

    public function test_a_failure_while_recording_never_fails_the_checkout(): void
    {
        Schema::drop('apparatus_identification_events');
        Schema::drop('apparatus_identification_questions');

        $this->submitCheckout([$this->answer('plate', 'XE1234')])->assertCreated();

        $this->assertSame(1, ApparatusInspection::query()->count());
    }

    public function test_answers_about_a_different_physical_vehicle_are_kept_but_never_applied_to_the_unit(): void
    {
        $payload = $this->checkout([$this->answer('plate', 'XE1234'), $this->answer('make', 'Pierce')]);
        $payload['physical_vehicle'] = ['vehicle_number' => 'V-303', 'swap_answer' => 'swap_completed', 'note' => 'Engine 3 was in the shop.'];
        foreach ($payload['field_values'] as &$field) {
            if ($field['id'] === 'vehicle_num') {
                $field['value'] = 'V-303';
            }
        }
        unset($field);
        // Below the checkout's readings, so they are accepted on the reported truck.
        $this->frontline->forceFill(['current_miles' => 900, 'current_engine_hours' => 300])->save();

        $this->postJson($this->submissionUrl(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertNull($this->unitMaster()->tag_number);
        $this->assertNull($this->unitMaster()->make);
        $this->assertSame(0, ApparatusIdentificationQuestion::query()->count());
        $events = ApparatusIdentificationEvent::query()->orderBy('id')->get();
        $this->assertSame(['superseded', 'superseded'], $events->pluck('event')->all());
        $this->assertSame('different_vehicle_reported', $events->first()->metadata['why']);
        $this->assertEqualsCanonicalizing(['plate', 'year', 'make', 'model'], $this->openFields($this->unit), 'the unit is still asked next time');
    }

    public function test_the_fire_boat_session_path_records_answers_for_an_unlinked_boat(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31 09:00:00', 'America/New_York'));
        $this->actingAsCanonicalUser($this->member);
        $station = Station::query()->create(['station_number' => 6, 'name' => 'Station 6', 'address' => '123 Marine Drive', 'is_active' => true]);
        $boat = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'Fire Boat 6',
            'type' => 'Fire Boat', 'slug' => 'fb6', 'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);
        $start = $this->postJson("/api/public/apparatuses/{$boat->id}/inspection-sessions")->assertCreated();
        $cookie = collect($start->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'daily_checkout_inspection_browser');
        $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        $contract = $start->json('inspection_session');
        $payload = $this->fireBoatCheckout($boat, $contract);
        $payload['vehicle_identification'] = [
            'questions_version' => $start->json('vehicle_identification.questions_version'),
            'answers' => [
                $this->answer('vehicle_number', 'FB-6'),
                $this->answer('vin', 'mtk 12345 a515'),
                $this->unavailable('plate', 'not_present'),
                $this->answer('year', '2019'),
                $this->answer('make', 'MetalShark'),
                $this->answer('model', 'Courageous 28'),
            ],
        ];

        $this->postJson("/api/public/apparatuses/{$boat->id}/inspections", $payload)->assertCreated();

        $this->assertSame(
            ['vin' => 'MTK12345A515', 'year' => '2019', 'make' => 'MetalShark', 'model' => 'Courageous 28'],
            ApparatusIdentificationQuestion::query()->where('apparatus_id', $boat->id)->where('state', 'answered')->pluck('answered_value', 'field')->all(),
        );
        $this->assertSame('not_applicable', ApparatusIdentificationQuestion::query()->where('field', 'plate')->value('state'));
        $boat->refresh();
        $this->assertSame('linked', $boat->master_link_status, 'a boat nobody could match is created for Fleet to verify');
        $this->assertSame('boat', $boat->masterVehicle->asset_category);
        $this->assertSame([], $this->openFields($boat));
    }

    public function test_the_fire_boat_still_identifies_when_its_checkout_reports_a_physical_vehicle(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31 09:00:00', 'America/New_York'));
        $this->actingAsCanonicalUser($this->member);
        $station = Station::query()->create(['station_number' => 6, 'name' => 'Station 6', 'address' => '123 Marine Drive', 'is_active' => true]);
        $boat = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'Fire Boat 6',
            'type' => 'Fire Boat', 'slug' => 'fb6', 'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);
        $start = $this->postJson("/api/public/apparatuses/{$boat->id}/inspection-sessions")->assertCreated();
        $cookie = collect($start->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'daily_checkout_inspection_browser');
        $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        $payload = $this->fireBoatCheckout($boat, $start->json('inspection_session'));
        $payload['processing_version'] = 1;
        $payload['compartments'] = array_map(fn (array $compartment): array => [
            ...$compartment,
            'items' => array_map(fn (array $item): array => [...$item, 'observed' => true], $compartment['items']),
        ], $payload['compartments']);
        $payload['scheduled_tasks'] = array_map(fn (array $task): array => [...$task, 'observed' => true], $payload['scheduled_tasks']);
        $payload['shift'] = 'A';
        $payload['officer_signature'] = self::SIGNATURE;
        $payload['physical_vehicle'] = ['vehicle_number' => 'FB-6', 'swap_answer' => 'swap_completed', 'note' => 'Named at the vehicle confirmation step.'];
        $payload['vehicle_identification'] = [
            'questions_version' => $start->json('vehicle_identification.questions_version'),
            'answers' => [$this->answer('vehicle_number', 'FB-6'), $this->answer('vin', 'mtk 12345 a515')],
        ];

        $response = $this->postJson("/api/public/apparatuses/{$boat->id}/inspections", $payload);

        $response->assertCreated();
        $this->assertSame(0, ApparatusChangeout::query()->count(), 'a unit with no number on file has nothing to change from');
        $this->assertNull($response->json('vehicle_swap_pending'));
        $this->assertSame(0, ApparatusIdentificationEvent::query()->where('event', 'superseded')->count(), 'nothing was dropped');
        $this->assertSame(['MTK12345A515'], ApparatusIdentificationQuestion::query()->where('state', 'answered')->orderBy('id')->pluck('answered_value')->all(), 'a fleet number from an older phone is not recorded for a boat');
        $this->assertSame('boat', $boat->fresh()->masterVehicle?->asset_category, 'resolved into a boat record for Fleet to verify');
        $this->assertNull($boat->fresh()->masterVehicle?->veh_number);
        $this->assertNull($boat->fresh()->vehicle_number);
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function fireBoatCheckout(Apparatus $boat, array $contract): array
    {
        $checklist = app(DailyCheckoutChecklistResolver::class)->resolve($boat)['checklist'];

        return [
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
                    'date' => '2026-08-31', 'number', 'percentage' => 1, 'checkbox' => true, default => 'Recorded',
                },
            ], $checklist['fields']),
            'scheduled_tasks' => array_map(static fn (array $task): array => ['id' => $task['id'], 'status' => 'Present', 'notes' => null], $contract['due_tasks']),
            'inspection_session_id' => $contract['id'],
            'inspection_session_token' => $contract['token'],
            'inspection_session_replay_key' => $contract['replay_key'],
        ];
    }
}
