<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationInventorySubmission;
use App\Models\StationSupplyRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StationWorkflowIntegrityReadModelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Employee $employee;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 12:00:00', 'America/New_York'));
        $this->employee = Employee::query()->create([
            'employee_id' => 'STATION-INTEGRITY-01',
            'name' => 'Station Integrity Actor',
            'rank' => 'Captain',
            'password' => 'not-used',
            'must_change_password' => false,
        ]);
        $this->user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_profile_id' => $this->employee->id,
        ]);
        $this->actingAsCanonicalUser($this->user);
        $this->station = Station::query()->create([
            'station_number' => 3,
            'name' => 'Station 3',
            'address' => '2300 Test Avenue',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_station_inspection_history_includes_every_current_day_review_state_without_leaking_identity(): void
    {
        $apparatus = Apparatus::query()->create([
            'station_id' => $this->station->id,
            'unit_id' => 'E3',
            'name' => 'Engine 3',
            'designation' => 'E3',
            'type' => 'Engine',
            'slug' => 'engine-3-integrity',
            'status' => 'In Service',
        ]);

        foreach (['pending_review', 'approved', 'rejected'] as $index => $status) {
            ApparatusInspection::query()->create([
                'apparatus_id' => $apparatus->id,
                'actor_user_id' => $this->user->id,
                'operator_name' => 'Protected Operator',
                'rank' => 'Captain',
                'shift' => 'A',
                'inspection_reference' => 'INS-E3-2026-'.($index + 1),
                'review_status' => $status,
                'completed_at' => CarbonImmutable::parse("2026-09-06 1{$index}:00:00", 'America/New_York')->utc(),
            ]);
        }

        $response = $this->getJson("/api/public/stations/{$this->station->id}/apparatus-inspections")
            ->assertOk()
            ->assertJsonCount(3, 'inspections')
            ->assertJsonPath('inspections.0.apparatus_name', 'E3')
            ->assertJsonStructure(['inspections' => [['id', 'inspection_reference', 'apparatus_name', 'completed_at', 'defect_count', 'review_status']]]);

        $this->assertEqualsCanonicalizing(
            ['pending_review', 'approved', 'rejected'],
            collect($response->json('inspections'))->pluck('review_status')->all(),
        );
        $response->assertJsonMissing(['operator_name' => 'Protected Operator']);
        $response->assertJsonMissing(['rank' => 'Captain']);
    }

    public function test_station_profile_read_models_keep_station_inspection_ppe_inventory_and_supply_records_canonical_and_redacted(): void
    {
        $stationInspection = StationInspection::query()->create([
            'station_id' => $this->station->id,
            'inspector_id' => $this->user->id,
            'inspection_date' => '2026-09-06',
            'inspection_type' => 'Saturday Station Inspection',
            'form_data' => ['checklist' => []],
            'overall_status' => 'pass',
            'inspector_signature' => 'protected-signature',
            'notes' => 'protected station note',
        ]);
        $ppe = $this->personnelRequest('equipment', $this->station);
        $ppe->items()->create([
            'item_code' => 'helmet',
            'item_name' => 'Helmet',
            'category' => 'equipment',
            'quantity' => 1,
            'reason' => 'damaged',
        ]);
        $this->personnelRequest('uniform', null);
        $submission = StationInventorySubmission::query()->create([
            'station_id' => $this->station->id,
            'employee_name' => 'Protected Inventory Actor',
            'shift' => 'A',
            'items' => [['item_id' => 'paper_towels', 'quantity' => 2]],
            'notes' => 'private inventory note',
            'pdf_path' => 'inventory-submissions/private.pdf',
            'created_by' => $this->user->id,
            'actor_employee_id' => $this->employee->id,
            'submitted_at' => now(),
        ]);
        $supply = StationSupplyRequest::query()->create([
            'station_id' => $this->station->id,
            'actor_user_id' => $this->user->id,
            'actor_employee_id' => $this->employee->id,
            'request_text' => 'Restock paper towels.',
            'status' => 'open',
            'created_by_name' => 'Protected Supply Actor',
            'created_by_shift' => 'A',
            'admin_notes' => 'private admin note',
        ]);

        $this->getJson("/api/public/stations/{$this->station->id}/inspections")
            ->assertOk()
            ->assertJsonPath('inspections.0.id', $stationInspection->id)
            ->assertJsonPath('inspections.0.inspection_date', '2026-09-06')
            ->assertJsonPath('inspections.0.review_status', 'pending_review')
            ->assertJsonMissing(['inspector_signature' => 'protected-signature'])
            ->assertJsonMissing(['notes' => 'protected station note']);

        $this->getJson("/api/public/stations/{$this->station->id}/personnel-equipment-requests")
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $ppe->id)
            ->assertJsonPath('requests.0.request_number', $ppe->request_number)
            ->assertJsonPath('requests.0.items.0.item_name', 'Helmet')
            ->assertJsonMissing(['beneficiary_name' => 'Protected Beneficiary'])
            ->assertJsonMissing(['requester_name' => 'Protected Requester']);

        $this->getJson("/api/public/stations/{$this->station->id}/inventory")
            ->assertOk()
            ->assertJsonPath('submissions.0.id', $submission->id)
            ->assertJsonPath('submissions.0.item_count', 1)
            ->assertJsonPath('supply_requests.0.id', $supply->id)
            ->assertJsonPath('supply_requests.0.request_text', 'Restock paper towels.')
            ->assertJsonMissing(['employee_name' => 'Protected Inventory Actor'])
            ->assertJsonMissing(['created_by_name' => 'Protected Supply Actor'])
            ->assertJsonMissing(['admin_notes' => 'private admin note'])
            ->assertJsonMissing(['pdf_path' => 'inventory-submissions/private.pdf']);

        $activity = $this->getJson("/api/public/stations/{$this->station->id}/activity")
            ->assertOk()
            ->json('activity');
        $this->assertContains('personnel_request', collect($activity)->pluck('type')->all());
        $this->assertContains('inventory_submission', collect($activity)->pluck('type')->all());
        $this->assertContains('supply_request', collect($activity)->pluck('type')->all());
    }

    private function personnelRequest(string $type, ?Station $station): PersonnelRequest
    {
        return PersonnelRequest::query()->create([
            'public_id' => (string) Str::ulid(),
            'request_number' => 'PR-'.Str::upper(Str::random(8)),
            'type' => $type,
            'beneficiary_employee_id' => $this->employee->id,
            'requester_employee_id' => $this->employee->id,
            'originating_station_id' => $station?->id,
            'beneficiary_name' => 'Protected Beneficiary',
            'beneficiary_employee_number' => 'PROTECTED-01',
            'requester_name' => 'Protected Requester',
            'requester_employee_number' => 'PROTECTED-01',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
