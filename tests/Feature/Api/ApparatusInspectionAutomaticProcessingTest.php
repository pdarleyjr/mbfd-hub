<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\Employee;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\InspectionMeterBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ApparatusInspectionAutomaticProcessingTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private const NARRATIVE = "Scratch below rear step.\nLocated beside the left rear light.";

    private User $member;

    private Apparatus $apparatus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        Queue::fake();
        Storage::fake('public', ['root' => storage_path('framework/testing/disks/automatic-inspection-'.getmypid())]);
        config()->set('google_sheets.apparatus_sync_enabled', false);
        $this->member = $this->actingAsCanonicalFixture('AUTO-001', 'Canonical Inspector', 'Firefighter');
        $this->apparatus = Apparatus::query()->create([
            'unit_id' => 'E2', 'designation' => 'E2', 'name' => 'Engine 2', 'type' => 'Engine',
            'vehicle_number' => 'V-202', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine2',
            'current_engine_hours' => 400, 'current_miles' => 1000,
        ]);
    }

    public function test_actual_engine2_http_submission_is_accepted_and_persists_exact_paper_evidence_once(): void
    {
        $payload = $this->payload();
        $response = $this->postJson($this->url(), $payload)->assertCreated()
            ->assertJsonPath('processing_status', 'accepted')->assertJsonPath('review_status', 'approved');
        $inspection = ApparatusInspection::sole();
        $this->assertSame($inspection->id, $response->json('id'));
        $this->assertSame('401.2', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame(1020, $this->apparatus->fresh()->current_miles);
        $this->assertSame('401.2', $inspection->engine_hours);
        $this->assertSame(1020, $inspection->miles);
        $this->assertSame($payload['compartments'], $inspection->results);
        $evidence = array_column($inspection->checklist_evidence, null, 'id');
        $this->assertSame(['id' => 'scba_5', 'name' => 'SCBA #5', 'input_type' => 'text', 'value' => '0012-A'], $evidence['scba_5']);
        $this->assertSame(self::NARRATIVE, $evidence['new_damage_description']['value']);
        $this->assertSame('Rear step, left side', $evidence['new_damage_location']['value']);
        $this->assertSame('Spare nozzle moved to reserve unit.', $evidence['changeover_equipment_removed']['value']);
        $this->assertSame(array_column($payload['field_values'], 'value'), array_column($inspection->checklist_evidence, 'value'));
        $this->assertNull($inspection->pending_effects);
        $this->assertNull(ApparatusInspectionReviewEvent::sole()->changed_by_user_id);
        Storage::disk('public')->assertExists($inspection->officer_signature);
        $paths = Storage::disk('public')->allFiles();

        $this->postJson($this->url(), $payload)->assertOk()->assertJsonPath('id', $inspection->id);
        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame($paths, Storage::disk('public')->allFiles());
        $changed = $payload;
        $this->setField($changed, 'scba_5', '0013-A');
        $this->postJson($this->url(), $changed)->assertConflict()->assertJsonPath('code', 'DAILY_CHECKOUT_SUBMISSION_REPLAY_CONFLICT');
        $this->assertSame('0012-A', array_column($inspection->fresh()->checklist_evidence, 'value', 'id')['scba_5']);
    }

    #[DataProvider('ordinaryTemplates')]
    public function test_blank_optional_meters_are_accepted_without_changing_previous_readings(string $template, string $designation, string $type): void
    {
        $this->apparatus->update(['unit_id' => $designation, 'designation' => $designation, 'type' => $type, 'daily_checkout_template' => $template]);
        $payload = $this->payload();
        $payload['engine_hours'] = null;
        $payload['miles'] = null;
        $this->setField($payload, 'mileage', null);

        $this->postJson($this->url(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $inspection = ApparatusInspection::sole();
        $this->assertNull($inspection->engine_hours);
        $this->assertNull($inspection->miles);
        $evidence = array_column($inspection->checklist_evidence, null, 'id');
        $this->assertArrayHasKey('mileage', $evidence);
        $this->assertNull($evidence['mileage']['value']);
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame(1000, $this->apparatus->fresh()->current_miles);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);

        $this->postJson($this->url(), $payload)->assertOk()->assertJsonPath('id', $inspection->id);
        $this->assertDatabaseCount('apparatus_inspections', 1);
    }

    public static function ordinaryTemplates(): array
    {
        return [
            ['engine', 'E1', 'Engine'], ['engine2', 'E2', 'Engine'],
            ['ladder1', 'L1', 'Ladder'], ['ladder3', 'L3', 'Ladder'],
            ['rescue', 'R1', 'Rescue'],
        ];
    }

    #[DataProvider('disputedMeters')]
    public function test_rollback_or_stale_baseline_quarantines_only_the_disputed_meter(string $reason): void
    {
        $payload = $this->payload();
        if ($reason === 'rollback') {
            $payload['engine_hours'] = 399;
        } else {
            $this->apparatus->update(['current_engine_hours' => 400.5]);
        }
        $this->postJson($this->url(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted_with_exception');
        $exception = ApparatusInspectionException::sole();
        $this->assertSame('engine_hours', $exception->field);
        $this->assertSame($reason, $exception->reason);
        $this->assertSame('400.0', $exception->baseline_value);
        $this->assertSame($reason === 'rollback' ? '400.0' : '400.5', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame(1020, $this->apparatus->fresh()->current_miles);
        $this->assertSame((float) $payload['engine_hours'], (float) ApparatusInspection::sole()->engine_hours);
        $this->assertSame('approved', ApparatusInspection::sole()->review_status);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
    }

    public static function disputedMeters(): array
    {
        return [['rollback'], ['stale_baseline']];
    }

    public function test_spoofed_member_and_vehicle_labels_are_overwritten_by_canonical_identity_and_foreign_retry_is_denied(): void
    {
        $other = Employee::query()->create(['employee_id' => 'AUTO-OTHER', 'name' => 'Other member', 'rank' => 'Captain', 'password' => 'unused']);
        $payload = $this->payload();
        $payload['employee_id'] = $other->id;
        $payload['operator_name'] = 'Forged name';
        $payload['rank'] = 'Forged rank';
        $payload['actor_user_id'] = 999999;
        $payload['unit_number'] = 'Forged vehicle';
        $payload['vehicle_number'] = 'Forged vehicle';
        $this->postJson($this->url(), $payload)->assertCreated();
        $inspection = ApparatusInspection::sole();
        $this->assertSame($this->member->id, $inspection->actor_user_id);
        $this->assertSame($this->member->employee_profile_id, $inspection->employee_id);
        $this->assertSame('Canonical Inspector', $inspection->operator_name);
        $this->assertSame('Firefighter', $inspection->rank);
        $this->assertSame('V-202', $inspection->unit_number);
        $this->assertSame('V-202', $inspection->vehicle_number);
        $this->actingAsCanonicalFixture('AUTO-002', 'Second Inspector');
        $this->postJson($this->url(), $payload)->assertConflict()->assertJsonPath('code', 'OFFLINE_QUEUE_OWNER_MISMATCH');
        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 1);
    }

    #[DataProvider('invalidSubmissions')]
    public function test_invalid_paper_contract_is_rejected_before_persisting_any_evidence(string $change, string $error): void
    {
        $payload = $this->payload();
        switch ($change) {
            case 'duplicate field': $payload['field_values'][] = $payload['field_values'][0];
                break;
            case 'unknown field': $payload['field_values'][0]['id'] = 'unissued';
                break;
            case 'omitted field': array_pop($payload['field_values']);
                break;
            case 'text wrong type': $this->setField($payload, 'scba_5', 12);
                break;
            case 'text too long': $this->setField($payload, 'scba_5', str_repeat('x', 2001));
                break;
            case 'numeric string': $this->setField($payload, 'mileage', '1020');
                break;
            case 'mileage mismatch': $this->setField($payload, 'mileage', 1021);
                break;
            case 'blank mileage mismatch': $this->setField($payload, 'mileage', null);
                break;
            case 'required pressure missing': $this->setField($payload, 'air_pressure_front', null);
                break;
            case 'negative mileage': $payload['miles'] = -1;
                $this->setField($payload, 'mileage', -1);
                break;
            case 'paper mileage overflow': $payload['miles'] = 1000000000;
                $this->setField($payload, 'mileage', 1000000000);
                break;
            case 'negative hours': $payload['engine_hours'] = -1;
                break;
            case 'invalid hours precision': $payload['engine_hours'] = 401.23;
                break;
            case 'missing shift': unset($payload['shift']);
                break;
            case 'vehicle mismatch': $this->setField($payload, 'vehicle_num', 'V-OTHER');
                break;
            case 'fractional mileage': $payload['miles'] = 1020.5;
                $this->setField($payload, 'mileage', 1020.5);
                break;
            case 'unobserved': $payload['compartments'][0]['items'][0]['observed'] = false;
                break;
            case 'omitted observation': unset($payload['compartments'][0]['items'][0]['observed']);
                break;
            case 'unissued item id': $payload['compartments'][0]['items'][0]['id'] = 'unissued';
                break;
            case 'missing signature': unset($payload['officer_signature']);
                break;
            case 'invalid signature': $payload['officer_signature'] = 'not an image';
                break;
        }
        $this->postJson($this->url(), $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('apparatus_inspections', 0);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame(1000, $this->apparatus->fresh()->current_miles);
    }

    public static function invalidSubmissions(): array
    {
        return [
            ['duplicate field', 'field_values'], ['unknown field', 'field_values'], ['omitted field', 'field_values'],
            ['text wrong type', 'field_values'], ['text too long', 'field_values'], ['numeric string', 'field_values'],
            ['mileage mismatch', 'field_values'], ['vehicle mismatch', 'field_values'], ['fractional mileage', 'miles'],
            ['blank mileage mismatch', 'field_values'], ['required pressure missing', 'field_values'],
            ['negative mileage', 'miles'], ['negative hours', 'engine_hours'], ['invalid hours precision', 'engine_hours'],
            ['paper mileage overflow', 'field_values'],
            ['missing shift', 'shift'],
            ['unobserved', 'compartments'], ['omitted observation', 'compartments'], ['unissued item id', 'compartments'],
            ['missing signature', 'officer_signature'], ['invalid signature', 'officer_signature'],
        ];
    }

    public function test_actual_ladder_template_typed_item_requires_value_and_preserves_leading_zero_identifier(): void
    {
        $this->apparatus->update(['unit_id' => 'L1', 'designation' => 'L1', 'name' => 'Ladder 1', 'type' => 'Ladder', 'daily_checkout_template' => 'ladder1']);
        $payload = $this->payload();
        $index = array_search('scba_radio', array_column($payload['compartments'], 'id'), true);
        $this->assertNotFalse($index);
        $this->setItemValue($payload, 'b_comp_4', 'comp_4-item-1', '0007-CO');
        $this->setItemValue($payload, 'b_comp_4', 'comp_4-item-2', '0008-H2S');
        $this->setItemValue($payload, 'front_cab', 'front_cab-item-26', 'Y');
        $invalid = $payload;
        unset($invalid['compartments'][$index]['items'][0]['value']);
        $this->postJson($this->url(), $invalid)->assertUnprocessable()->assertJsonValidationErrors('compartments');
        $this->postJson($this->url(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $inspection = ApparatusInspection::sole();
        $this->assertSame('0012-A', $inspection->results[$index]['items'][0]['value']);
        $groups = array_column($inspection->results, null, 'id');
        $medical = array_column($groups['b_comp_4']['items'], 'value', 'id');
        $this->assertSame('0007-CO', $medical['comp_4-item-1']);
        $this->assertSame('0008-H2S', $medical['comp_4-item-2']);
        $cab = array_column($groups['front_cab']['items'], 'value', 'id');
        $this->assertSame('Y', $cab['front_cab-item-26']);
        $saws = array_column($groups['b_comp_3']['items'], 'name', 'id');
        $this->assertSame('SawZall 20Volt / 28Volt', $saws['comp_3-item-2']);
        $this->assertArrayNotHasKey('comp_3-item-3', $saws);
        $this->assertNotContains('plymovent_trans', array_column($inspection->checklist_evidence, 'id'));
    }

    public function test_ladder3_paper_medical_identifiers_and_reported_crew_persist_without_changing_actor(): void
    {
        $this->apparatus->update(['unit_id' => 'L3', 'designation' => 'L3', 'name' => 'Ladder 3', 'type' => 'Ladder', 'daily_checkout_template' => 'ladder3']);
        $payload = $this->payload();
        $this->setItemValue($payload, 'b_comp_3', 'co_3500pak_num', '0009-CO');
        $this->setItemValue($payload, 'b_comp_3', 'h2s_3500pak_num', '0010-H2S');
        $this->setField($payload, 'personnel_oic', 'Reported crew officer');
        $this->setField($payload, 'personnel_de', 'Reported driver');
        $this->setField($payload, 'personnel_ff1', 'Reported crew one');
        $this->setField($payload, 'personnel_ff2', 'Reported crew two');
        $invalid = $payload;
        $this->setItemValue($invalid, 'b_comp_3', 'co_3500pak_num', 9);
        $this->postJson($this->url(), $invalid)->assertUnprocessable()->assertJsonValidationErrors('compartments');
        $this->assertDatabaseCount('apparatus_inspections', 0);
        $this->postJson($this->url(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $inspection = ApparatusInspection::sole();
        $groups = array_column($inspection->results, null, 'id');
        $medical = array_column($groups['b_comp_3']['items'], 'value', 'id');
        $this->assertSame('0009-CO', $medical['co_3500pak_num']);
        $this->assertSame('0010-H2S', $medical['h2s_3500pak_num']);
        $tools = array_column($groups['a_comp_3']['items'], null, 'id');
        $this->assertSame('Band Saw', $tools['l3-a-comp-3-band-saw']['name']);
        $this->assertTrue($tools['l3-a-comp-3-band-saw']['observed']);
        $fields = array_column($inspection->checklist_evidence, 'value', 'id');
        $this->assertSame('Reported crew officer', $fields['personnel_oic']);
        $this->assertSame('Reported driver', $fields['personnel_de']);
        $this->assertSame('Reported crew one', $fields['personnel_ff1']);
        $this->assertSame('Reported crew two', $fields['personnel_ff2']);
        $this->assertArrayNotHasKey('co_3500pak_num', $fields);
        $this->assertArrayNotHasKey('h2s_3500pak_num', $fields);
        $this->assertSame($this->member->id, $inspection->actor_user_id);
        $this->assertSame('Canonical Inspector', $inspection->operator_name);
        $this->assertSame('V-202', $inspection->vehicle_number);
    }

    public function test_defect_and_present_correction_photos_persist_as_paths_without_resolving_the_existing_defect(): void
    {
        [$payload, $existing] = $this->photoPayload();
        $this->postJson($this->url(), $payload)->assertCreated()->assertJsonPath('processing_status', 'accepted');
        $inspection = ApparatusInspection::sole();
        $observations = ApparatusDefectObservation::query()->orderBy('id')->get();
        $this->assertCount(2, $observations);
        $this->assertSame(['first_reported', 'appears_corrected'], $observations->pluck('observation')->all());
        $this->assertSame(['Missing', 'Present'], $observations->pluck('reported_status')->all());
        $this->assertSame([$this->member->id, $this->member->id], $observations->pluck('actor_user_id')->all());
        $this->assertFalse($existing->fresh()->resolved);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $photos = array_column(array_slice($inspection->results[0]['items'], 0, 2), 'photo_path');
        $this->assertCount(2, $photos);
        $this->assertSame($photos, $observations->pluck('photo_path')->all());
        Storage::disk('public')->assertExists([$inspection->officer_signature, ...$photos]);
        $this->assertCount(3, Storage::disk('public')->allFiles());
        $event = ApparatusInspectionReviewEvent::sole();
        $this->assertSame($photos[0], $event->metadata['submitted_effects']['defects'][0]['photo_path']);
        $persisted = json_encode([$inspection->getAttributes(), $event->getAttributes(), $observations->toArray(), ApparatusDefect::all()->toArray()], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('data:image', $persisted);
        $this->assertStringNotContainsString('iVBORw0KGgo', $persisted);
        foreach (array_slice($inspection->results[0]['items'], 0, 2) as $item) {
            $this->assertArrayNotHasKey('photo', $item);
        }
    }

    #[DataProvider('routineFindingStatuses')]
    public function test_unclassified_routine_findings_are_accepted_and_keep_one_immutable_defect_history(string $status): void
    {
        $first = $this->payload();
        $first['compartments'][0]['items'][0]['status'] = $status;
        $first['compartments'][0]['items'][0]['notes'] = 'Observed during checkout.';
        $first['defects'][] = [
            'compartment' => $first['compartments'][0]['name'],
            'item' => $first['compartments'][0]['items'][0]['name'],
            'status' => $status,
            'notes' => $first['compartments'][0]['items'][0]['notes'],
        ];
        $this->postJson($this->url(), $first)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $defect = ApparatusDefect::sole();
        $this->assertSame(strtolower($status), $defect->issue_type);
        $this->assertSame('unclassified', $defect->operational_impact);
        $this->assertFalse($defect->resolved);

        $second = $this->payload();
        $second['compartments'][0]['items'][0]['status'] = $status;
        $second['compartments'][0]['items'][0]['notes'] = 'Confirmed on the next checkout.';
        $second['defects'][] = [
            'compartment' => $second['compartments'][0]['name'],
            'item' => $second['compartments'][0]['items'][0]['name'],
            'status' => $status,
            'notes' => $second['compartments'][0]['items'][0]['notes'],
        ];
        $this->postJson($this->url(), $second)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $present = $this->payload();
        $present['compartments'][0]['items'][0]['notes'] = 'Item appears corrected; disposition remains authorized work.';
        $this->postJson($this->url(), $present)->assertCreated()->assertJsonPath('processing_status', 'accepted');

        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
        $this->assertFalse($defect->fresh()->resolved);
        $this->assertSame(
            ['first_reported', 'confirmed_again', 'appears_corrected'],
            ApparatusDefectObservation::query()->where('apparatus_defect_id', $defect->id)->orderBy('id')->pluck('observation')->all(),
        );
    }

    public static function routineFindingStatuses(): array
    {
        return [['Missing'], ['Damaged']];
    }

    public function test_transaction_failure_rolls_back_meters_evidence_and_every_uploaded_image(): void
    {
        [$payload, $existing] = $this->photoPayload();
        $reachedProcessing = false;
        ApparatusInspectionReviewEvent::creating(function () use (&$reachedProcessing): never {
            $reachedProcessing = true;
            $this->assertCount(3, Storage::disk('public')->allFiles());
            $this->assertDatabaseCount('apparatus_defect_observations', 2);
            $this->assertSame('401.2', $this->apparatus->fresh()->current_engine_hours);
            throw new RuntimeException('Forced processing failure after image and database writes.');
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->url(), $payload);
            $this->fail('The forced transaction failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced processing failure after image and database writes.', $exception->getMessage());
        }
        $this->assertTrue($reachedProcessing);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('apparatus_inspections', 0);
        $this->assertDatabaseCount('apparatus_inspection_review_events', 0);
        $this->assertDatabaseCount('apparatus_inspection_exceptions', 0);
        $this->assertDatabaseCount('apparatus_defect_observations', 0);
        $this->assertDatabaseCount('apparatus_defects', 1);
        $this->assertFalse($existing->fresh()->resolved);
        $this->assertSame('400.0', $this->apparatus->fresh()->current_engine_hours);
        $this->assertSame(1000, $this->apparatus->fresh()->current_miles);
        Queue::assertNothingPushed();
    }

    private function payload(): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($this->apparatus->fresh());
        $this->assertTrue($resolution['usable']);
        $checklist = $resolution['checklist'];
        $payload = [
            'processing_version' => 1, 'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => $resolution['checklist_version'],
            'meter_baseline_token' => app(InspectionMeterBaseline::class)->issue($this->apparatus->fresh()),
            'operator_name' => 'Canonical Inspector', 'rank' => 'Firefighter', 'shift' => 'A',
            'engine_hours' => 401.2, 'miles' => 1020, 'field_values' => [], 'compartments' => [],
            'defects' => [], 'officer_signature' => self::PNG,
        ];
        foreach ($checklist['fields'] ?? $checklist['officerChecklist'] as $field) {
            $value = match ($field['id']) {
                'mileage' => 1020,
                'vehicle_num' => $this->apparatus->vehicle_number,
                'new_damage_description' => self::NARRATIVE,
                'new_damage_location' => 'Rear step, left side',
                'changeover_equipment_removed' => 'Spare nozzle moved to reserve unit.',
                default => $this->value($field['inputType']),
            };
            $payload['field_values'][] = ['id' => $field['id'], 'value' => $value];
        }
        foreach ($checklist['compartments'] as $compartment) {
            $items = [];
            foreach ($compartment['items'] as $index => $definition) {
                $item = ['id' => $definition['id'] ?? $compartment['id'].'-item-'.($index + 1), 'name' => $definition['name'], 'status' => 'Present', 'observed' => true];
                if (($definition['inputType'] ?? 'checkbox') !== 'checkbox') {
                    $item['value'] = $this->value($definition['inputType']);
                }
                $items[] = $item;
            }
            $payload['compartments'][] = ['id' => $compartment['id'], 'name' => $compartment['name'] ?? $compartment['title'], 'items' => $items];
        }

        return $payload;
    }

    private function photoPayload(): array
    {
        $payload = $this->payload();
        $compartment = &$payload['compartments'][0];
        $compartment['items'][0]['status'] = 'Missing';
        $compartment['items'][0]['notes'] = 'Not in its assigned position.';
        $compartment['items'][0]['photo'] = self::PNG;
        $compartment['items'][1]['notes'] = 'Located and appears corrected; needs authorized review.';
        $compartment['items'][1]['photo'] = self::PNG;
        $payload['defects'][] = [
            'compartment' => $compartment['name'], 'item' => $compartment['items'][0]['name'],
            'status' => 'Missing', 'notes' => $compartment['items'][0]['notes'], 'photo' => self::PNG,
        ];
        $existing = ApparatusDefect::recordDefect($this->apparatus->id, $compartment['name'], $compartment['items'][1]['name'], 'Damaged', 'Earlier report.', null, null);

        return [$payload, $existing];
    }

    private function setField(array &$payload, string $id, mixed $value): void
    {
        $index = array_search($id, array_column($payload['field_values'], 'id'), true);
        $this->assertNotFalse($index);
        $payload['field_values'][$index]['value'] = $value;
    }

    private function setItemValue(array &$payload, string $groupId, string $itemId, mixed $value): void
    {
        $group = array_search($groupId, array_column($payload['compartments'], 'id'), true);
        $this->assertNotFalse($group);
        $item = array_search($itemId, array_column($payload['compartments'][$group]['items'], 'id'), true);
        $this->assertNotFalse($item);
        $payload['compartments'][$group]['items'][$item]['value'] = $value;
    }

    private function value(string $type): string|int|bool
    {
        return match ($type) {
            'number' => 110, 'percentage' => 75, 'checkbox' => true, 'date' => '2026-09-21', default => '0012-A',
        };
    }

    private function url(): string
    {
        return "/api/public/apparatuses/{$this->apparatus->id}/inspections";
    }
}
