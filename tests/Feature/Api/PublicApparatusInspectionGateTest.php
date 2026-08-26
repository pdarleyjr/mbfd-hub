<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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

/**
 * H-01: an unauthenticated public inspection submission must not be able to
 * directly drive an apparatus Out of Service. Critical defects are recorded as
 * a pending-review submission; only an authenticated/authorized approval mutates
 * operational status.
 */
class PublicApparatusInspectionGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeApparatus(string $status = 'In Service'): Apparatus
    {
        $station = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main St',
            'is_active' => true,
        ]);

        return Apparatus::create([
            'station_id' => $station->id,
            'unit_id' => 'E1-'.uniqid(),
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => 'V100',
            'designation' => 'E1',
            'slug' => 'engine-1-'.uniqid(),
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => $status,
            'daily_checkout_requirement' => 'required',
        ]);
    }

    private function criticalDefectPayload(Apparatus $apparatus): array
    {
        $compartments = $this->canonicalCompartments($apparatus);
        $compartments[0]['items'][0]['status'] = 'Missing';

        return [
            'client_submission_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'operator_name' => 'John Doe',
            'rank' => 'Lieutenant',
            'shift' => 'A',
            'unit_number' => 'E1',
            'compartments' => $compartments,
            'defects' => [
                [
                    'compartment' => $compartments[0]['name'],
                    'item' => $compartments[0]['items'][0]['name'],
                    'status' => 'Missing',
                    'notes' => 'Not on board',
                ],
            ],
        ];
    }

    public function test_public_submission_with_critical_defect_does_not_set_out_of_service(): void
    {
        $apparatus = $this->makeApparatus('In Service');

        $response = $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->criticalDefectPayload($apparatus)
        );

        $response->assertStatus(201);

        // Operational status must be unchanged by an anonymous submission.
        $this->assertSame('In Service', $apparatus->fresh()->status);

        // The submission is recorded but held for review.
        $inspection = ApparatusInspection::latest('id')->first();
        $this->assertNotNull($inspection);
        $this->assertSame('pending_review', $inspection->review_status);
        $this->assertSame($inspection->id, ApparatusDefect::sole()->apparatus_inspection_id);
    }

    public function test_public_submission_without_critical_defect_is_approved_and_status_unchanged(): void
    {
        $apparatus = $this->makeApparatus('In Service');

        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", [
            'client_submission_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'operator_name' => 'Jane Roe',
            'rank' => 'Firefighter',
            'shift' => 'B',
            'compartments' => $this->canonicalCompartments($apparatus),
            'defects' => [],
        ]);

        $response->assertStatus(201);

        $this->assertSame('In Service', $apparatus->fresh()->status);
        $inspection = ApparatusInspection::latest('id')->first();
        $this->assertSame('approved', $inspection->review_status);
    }

    public function test_inspection_uses_authoritative_vehicle_identity_and_preserves_meter_history(): void
    {
        $apparatus = $this->makeApparatus('In Service');
        $apparatus->update([
            'current_engine_hours' => 100.0,
            'current_miles' => 10_000,
        ]);

        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", [
            'client_submission_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'operator_name' => 'Jane Roe',
            'rank' => 'Firefighter',
            'shift' => 'B',
            'unit_number' => 'ATTACKER-CONTROLLED-VALUE',
            'engine_hours' => 101.5,
            'miles' => 10_025,
            'compartments' => $this->canonicalCompartments($apparatus),
            'defects' => [],
        ]);

        $response->assertCreated();

        $inspection = ApparatusInspection::sole();
        $this->assertSame($apparatus->vehicle_number, $inspection->unit_number);
        $this->assertSame('101.5', $inspection->engine_hours);
        $this->assertSame(10_025, $inspection->miles);
        $this->assertSame('101.5', $apparatus->fresh()->current_engine_hours);
        $this->assertSame(10_025, $apparatus->fresh()->current_miles);
    }

    public function test_unauthenticated_user_cannot_approve_pending_inspection(): void
    {
        $apparatus = $this->makeApparatus('In Service');

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->criticalDefectPayload($apparatus)
        )->assertStatus(201);

        $inspection = ApparatusInspection::latest('id')->first();

        $response = $this->postJson("/api/apparatus-inspections/{$inspection->id}/approve");

        $response->assertStatus(401);
        $this->assertSame('In Service', $apparatus->fresh()->status);
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
    }

    public function test_authorized_user_can_approve_pending_inspection_and_set_out_of_service(): void
    {
        $apparatus = $this->makeApparatus('In Service');

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->criticalDefectPayload($apparatus)
        )->assertStatus(201);

        $inspection = ApparatusInspection::latest('id')->first();

        $this->actingAsAdmin();

        $response = $this->postJson("/api/apparatus-inspections/{$inspection->id}/approve");

        $response->assertStatus(200);
        $this->assertSame('Out of Service', $apparatus->fresh()->status);
        $this->assertSame('approved', $inspection->fresh()->review_status);
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
}
