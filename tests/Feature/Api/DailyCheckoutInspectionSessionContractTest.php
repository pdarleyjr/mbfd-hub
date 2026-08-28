<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\DailyCheckoutInspectionSession;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyCheckoutInspectionSessionContractTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/New_York';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_normal_same_day_online_fire_boat_inspection_uses_a_persisted_server_contract(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');
        $actor = User::factory()->create();
        Sanctum::actingAs($actor);

        $contract = $this->startInspectionSession($apparatus);

        $this->assertSame('2026-08-31', $contract['duty_date']);
        $this->assertSame('fire_boat_6_daily', $contract['checklist_template_id']);
        $this->assertSame('2026-07', $contract['checklist_template_version']);
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
        $this->assertSame('2026-08-31', $persistedContract->duty_date?->toDateString());
        $this->assertNotSame($contract['token'], $persistedContract->token_hash);

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->fireBoatSubmission($apparatus, $contract, '11111111-1111-4111-8111-111111111111'),
        )->assertCreated();
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

    public function test_anonymous_fire_boat_contract_is_bound_to_an_http_only_browser_cookie(): void
    {
        $apparatus = $this->makeFireBoat6();
        $this->setTestTime('2026-08-31 09:00:00');

        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspection-sessions")
            ->assertCreated();
        $browserCookie = $this->browserBindingCookieFrom($response);
        $contract = $response->json('inspection_session');
        $this->assertIsArray($contract);

        $persisted = DailyCheckoutInspectionSession::sole();
        $this->assertSame(hash('sha256', $browserCookie->getValue()), $persisted->actor_session_hash);

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

    public function test_same_anonymous_browser_reuses_one_active_contract_and_prunes_the_expired_unsubmitted_row(): void
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
        $this->assertDatabaseMissing('daily_checkout_inspection_sessions', ['public_id' => $firstContract['id']]);
        $this->assertDatabaseCount('daily_checkout_inspection_sessions', 1);
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
            $this->browserBindingCookieFrom($first)->getValue(),
            $this->browserBindingCookieFrom($retry)->getValue(),
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
                    'number' => 1,
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
            'vehicle_number' => 'FB6',
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
