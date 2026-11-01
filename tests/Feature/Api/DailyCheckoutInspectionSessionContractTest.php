<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\DailyCheckoutInspectionSession;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyCheckoutInspectionSessionContractTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/New_York';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsCanonicalFixture();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_minimal_fb6_with_null_fleet_metadata_survives_the_full_daily_api_flow(): void
    {
        $station = Station::query()->create([
            'station_number' => 6,
            'name' => 'Station 6',
            'address' => '123 Marine Drive',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'FB6',
            'name' => 'Fire Boat 6',
            'designation' => 'Fire Boat 6',
            'type' => 'Fire Boat',
            'slug' => 'fb6',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);

        $this->getJson('/api/public/apparatuses')
            ->assertOk()
            ->assertJsonPath('0.id', $apparatus->id)
            ->assertJsonPath('0.vehicle_number', null)
            ->assertJsonMissingPath('0.make')
            ->assertJsonMissingPath('0.model')
            ->assertJsonMissingPath('0.vin')
            ->assertJsonMissingPath('0.year')
            ->assertJsonMissingPath('0.snipeit_asset_id');
        $this->getJson("/api/public/stations/{$station->id}/apparatus")
            ->assertOk()
            ->assertJsonPath('apparatuses.0.id', $apparatus->id)
            ->assertJsonPath('apparatuses.0.vehicle_number', null);
        $this->get('/daily/stations/6')->assertOk();
        $this->getJson("/api/public/apparatuses/{$apparatus->id}/checklist")
            ->assertOk()
            ->assertJsonPath('checklist_type', 'fireboat6')
            ->assertJsonPath('apparatus.vehicle_number', null);

        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $contract, '10101010-1010-4010-8010-101010101010'),
        )->assertCreated();
    }

    public function test_the_payload_the_app_really_sends_for_fb6_is_accepted_with_no_vehicle_id(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $checklist = $this->getJson("/api/public/apparatuses/{$apparatus->id}/checklist")
            ->assertOk()
            ->assertJsonPath('vehicle_id_required', false)
            ->assertJsonPath('apparatus.vehicle_id_required', false)
            ->assertJsonPath('apparatus.vehicle_number', null);
        $this->assertNotContains('vehicle_number', array_column($checklist->json('vehicle_identification.questions'), 'field'));

        $contract = $this->startInspectionSession($apparatus);
        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $this->appSubmission($apparatus, $contract, '20202020-2020-4020-8020-202020202020'))
            ->assertCreated();

        $apparatus->refresh();
        $this->assertNull($apparatus->vehicle_number);
        $this->assertNull($apparatus->unit_master_vehicle_id);
        $this->assertDatabaseHas('apparatus_inspections', [
            'apparatus_id' => $apparatus->id,
            'vehicle_number' => null,
            'unit_number' => null,
            'designation_at_time' => $apparatus->designation,
            'processing_status' => 'accepted',
            'review_status' => 'approved',
        ]);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        $this->assertNotNull(DailyCheckoutInspectionSession::sole()->submitted_inspection_id);
    }

    public function test_a_retry_of_the_same_fb6_submission_records_one_inspection(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->appSubmission($apparatus, $contract, '21212121-2121-4121-8121-212121212121');

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)->assertCreated();
        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)->assertOk();

        $this->assertDatabaseCount('apparatus_inspections', 1);
    }

    public function test_an_older_phone_naming_a_vehicle_on_fb6_records_nothing_about_a_vehicle(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->appSubmission($apparatus, $contract, '22222222-2222-4222-8222-222222222223');
        $payload['physical_vehicle'] = ['vehicle_number' => 'FB6-1'];

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)->assertCreated();

        $this->assertNull($apparatus->fresh()->vehicle_number);
        $this->assertDatabaseCount('apparatus_changeouts', 0);
        $this->assertDatabaseMissing('apparatus_inspection_review_events', ['status' => 'vehicle_named']);
    }

    public function test_a_second_member_is_not_locked_out_while_another_members_fb6_inspection_is_open(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $this->startInspectionSession($apparatus);

        $second = $this->actingAsCanonicalFixture('E01-SECOND-MEMBER');
        $this->flushBrowserBinding();
        $contract = $this->startInspectionSession($apparatus);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $this->appSubmission($apparatus, $contract, '23232323-2323-4323-8323-232323232323'))
            ->assertCreated();
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 2);
        $this->assertDatabaseHas('apparatus_inspections', ['apparatus_id' => $apparatus->id, 'actor_user_id' => $second->id]);
    }

    public function test_the_same_member_on_another_device_the_same_day_gets_their_own_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $this->startInspectionSession($apparatus);

        $this->flushBrowserBinding();
        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions", ['inspection_session_start_key' => '24242424-2424-4424-8424-242424242424'])
            ->assertCreated();

        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 2);
    }

    public function test_the_readings_and_identifiers_a_member_enters_are_kept_with_their_units(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->appSubmission($apparatus, $contract, '25252525-2525-4525-8525-252525252525');
        $entered = [
            'fb6-port-engine-hours' => 1204.5, 'fb6-star-engine-hours' => 1201, 'fb6-generator-hours' => 600,
            'fb6-port-engine-voltage' => 13.8, 'fb6-star-engine-voltage' => 13.7,
            'fb6-port-engine-temperature' => 182, 'fb6-star-engine-temperature' => 180,
            'fb6-fuel-level' => 75, 'fb6-scba-id-1' => 'A-101', 'fb6-scuba-ids' => 'T-1, T-2',
            'fb6-driver-radio-number' => '6101', 'fb6-driver-radio-last-four-serial' => '4821',
        ];
        $payload['field_values'] = array_map(static fn (array $field): array => array_key_exists($field['id'], $entered)
            ? ['id' => $field['id'], 'value' => $entered[$field['id']]]
            : $field, $payload['field_values']);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)->assertCreated();

        $evidence = collect(ApparatusInspection::query()->sole()->checklist_evidence)->keyBy('id');
        foreach ($entered as $id => $value) {
            $this->assertSame($value, $evidence[$id]['value'], $id);
        }
        $this->assertSame('Starboard engine hours', $evidence['fb6-star-engine-hours']['name']);
        $this->assertSame('Port engine running temperature (°F)', $evidence['fb6-port-engine-temperature']['name'], 'the record says what the number means');
        $this->assertSame('SCUBA equipment ID numbers', $evidence['fb6-scuba-ids']['name']);
        $this->assertNotSame($evidence['fb6-scuba-ids']['name'], $evidence['fb6-scba-id-1']['name'], 'SCUBA and airpacks are different equipment');
    }

    public function test_an_impossible_reading_is_refused_and_a_blank_one_is_not(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->appSubmission($apparatus, $contract, '26262626-2626-4626-8626-262626262626');
        $with = static fn (string $id, mixed $value): array => [
            ...$payload,
            'field_values' => array_map(static fn (array $field): array => $field['id'] === $id ? ['id' => $id, 'value' => $value] : $field, $payload['field_values']),
        ];

        foreach ([['fb6-port-engine-voltage', -1], ['fb6-generator-hours', -0.5], ['fb6-fuel-level', 101], ['fb6-fuel-level', -1], ['fb6-star-engine-temperature', 'hot']] as [$id, $value]) {
            $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $with($id, $value))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('field_values');
        }
        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $with('fb6-fuel-level', null))->assertCreated();
    }

    public function test_a_duty_not_completed_is_recorded_and_opens_no_equipment_defect(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $this->assertNotSame([], $contract['due_tasks'], 'Monday has a due duty');
        $payload = $this->appSubmission($apparatus, $contract, '27272727-2727-4727-8727-272727272727');
        $payload['scheduled_tasks'] = array_map(static fn (array $task): array => ['id' => $task['id'], 'status' => 'Not Completed', 'notes' => 'Lift out of service', 'observed' => true], $contract['due_tasks']);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)->assertCreated();

        $this->assertDatabaseCount('apparatus_defects', 0);
        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame('approved', $inspection->review_status);
        $event = $inspection->reviewEvents()->where('status', 'accepted')->sole();
        $this->assertSame('Not Completed', $event->metadata['submitted_effects']['checklist_v2']['scheduled_tasks'][0]['status']);
        $this->assertSame('Lift out of service', $event->metadata['submitted_effects']['checklist_v2']['scheduled_tasks'][0]['notes']);
    }

    public function test_a_queued_older_payload_with_the_equipment_words_keeps_its_meaning(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->appSubmission($apparatus, $contract, '28282828-2828-4828-8828-282828282828');
        $payload['scheduled_tasks'] = array_map(static fn (array $task): array => ['id' => $task['id'], 'status' => 'Missing', 'notes' => null, 'observed' => true], $contract['due_tasks']);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)->assertCreated();

        $this->assertDatabaseHas('apparatus_defects', ['apparatus_id' => $apparatus->id, 'compartment' => 'Scheduled duties', 'issue_type' => 'missing']);
    }

    public function test_a_session_issued_on_the_earlier_template_still_submits_after_the_template_changes(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $session = DailyCheckoutInspectionSession::sole();
        $original = $session->checklist_snapshot;
        // A session frozen before the new readings: only the fields it was issued with may be sent, and it stays valid.
        $older = $original;
        $older['template_version'] = '2026-07';
        $older['fields'] = array_values(array_filter($original['fields'], static fn (array $field): bool => ! in_array($field['id'], ['fb6-fuel-level', 'fb6-scuba-ids'], true)));
        $hash = hash('sha256', json_encode($this->canonical($older), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        DB::table('daily_checkout_inspection_sessions')->where('id', $session->id)->update(['checklist_snapshot' => json_encode($this->canonical($older)), 'checklist_hash' => $hash, 'checklist_template_version' => '2026-07']);

        $payload = $this->appSubmission($apparatus, $contract, '29292929-2929-4929-8929-292929292929');
        $payload['checklist_version'] = $hash;
        $payload['field_values'] = array_values(array_filter($payload['field_values'], static fn (array $field): bool => ! in_array($field['id'], ['fb6-fuel-level', 'fb6-scuba-ids'], true)));

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)->assertCreated();
        $this->assertSame($hash, ApparatusInspection::query()->sole()->checklist_version);
    }

    /** @param array<mixed> $value */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $entry): mixed => is_array($entry) ? $this->canonical($entry) : $entry, $value);
    }

    public function test_a_boat_has_no_vehicle_change_but_a_truck_still_does(): void
    {
        $boat = $this->makeFireBoat6();

        $this->getJson("/api/public/apparatuses/{$boat->id}/changeout-context")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Fire Boat 6 does not use vehicle changes.');

        $truck = Apparatus::query()->create([
            'station_id' => $boat->station_id, 'unit_id' => 'E6', 'name' => 'Engine 6', 'designation' => 'E6', 'type' => 'Engine',
            'slug' => 'e6', 'vehicle_number' => '20503', 'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine',
        ]);
        $this->getJson("/api/public/apparatuses/{$truck->id}/changeout-context")->assertOk();
        $this->getJson("/api/public/apparatuses/{$truck->id}/checklist")->assertOk()->assertJsonPath('vehicle_id_required', true);
    }

    public function test_normal_same_day_online_fire_boat_inspection_uses_a_persisted_server_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $actor = $this->actingAsCanonicalFixture('E01-SESSION-ACTOR');

        $contract = $this->startInspectionSession($apparatus);

        $this->assertSame('2026-08-31', $contract['duty_date']);
        $this->assertSame('fire_boat_6_daily', $contract['checklist_template_id']);
        $this->assertSame('2026-11', $contract['checklist_template_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['checklist_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['due_tasks_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $contract['replay_key']);
        $this->assertSame(['fb6-monday-fuel-tank-hold'], array_column($contract['due_tasks'], 'id'));

        $this->assertDatabaseHas('daily_checkout_inspection_sessions', [
            'public_id' => $contract['id'],
            'apparatus_id' => $apparatus->id,
            'actor_user_id' => $actor->id,
            'checklist_hash' => $contract['checklist_hash'],
            'due_tasks_hash' => $contract['due_tasks_hash'],
            'replay_key' => $contract['replay_key'],
        ]);
        $persistedContract = DailyCheckoutInspectionSession::sole();
        $this->assertInstanceOf(CarbonImmutable::class, $persistedContract->issued_at);
        $this->assertInstanceOf(CarbonImmutable::class, $persistedContract->duty_date);
        $this->assertIsArray($persistedContract->checklist_snapshot);
        $this->assertIsArray($persistedContract->due_tasks);
        $this->assertInstanceOf(CarbonImmutable::class, $persistedContract->expires_at);
        $this->assertNull($persistedContract->abandoned_at);
        $this->assertSame('2026-08-31', $persistedContract->duty_date?->toDateString());
        $this->assertNotSame($contract['token'], $persistedContract->token_hash);

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $contract, '11111111-1111-4111-8111-111111111111'),
        )->assertCreated();
    }

    public function test_same_authenticated_actor_reuses_its_active_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $this->actingAsCanonicalFixture('E01-SESSION-REUSE');

        $first = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();
        $browserCookie = $this->browserBindingCookieFrom($first);
        $retry = $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue())
            ->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertOk();

        $retry
            ->assertJsonPath('inspection_session.id', $first->json('inspection_session.id'))
            ->assertJsonPath('inspection_session.token', $first->json('inspection_session.token'));
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);
    }

    public function test_contract_started_before_midnight_keeps_its_original_duty_date_after_midnight(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-30 23:55:00');
        $contract = $this->startInspectionSession($apparatus);

        $this->assertSame('2026-08-30', $contract['duty_date']);
        $this->assertSame([], $contract['due_tasks']);

        $this->setTestTime('2026-08-31 00:05:00');
        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $contract, '22222222-2222-4222-8222-222222222222'),
        )->assertCreated();
    }

    public function test_temporary_offline_period_then_reconnect_uses_the_issued_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);

        // No server interaction occurs while the PWA is temporarily offline.
        $this->setTestTime('2026-08-31 11:00:00');

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $contract, '33333333-3333-4333-8333-333333333333'),
        )->assertCreated();
    }

    public function test_duplicate_retry_for_one_contract_is_idempotent(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->fireBoatSubmission($apparatus, $contract, '44444444-4444-4444-8444-444444444444');

        $first = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertCreated();

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'));

        $this->assertDatabaseCount('apparatus_inspections', 1);
    }

    public function test_replay_waiting_on_the_apparatus_lock_returns_the_receipt_written_by_the_first_request(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->fireBoatSubmission($apparatus, $contract, '55555555-5555-4555-8555-555555555555');
        $test = $this;
        $firstResponse = null;

        // Sequential simulation of the race: the replay has passed the early
        // idempotency checks (no row yet), then waits on the apparatus lock
        // while the original request commits its inspection.
        $controller = new class(function () use ($test, $apparatus, $payload, &$firstResponse): void {
            $firstResponse = $test->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload);
        }) extends \App\Http\Controllers\Api\ApparatusController
        {

            private bool $firstRequestCompleted = false;

            public function __construct(private readonly \Closure $completeFirstRequest) {}

            protected function lockApparatusForInspection(int $id): Apparatus
            {
                if (! $this->firstRequestCompleted) {
                    $this->firstRequestCompleted = true;
                    ($this->completeFirstRequest)();
                }

                return parent::lockApparatusForInspection($id);
            }
        };
        $this->app->instance(\App\Http\Controllers\Api\ApparatusController::class, $controller);

        $replay = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload);

        $this->assertNotNull($firstResponse);
        $firstResponse->assertCreated();
        $replay->assertOk()->assertJsonPath('id', $firstResponse->json('id'));
        $this->assertDatabaseCount('apparatus_inspections', 1);
    }

    public function test_client_cannot_change_the_server_issued_duty_date(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->fireBoatSubmission($apparatus, $contract, '55555555-5555-4555-8555-555555555555');

        foreach ($payload['field_values'] as &$fieldValue) {
            if ($fieldValue['id'] === 'inspection_date') {
                $fieldValue['value'] = '2026-09-01';
            }
        }
        unset($fieldValue);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['field_values']);

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_client_cannot_change_the_server_issued_scheduled_task_set(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);
        $payload = $this->fireBoatSubmission($apparatus, $contract, '66666666-6666-4666-8666-666666666666');
        $payload['scheduled_tasks'] = [];

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_tasks']);

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_expired_contract_cannot_create_an_inspection(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);

        $this->setTestTime('2026-08-31 21:00:01');
        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $contract, '77777777-7777-4777-8777-777777777777'),
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_EXPIRED');

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_unbound_offline_draft_cannot_be_silently_backdated_or_submitted(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');

        $unboundDraft = $this->fireBoatSubmissionWithoutContract(
            $apparatus,
            '2026-08-30',
            '88888888-8888-4888-8888-888888888888',
        );

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $unboundDraft)
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_REQUIRED');

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_weekday_fire_boat_duty_is_snapshotted_and_accepted_from_its_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $contract = $this->startInspectionSession($apparatus);

        $this->assertSame(['fb6-monday-fuel-tank-hold'], array_column($contract['due_tasks'], 'id'));

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $contract, '99999999-9999-4999-8999-999999999999'),
        )->assertCreated();
    }

    public function test_first_day_of_month_fire_boat_duty_is_snapshotted_and_accepted_from_its_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-11-01 09:00:00');
        $contract = $this->startInspectionSession($apparatus);

        $this->assertSame(['fb6-monthly-first-day'], array_column($contract['due_tasks'], 'id'));

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $contract, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
        )->assertCreated();
    }

    public function test_authenticated_fire_boat_contract_is_bound_to_an_http_only_browser_cookie(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');

        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();
        $browserCookie = $this->browserBindingCookieFrom($response);
        $contract = $response->json('inspection_session');
        $this->assertIsArray($contract);

        $persisted = DailyCheckoutInspectionSession::sole();
        $this->assertSame(hash('sha256', $this->decryptedCookieValue($browserCookie)), $persisted->actor_session_hash);

        $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), str_repeat('f', 64))
            ->postJson(
                "/api/public/apparatuses/{$apparatus->id}/inspections",
                $this->fireBoatSubmission($apparatus, $contract, 'abababab-abab-4bab-8bab-abababababab'),
            )
            ->assertForbidden()
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_ACTOR_MISMATCH');

        $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue())
            ->postJson(
                "/api/public/apparatuses/{$apparatus->id}/inspections",
                $this->fireBoatSubmission($apparatus, $contract, 'abababab-abab-4bab-8bab-abababababac'),
            )
            ->assertCreated();
    }

    public function test_same_authenticated_browser_reuses_one_active_contract_and_preserves_the_expired_unsubmitted_row(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');

        $first = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();
        $browserCookie = $this->browserBindingCookieFrom($first);
        $firstContract = $first->json('inspection_session');
        $this->assertIsArray($firstContract);

        $sameSession = $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue())
            ->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertOk();
        $sameSession
            ->assertJsonPath('inspection_session.id', $firstContract['id'])
            ->assertJsonPath('inspection_session.token', $firstContract['token']);
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);

        $this->setTestTime('2026-08-31 21:00:01');
        $replacement = $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue())
            ->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();

        $replacement->assertJsonPath('inspection_session.duty_date', '2026-08-31');
        $this->assertDatabaseHas('daily_checkout_inspection_sessions', ['public_id' => $firstContract['id']]);
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 2);
    }

    public function test_public_start_retry_after_a_lost_first_response_reuses_the_same_issuance_key_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $payload = ['inspection_session_start_key' => 'dededede-dede-4ede-8ede-dededededede'];

        // Intentionally do not retain the first Set-Cookie response. This
        // models a connection loss after the server persisted the contract but
        // before the browser received it.
        $first = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions", $payload)
            ->assertCreated();
        $retry = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions", $payload)
            ->assertOk();

        $retry
            ->assertJsonPath('inspection_session.id', $first->json('inspection_session.id'))
            ->assertJsonPath('inspection_session.token', $first->json('inspection_session.token'));
        $this->assertSame(
            $this->decryptedCookieValue($this->browserBindingCookieFrom($first)),
            $this->decryptedCookieValue($this->browserBindingCookieFrom($retry)),
        );
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);
    }

    public function test_new_issuance_keys_cannot_bypass_same_browser_active_contract_reuse(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');

        $first = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();
        $browserCookie = $this->browserBindingCookieFrom($first);
        $firstContract = $first->json('inspection_session');
        $this->assertIsArray($firstContract);

        $freshKey = $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue())
            ->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions", [
                'inspection_session_start_key' => 'efefefef-efef-4fef-8fef-efefefefefef',
            ])
            ->assertOk();

        $freshKey->assertJsonPath('inspection_session.id', $firstContract['id']);
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);
    }

    public function test_replay_key_must_match_the_server_issued_contract_before_a_new_inspection_is_written(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions");
        $browserCookie = $this->browserBindingCookieFrom($response);
        $contract = $response->json('inspection_session');
        $this->assertIsArray($contract);
        $payload = $this->fireBoatSubmission($apparatus, $contract, 'bcbcbcbc-bcbc-4cbc-8cbc-bcbcbcbcbcbc');
        $payload['inspection_session_replay_key'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

        $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue())
            ->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_REPLAY_MISMATCH');

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_tampered_persisted_checklist_snapshot_is_rejected_before_submission(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions");
        $browserCookie = $this->browserBindingCookieFrom($response);
        $contract = $response->json('inspection_session');
        $this->assertIsArray($contract);
        $persisted = DailyCheckoutInspectionSession::sole();
        $snapshot = $persisted->checklist_snapshot;
        $this->assertIsArray($snapshot);
        $snapshot['template_version'] = 'tampered';
        DB::table('daily_checkout_inspection_sessions')
            ->where('id', $persisted->id)
            ->update(['checklist_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);

        $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue())
            ->postJson(
                "/api/public/apparatuses/{$apparatus->id}/inspections",
                $this->fireBoatSubmission($apparatus, $contract, 'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd'),
            )
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_INVALID');

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_lost_local_storage_after_midnight_recovers_the_valid_cookie_bound_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-30 23:55:00');
        $prior = $this->startInspectionSession($apparatus);

        // The browser lost its local issuance key/autosave at midnight, but
        // its HTTP-only server binding remains. The server must recover the
        // prior incomplete contract instead of issuing a second duty date.
        $this->setTestTime('2026-08-31 00:05:00');
        $recovered = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions", [
            'inspection_session_start_key' => '10101010-1010-4010-8010-101010101010',
        ])->assertOk();

        $recovered
            ->assertJsonPath('inspection_session.id', $prior['id'])
            ->assertJsonPath('inspection_session.duty_date', '2026-08-30');
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);
    }

    public function test_expired_contract_is_preserved_and_a_new_contract_may_begin(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-30 09:00:00');
        $expired = $this->startInspectionSession($apparatus);

        $this->setTestTime('2026-08-31 00:05:00');
        $replacement = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();

        $replacement
            ->assertJsonPath('inspection_session.duty_date', '2026-08-31')
            ->assertJsonPath('inspection_session.id', fn (string $id): bool => $id !== $expired['id']);
        $this->assertDatabaseHas('daily_checkout_inspection_sessions', ['public_id' => $expired['id']]);
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 2);
    }

    public function test_completed_contract_allows_a_new_contract_without_abandonment(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $completed = $this->startInspectionSession($apparatus);

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $completed, '12121212-1212-4212-8212-121212121212'),
        )->assertCreated();

        $replacement = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();

        $replacement->assertJsonPath('inspection_session.id', fn (string $id): bool => $id !== $completed['id']);
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 2);
    }

    public function test_explicit_abandonment_preserves_the_prior_contract_and_issues_todays_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $actor = $this->actingAsCanonicalFixture('E01-ABANDON-ACTOR');
        $this->setTestTime('2026-08-30 23:55:00');
        $prior = $this->startInspectionSession($apparatus);

        $this->setTestTime('2026-08-31 00:05:00');
        $transition = $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspection-sessions/{$prior['id']}/abandon",
            $this->abandonmentPayload($prior, '13131313-1313-4313-8313-131313131313'),
        )->assertCreated();
        $today = $transition->json('inspection_session');
        $this->assertIsArray($today);

        $transition
            ->assertJsonPath('checklist.template_id', 'fire_boat_6_daily')
            ->assertJsonPath('checklist.template_version', '2026-11')
            ->assertJsonPath('inspection_session.duty_date', '2026-08-31');
        $priorRecord = DailyCheckoutInspectionSession::query()->where('public_id', $prior['id'])->sole();
        $todayRecord = DailyCheckoutInspectionSession::query()->where('public_id', $today['id'])->sole();
        $this->assertNotNull($priorRecord->abandoned_at);
        $this->assertInstanceOf(CarbonImmutable::class, $priorRecord->abandoned_at);
        $this->assertTrue($priorRecord->abandoned_at->greaterThan($priorRecord->issued_at));
        $this->assertSame($actor->id, $priorRecord->abandoned_by_user_id);
        $this->assertSame($priorRecord->actor_session_hash, $priorRecord->abandoned_by_session_hash);
        $this->assertSame('operator_requested_start_today', $priorRecord->abandonment_reason);
        $this->assertSame('abandon_prior_inspection_start_today', $priorRecord->abandonment_transition_type);
        $this->assertSame('13131313-1313-4313-8313-131313131313', $priorRecord->abandonment_transition_key);
        $this->assertSame($priorRecord->id, $todayRecord->prior_inspection_session_id);
        $this->assertSame($todayRecord->id, $priorRecord->replacement_session_id);
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 2);
    }

    public function test_malformed_prior_expiry_fails_closed_without_issuing_a_replacement(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-30 23:55:00');
        $prior = $this->startInspectionSession($apparatus);
        DB::table('daily_checkout_inspection_sessions')
            ->where('public_id', $prior['id'])
            ->update(['expires_at' => 'not-a-date']);

        $this->setTestTime('2026-08-31 00:05:00');
        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspection-sessions/{$prior['id']}/abandon",
            $this->abandonmentPayload($prior, '18181818-1818-4818-8818-181818181818'),
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_EXPIRED');
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);
    }

    public function test_malformed_prior_duty_date_fails_closed_without_issuing_a_replacement(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-30 23:55:00');
        $prior = $this->startInspectionSession($apparatus);
        DB::table('daily_checkout_inspection_sessions')
            ->where('public_id', $prior['id'])
            ->update(['duty_date' => 'not-a-date']);

        $this->setTestTime('2026-08-31 00:05:00');
        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspection-sessions/{$prior['id']}/abandon",
            $this->abandonmentPayload($prior, '19191919-1919-4919-8919-191919191919'),
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_ABANDONMENT_NOT_REQUIRED');
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);
    }

    public function test_replayed_abandonment_transition_is_idempotent_and_does_not_issue_a_second_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-30 23:55:00');
        $prior = $this->startInspectionSession($apparatus);
        $payload = $this->abandonmentPayload($prior, '14141414-1414-4414-8414-141414141414');

        $this->setTestTime('2026-08-31 00:05:00');
        $first = $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspection-sessions/{$prior['id']}/abandon",
            $payload,
        )->assertCreated();
        $retry = $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspection-sessions/{$prior['id']}/abandon",
            $payload,
        )->assertOk();

        $retry->assertJsonPath('inspection_session.id', $first->json('inspection_session.id'));
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 2);
    }

    public function test_new_day_contract_is_not_issued_until_a_valid_explicit_transition_closes_the_prior_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-30 23:55:00');
        $prior = $this->startInspectionSession($apparatus);

        $this->setTestTime('2026-08-31 00:05:00');
        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertOk()
            ->assertJsonPath('inspection_session.id', $prior['id']);
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspection-sessions/{$prior['id']}/abandon",
            [],
        )->assertUnprocessable();
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspection-sessions/{$prior['id']}/abandon",
            $this->abandonmentPayload($prior, '15151515-1515-4515-8515-151515151515'),
        )
            ->assertCreated()
            ->assertJsonPath('inspection_session.duty_date', '2026-08-31');
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 2);
    }

    public function test_missing_browser_binding_cannot_abandon_a_valid_contract_or_create_a_new_duty_date(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-30 23:55:00');
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();
        $prior = $response->json('inspection_session');
        $this->assertIsArray($prior);
        $browserCookie = $this->browserBindingCookieFrom($response);

        $this->setTestTime('2026-08-31 00:05:00');
        $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), str_repeat('f', 64))
            ->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions", [
                'inspection_session_start_key' => '17171717-1717-4717-8717-171717171717',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_ACTIVE');

        $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), str_repeat('f', 64))
            ->postJson(
                "/api/public/apparatuses/{$apparatus->id}/inspection-sessions/{$prior['id']}/abandon",
                $this->abandonmentPayload($prior, '16161616-1616-4616-8616-161616161616'),
            )
            ->assertForbidden()
            ->assertJsonPath('code', 'DAILY_CHECKOUT_INSPECTION_SESSION_ACTOR_MISMATCH');

        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);
    }

    /** @return array<string, mixed> */
    private function startInspectionSession(Apparatus $apparatus): array
    {
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();
        $browserCookie = collect($response->headers->getCookies())
            ->first(static fn (\Symfony\Component\HttpFoundation\Cookie $cookie): bool => $cookie->getName() === 'daily_checkout_inspection_browser');
        if ($browserCookie instanceof \Symfony\Component\HttpFoundation\Cookie) {
            $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue());
        }
        $contract = $response->json('inspection_session');

        $this->assertIsArray($contract);

        return $contract;
    }

    private function browserBindingCookieFrom(\Illuminate\Testing\TestResponse $response): \Symfony\Component\HttpFoundation\Cookie
    {
        $browserCookie = collect($response->headers->getCookies())
            ->first(static fn (\Symfony\Component\HttpFoundation\Cookie $cookie): bool => $cookie->getName() === 'daily_checkout_inspection_browser');

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Cookie::class, $browserCookie);
        $this->assertTrue($browserCookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $browserCookie->getSameSite()));

        return $browserCookie;
    }

    private function decryptedCookieValue(\Symfony\Component\HttpFoundation\Cookie $cookie): string
    {
        $value = app('encrypter')->decrypt($cookie->getValue(), false);
        $this->assertIsString($value);

        return \Illuminate\Cookie\CookieValuePrefix::remove($value);
    }

    /** @return array<string, mixed> */
    private function fireBoatSubmission(Apparatus $apparatus, array $contract, string $clientSubmissionId): array
    {
        $payload = $this->fireBoatSubmissionWithoutContract($apparatus, (string) $contract['duty_date'], $clientSubmissionId);
        $payload['checklist_version'] = $contract['checklist_hash'];
        $payload['scheduled_tasks'] = array_map(static fn (array $task): array => [
            'id' => $task['id'],
            'status' => 'Present',
            'notes' => null,
        ], $contract['due_tasks']);
        $payload['inspection_session_id'] = $contract['id'];
        $payload['inspection_session_token'] = $contract['token'];
        $payload['inspection_session_replay_key'] = $contract['replay_key'];

        return $payload;
    }

    /** A new device: no inspection browser cookie yet. */
    private function flushBrowserBinding(): void
    {
        unset($this->defaultCookies['daily_checkout_inspection_browser'], $this->unencryptedCookies['daily_checkout_inspection_browser']);
    }

    /**
     * What InspectionWizard.handleSubmit really posts for a member who filled in only what is required:
     * processing_version 1, explicit observations, a signature, untouched fields as their empty values and no vehicle.
     *
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function appSubmission(Apparatus $apparatus, array $contract, string $clientSubmissionId): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $this->assertIsArray($resolution['checklist']);
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        return [
            'processing_version' => 1,
            'meter_baseline_token' => $this->getJson('/api/public/apparatuses')->json('0.meter_baseline_token'),
            'client_submission_id' => $clientSubmissionId,
            'checklist_version' => $contract['checklist_hash'],
            'operator_name' => 'Firefighter Example',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => 'FB6',
            'engine_hours' => null,
            'miles' => null,
            'compartments' => array_map(static fn (array $compartment): array => [
                'id' => $compartment['id'],
                'name' => $compartment['name'],
                'items' => array_map(static fn (array $item): array => [
                    'id' => $item['id'], 'name' => $item['name'], 'status' => 'Present', 'observed' => true, 'notes' => null,
                ], $compartment['items']),
            ], $resolution['checklist']['compartments']),
            'defects' => [],
            'field_values' => array_map(static fn (array $field): array => [
                'id' => $field['id'],
                'value' => match (true) {
                    $field['inputType'] === 'date' => $contract['duty_date'],
                    $field['id'] === 'fb6-shift' => 'A',
                    $field['inputType'] === 'checkbox' => false,
                    $field['inputType'] === 'number', $field['inputType'] === 'percentage' => null,
                    default => '',
                },
            ], $resolution['checklist']['fields']),
            'scheduled_tasks' => array_map(static fn (array $task): array => ['id' => $task['id'], 'status' => 'Present', 'notes' => null, 'observed' => true], $contract['due_tasks']),
            'inspection_session_id' => $contract['id'],
            'inspection_session_token' => $contract['token'],
            'inspection_session_replay_key' => $contract['replay_key'],
            'officer_signature' => $signature,
        ];
    }

    /** @return array<string, string> */
    private function abandonmentPayload(array $contract, string $transitionKey): array
    {
        return [
            'inspection_session_token' => (string) $contract['token'],
            'inspection_session_replay_key' => (string) $contract['replay_key'],
            'inspection_session_transition_key' => $transitionKey,
        ];
    }

    /** @return array<string, mixed> */
    private function fireBoatSubmissionWithoutContract(Apparatus $apparatus, string $inspectionDate, string $clientSubmissionId): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $checklist = $resolution['checklist'];
        $this->assertIsArray($checklist);

        return [
            'client_submission_id' => $clientSubmissionId,
            'checklist_version' => (string) $resolution['checklist_version'],
            'operator_name' => 'Firefighter Example',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $apparatus->vehicle_number,
            'engine_hours' => 1,
            'miles' => 1,
            'compartments' => array_map(static function (array $compartment): array {
                return [
                    'id' => $compartment['id'],
                    'name' => $compartment['name'],
                    'items' => array_map(static fn (array $item): array => [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'status' => 'Present',
                        'notes' => null,
                    ], $compartment['items']),
                ];
            }, $checklist['compartments']),
            'defects' => [],
            'field_values' => array_map(static function (array $field) use ($inspectionDate): array {
                $value = match ($field['inputType']) {
                    'date' => $inspectionDate,
                    'number', 'percentage' => 1,
                    'checkbox' => true,
                    default => 'Recorded',
                };

                return [
                    'id' => $field['id'],
                    'value' => $value,
                ];
            }, $checklist['fields']),
            'scheduled_tasks' => [],
        ];
    }

    private function setTestTime(string $localDateTime): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($localDateTime, self::TIMEZONE));
        $actor = $this->app['auth']->guard('web')->user();
        if ($actor instanceof User) {
            $this->actingAsCanonicalUser($actor);
        }
    }

    private function makeFireBoat6(): Apparatus
    {
        $station = Station::firstOrCreate(
            ['station_number' => 6],
            [
                'name' => 'Station 6',
                'address' => '123 Marine Drive',
                'is_active' => true,
            ],
        );

        return Apparatus::create([
            'station_id' => $station->id,
            'unit_id' => 'FB6',
            'name' => 'Fire Boat 6',
            'type' => 'Fire Boat',
            'class_description' => 'Marine',
            'vehicle_number' => null,
            'designation' => 'FB6',
            'slug' => 'fire-boat-6',
            'make' => 'Metal Shark',
            'model' => 'Fire Boat',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);
    }
}
