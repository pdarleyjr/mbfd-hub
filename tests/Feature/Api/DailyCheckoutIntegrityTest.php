<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyCheckoutIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_endpoint_serves_a_non_empty_tracked_checklist(): void
    {
        $apparatus = $this->makeApparatus('required');

        $response = $this->getJson("/api/public/apparatuses/{$apparatus->id}/checklist");

        $response->assertOk();

        $compartments = $response->json('checklist.compartments');
        $checklistVersion = $response->json('checklist_version');

        $this->assertIsArray($compartments);
        $this->assertNotEmpty($compartments);
        $this->assertNotEmpty($compartments[0]['items'] ?? []);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $checklistVersion);
        $this->assertSame(
            app(DailyCheckoutChecklistResolver::class)->resolve($apparatus)['checklist_version'],
            $checklistVersion,
        );
    }

    public function test_public_daily_checkout_catalog_only_exposes_explicitly_required_apparatus(): void
    {
        $required = $this->makeApparatus('required', 'Engine 1');
        $exempt = $this->makeApparatus('exempt', 'Reserve 1');
        $unknown = $this->makeApparatus('unknown', 'Support 1');

        $ids = collect($this->getJson('/api/public/apparatuses')->assertOk()->json())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains($required->id, $ids);
        $this->assertNotContains($exempt->id, $ids);
        $this->assertNotContains($unknown->id, $ids);
    }

    public function test_unknown_apparatus_cannot_accept_a_daily_checkout_submission(): void
    {
        $apparatus = $this->makeApparatus('unknown');

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->submissionWithChecklist($apparatus, '11111111-1111-4111-8111-111111111111')
        )->assertStatus(409);

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_client_submission_id_is_required_before_any_inspection_is_written(): void
    {
        $apparatus = $this->makeApparatus('required');
        $payload = $this->submissionPayload('11111111-1111-4111-8111-111111111111');
        unset($payload['client_submission_id']);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_submission_id']);

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_checklist_version_is_required_before_any_inspection_is_written(): void
    {
        $apparatus = $this->makeApparatus('required');
        $payload = $this->submissionWithChecklist($apparatus, 'aaaaaaaa-1111-4111-8111-111111111111');
        unset($payload['checklist_version']);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['checklist_version']);

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_stale_checklist_version_requires_review_without_writing_an_inspection(): void
    {
        $apparatus = $this->makeApparatus('required');
        $payload = $this->submissionWithChecklist($apparatus, 'bbbbbbbb-1111-4111-8111-111111111111');
        $payload['checklist_version'] = str_repeat('a', 64);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED')
            ->assertJsonPath(
                'current_checklist_version',
                app(DailyCheckoutChecklistResolver::class)->resolve($apparatus)['checklist_version'],
            );

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_same_client_submission_id_is_recorded_once_when_the_response_is_retried(): void
    {
        $apparatus = $this->makeApparatus('required');
        $apparatus->update([
            'current_engine_hours' => 100.0,
            'current_miles' => 10_000,
        ]);

        $payload = $this->submissionWithChecklist($apparatus, '22222222-2222-4222-8222-222222222222', [
            'engine_hours' => 101.5,
            'miles' => 10_025,
        ]);

        $first = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertCreated();

        $inspectionId = $first->json('id');

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertOk()
            ->assertJsonPath('id', $inspectionId);

        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertSame('101.5', ApparatusInspection::sole()->engine_hours);
        $this->assertSame(
            app(DailyCheckoutChecklistResolver::class)->resolve($apparatus)['checklist_version'],
            ApparatusInspection::sole()->checklist_version,
        );
        $this->assertSame('100.0', $apparatus->fresh()->current_engine_hours);
        $this->assertSame(10_000, $apparatus->fresh()->current_miles);
        $this->assertSame('pending_review', ApparatusInspection::sole()->review_status);
    }

    public function test_client_submission_id_cannot_be_reused_for_another_apparatus(): void
    {
        $firstApparatus = $this->makeApparatus('required', 'Engine 1');
        $secondApparatus = $this->makeApparatus('required', 'Engine 2');
        $clientSubmissionId = '33333333-3333-4333-8333-333333333333';

        $this->postJson(
            "/api/public/apparatuses/{$firstApparatus->id}/inspections",
            $this->submissionWithChecklist($firstApparatus, $clientSubmissionId)
        )->assertCreated();

        $this->postJson(
            "/api/public/apparatuses/{$secondApparatus->id}/inspections",
            $this->submissionWithChecklist($secondApparatus, $clientSubmissionId)
        )->assertStatus(409);

        $this->assertDatabaseCount('apparatus_inspections', 1);
    }

    public function test_client_submission_id_cannot_be_reused_with_a_changed_payload(): void
    {
        $apparatus = $this->makeApparatus('required');
        $clientSubmissionId = '34343434-3434-4343-8343-343434343434';
        $payload = $this->submissionWithChecklist($apparatus, $clientSubmissionId, [
            'miles' => 10_000,
        ]);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertCreated();

        $changedPayload = $payload;
        $changedPayload['miles'] = 10_001;

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $changedPayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT');

        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertSame(10_000, ApparatusInspection::sole()->miles);
    }

    public function test_client_submission_id_replay_accepts_equivalent_payload_key_order(): void
    {
        $apparatus = $this->makeApparatus('required');
        $clientSubmissionId = '35353535-3535-4353-8353-353535353535';
        $payload = $this->submissionWithChecklist($apparatus, $clientSubmissionId);

        $first = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertCreated();

        $reorderedPayload = array_reverse($payload, true);
        $reorderedPayload['compartments'] = array_map(
            static fn (array $compartment): array => array_reverse($compartment, true),
            $payload['compartments'],
        );

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $reorderedPayload)
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'));

        $this->assertDatabaseCount('apparatus_inspections', 1);
    }

    public function test_legacy_submission_without_a_payload_hash_cannot_be_blindly_replayed(): void
    {
        $apparatus = $this->makeApparatus('required');
        $clientSubmissionId = '36363636-3636-4363-8363-363636363636';
        $payload = $this->submissionWithChecklist($apparatus, $clientSubmissionId);

        ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'client_submission_id' => $clientSubmissionId,
            'operator_name' => 'Historical operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'review_status' => 'approved',
            'completed_at' => now(),
        ]);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT');

        $this->assertDatabaseCount('apparatus_inspections', 1);
    }

    public function test_invalid_defect_photo_does_not_leave_a_partial_inspection(): void
    {
        $apparatus = $this->makeApparatus('required');

        $payload = $this->submissionWithChecklist($apparatus, '44444444-4444-4444-8444-444444444444');
        $payload['compartments'][0]['items'][0]['status'] = 'Missing';
        $payload['defects'] = [[
            'compartment' => $payload['compartments'][0]['name'],
            'item' => $payload['compartments'][0]['items'][0]['name'],
            'status' => 'Missing',
            'photo' => 'not-an-image',
        ]];

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['defects.0.photo']);

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_submission_cannot_be_counted_as_complete_without_the_current_canonical_checklist(): void
    {
        $apparatus = $this->makeApparatus('required');
        $complete = $this->submissionWithChecklist($apparatus, '55555555-5555-4555-8555-555555555555');

        $cases = [
            'missing' => $this->submissionPayload('66666666-6666-4666-8666-666666666666', [
                'checklist_version' => $complete['checklist_version'],
            ]),
            'empty' => $this->submissionPayload('77777777-7777-4777-8777-777777777777', [
                'checklist_version' => $complete['checklist_version'],
                'compartments' => [],
            ]),
            'partial' => $this->submissionPayload('88888888-8888-4888-8888-888888888888', [
                'checklist_version' => $complete['checklist_version'],
                'compartments' => [reset($complete['compartments'])],
            ]),
            'tampered' => $this->submissionPayload('99999999-9999-4999-8999-999999999999', [
                'checklist_version' => $complete['checklist_version'],
                'compartments' => array_map(static function (array $compartment): array {
                    if ($compartment['items'] !== []) {
                        $compartment['items'][0]['name'] = 'Untracked item';
                    }

                    return $compartment;
                }, $complete['compartments']),
            ]),
        ];

        foreach ($cases as $payload) {
            $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['compartments']);
        }

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_submission_canonicalizes_whitespace_before_persistence_and_does_not_bypass_defect_deduplication(): void
    {
        $apparatus = $this->makeApparatus('required');
        $canonical = $this->submissionWithChecklist($apparatus, 'abababab-abab-4bab-8bab-abababababab');

        $tamperedCompartmentId = $canonical;
        $tamperedCompartmentId['compartments'][0]['id'] .= ' ';
        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $tamperedCompartmentId)
            ->assertCreated();
        $this->assertSame(
            $canonical['compartments'][0]['id'],
            ApparatusInspection::sole()->results[0]['id'],
        );

        $tamperedDefect = $this->submissionWithChecklist($apparatus, 'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd');
        $tamperedDefect['compartments'][0]['items'][0]['status'] = 'Missing';
        $tamperedDefect['defects'] = [[
            'compartment' => ' '.$tamperedDefect['compartments'][0]['name'],
            'item' => $tamperedDefect['compartments'][0]['items'][0]['name'].' ',
            'status' => 'Missing',
        ]];
        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $tamperedDefect)
            ->assertCreated();

        $repeatDefect = $tamperedDefect;
        $repeatDefect['client_submission_id'] = 'dededede-dede-4ede-8ede-dededededede';
        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $repeatDefect)
            ->assertCreated();

        $this->assertDatabaseCount('apparatus_inspections', 3);

        $this->actingAsAdmin();
        $defectInspections = ApparatusInspection::query()->orderBy('id')->get()->slice(1);
        foreach ($defectInspections as $inspection) {
            $this->postJson("/api/apparatus-inspections/{$inspection->id}/approve")
                ->assertOk();
        }

        $this->assertDatabaseCount('apparatus_defects', 1);
        $defect = ApparatusDefect::sole();
        $this->assertSame($canonical['compartments'][0]['name'], $defect->compartment);
        $this->assertSame($canonical['compartments'][0]['items'][0]['name'], $defect->item);
    }

    public function test_non_present_checklist_item_requires_a_matching_defect_record(): void
    {
        $apparatus = $this->makeApparatus('required');
        $payload = $this->submissionWithChecklist($apparatus, '12121212-1212-4121-8121-121212121212');
        $payload['compartments'][0]['items'][0]['status'] = 'Missing';

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['defects']);

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_fire_boat_6_v2_serves_only_due_recurring_tasks_and_persists_validated_typed_values(): void
    {
        $apparatus = $this->makeFireBoat6();

        $this->getJson("/api/public/apparatuses/{$apparatus->id}/checklist?inspection_date=2026-08-31")
            ->assertOk()
            ->assertJsonPath('checklist.schema_version', 2)
            ->assertJsonPath('checklist.template_id', 'fire_boat_6_daily')
            ->assertJsonPath('inspection_date', '2026-08-31')
            ->assertJsonCount(1, 'due_tasks')
            ->assertJsonPath('due_tasks.0.id', 'fb6-monday-fuel-tank-hold');

        $this->getJson("/api/public/apparatuses/{$apparatus->id}/checklist?inspection_date=2026-11-01")
            ->assertOk()
            ->assertJsonCount(1, 'due_tasks')
            ->assertJsonPath('due_tasks.0.id', 'fb6-monthly-first-day');

        $payload = $this->fireBoatSubmissionWithChecklist(
            $apparatus,
            'fbfbfbfb-fbfb-4bfb-8bfb-fbfbfbfbfbfb',
            '2026-08-31',
        );

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertCreated();

        $inspection = ApparatusInspection::sole()->refresh();
        $submittedChecklistV2 = $inspection->pending_effects['checklist_v2'];
        $this->assertSame('fire_boat_6_daily', $submittedChecklistV2['template_id']);
        $this->assertSame('2026-07', $submittedChecklistV2['template_version']);
        $this->assertSame([
            'id' => 'inspection_date',
            'name' => 'Date',
            'input_type' => 'date',
            'required' => true,
            'value' => '2026-08-31',
        ], $submittedChecklistV2['field_values'][0]);
        $this->assertSame([
            'id' => 'fb6-monday-fuel-tank-hold',
            'name' => 'Fuel Tank Hold',
            'instructions' => 'Fuel Tank Hold / Ex. Lubricate Valves / Check Fuel Filters / Sterilize Fresh Water Tank / Check Stokes Basket Condition- Secured',
            'recurrence' => [
                'type' => 'weekday',
                'weekday' => 'monday',
            ],
            'recurrence_label' => 'Every Monday',
            'status' => 'Present',
            'notes' => null,
        ], $submittedChecklistV2['scheduled_tasks'][0]);

        $this->actingAsAdmin();
        $this->postJson("/api/apparatus-inspections/{$inspection->id}/approve")
            ->assertOk();

        $reviewEvent = $inspection->refresh()->reviewEvents()->sole();
        $this->assertSame($submittedChecklistV2, $reviewEvent->metadata['submitted_effects']['checklist_v2']);

        $invalidValue = $this->fireBoatSubmissionWithChecklist(
            $apparatus,
            'abababab-fbfb-4bfb-8bfb-fbfbfbfbfbfb',
            '2026-08-31',
        );
        foreach ($invalidValue['field_values'] as &$fieldValue) {
            if ($fieldValue['id'] === 'fb6-port-engine-hours') {
                $fieldValue['value'] = 'not-a-number';
            }
        }
        unset($fieldValue);

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $invalidValue)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['field_values']);

        $missingDueTask = $this->fireBoatSubmissionWithChecklist(
            $apparatus,
            'cdcdcdcd-fbfb-4bfb-8bfb-fbfbfbfbfbfb',
            '2026-08-31',
        );
        $missingDueTask['scheduled_tasks'] = [];

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $missingDueTask)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_tasks']);

        $notDueToday = $this->fireBoatSubmissionWithChecklist(
            $apparatus,
            'cfcfcfcf-fbfb-4bfb-8bfb-fbfbfbfbfbfb',
            '2026-08-31',
        );
        $notDueToday['scheduled_tasks'] = [[
            'id' => 'fb6-monthly-first-day',
            'status' => 'Present',
            'notes' => null,
        ]];

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $notDueToday)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_tasks']);

        $wrongMachineId = $this->fireBoatSubmissionWithChecklist(
            $apparatus,
            'dededede-fbfb-4bfb-8bfb-fbfbfbfbfbfb',
            '2026-08-31',
        );
        $wrongMachineId['compartments'][0]['items'][0]['id'] = 'not-the-canonical-item-id';

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $wrongMachineId)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['compartments']);
    }

    public function test_legacy_v1_submission_remains_accepted_without_v2_field_or_schedule_payloads(): void
    {
        $apparatus = $this->makeApparatus('required');

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->submissionWithChecklist($apparatus, 'efefefef-efef-4fef-8fef-efefefefefef'),
        )->assertCreated();

        $inspection = ApparatusInspection::sole();
        $this->assertArrayNotHasKey('checklist_v2', $inspection->pending_effects);
    }

    public function test_public_inspection_response_is_a_minimal_receipt(): void
    {
        $apparatus = $this->makeApparatus('required');

        $response = $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->submissionWithChecklist($apparatus, '13131313-1313-4131-8131-131313131313'),
        )->assertCreated();

        $receipt = $response->json();
        $this->assertArrayHasKey('inspection_reference', $receipt);
        $this->assertArrayNotHasKey('client_submission_id', $receipt);
        $this->assertArrayNotHasKey('operator_name', $receipt);
        $this->assertArrayNotHasKey('results', $receipt);
        $this->assertArrayNotHasKey('officer_signature', $receipt);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function submissionPayload(string $clientSubmissionId, array $overrides = []): array
    {
        return array_merge([
            'client_submission_id' => $clientSubmissionId,
            'operator_name' => 'Jane Roe',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => 'CLIENT-CONTROLLED-VALUE',
            'defects' => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function submissionWithChecklist(Apparatus $apparatus, string $clientSubmissionId, array $overrides = []): array
    {
        return $this->submissionPayload($clientSubmissionId, array_merge([
            'checklist_version' => $this->canonicalChecklistVersion($apparatus),
            'compartments' => $this->canonicalCompartments($apparatus),
        ], $overrides));
    }

    private function canonicalChecklistVersion(Apparatus $apparatus): string
    {
        $version = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus)['checklist_version'];
        $this->assertIsString($version);

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function fireBoatSubmissionWithChecklist(Apparatus $apparatus, string $clientSubmissionId, string $inspectionDate): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $checklist = $resolution['checklist'];
        $this->assertIsArray($checklist);

        $fieldValues = array_map(static function (array $field) use ($inspectionDate): array {
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
        }, $checklist['fields']);

        $dueTasks = app(DailyCheckoutChecklistResolver::class)->dueTasksFor(
            $checklist,
            \Carbon\CarbonImmutable::parse($inspectionDate),
        );

        return $this->submissionWithChecklist($apparatus, $clientSubmissionId, [
            'field_values' => $fieldValues,
            'scheduled_tasks' => array_map(static fn (array $task): array => [
                'id' => $task['id'],
                'status' => 'Present',
                'notes' => null,
            ], $dueTasks),
        ]);
    }

    private function actingAsAdmin(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /** @return list<array{id: string, name: string, items: list<array{id: string, name: string, status: string, notes: null}>}> */
    private function canonicalCompartments(Apparatus $apparatus): array
    {
        $checklist = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus)['checklist'];
        $this->assertIsArray($checklist);

        return array_map(static function (array $compartment): array {
            $compartmentId = (string) $compartment['id'];

            return [
                'id' => $compartmentId,
                'name' => (string) ($compartment['name'] ?? $compartment['title']),
                'items' => array_map(static fn (array $item, int $index): array => [
                    'id' => (string) ($item['id'] ?? "{$compartmentId}-item-".($index + 1)),
                    'name' => (string) $item['name'],
                    'status' => 'Present',
                    'notes' => null,
                ], $compartment['items'], array_keys($compartment['items'])),
            ];
        }, $checklist['compartments']);
    }

    private function makeApparatus(string $dailyCheckoutRequirement, string $name = 'Engine 1'): Apparatus
    {
        $station = Station::firstOrCreate(
            ['station_number' => 1],
            [
                'name' => 'Station 1',
                'address' => '123 Main St',
                'is_active' => true,
            ]
        );

        $sequence = Apparatus::query()->count() + 1;

        return Apparatus::create([
            'station_id' => $station->id,
            'unit_id' => "E{$sequence}",
            'name' => $name,
            'type' => 'Engine',
            'vehicle_number' => "V{$sequence}",
            'designation' => "E{$sequence}",
            'slug' => "engine-{$sequence}",
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => $dailyCheckoutRequirement,
        ]);
    }

    private function makeFireBoat6(): Apparatus
    {
        $station = Station::firstOrCreate(
            ['station_number' => 6],
            [
                'name' => 'Station 6',
                'address' => '123 Marine Drive',
                'is_active' => true,
            ]
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
