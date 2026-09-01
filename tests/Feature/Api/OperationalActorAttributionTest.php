<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Station;
use App\Models\StationInventoryItem;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class OperationalActorAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.private', 'local'));
    }

    public function test_daily_checkout_ignores_every_client_actor_field_and_persists_the_canonical_actor(): void
    {
        [$actor, $actorEmployee] = $this->canonicalMember('E01-A', 'Canonical Actor');
        [$otherUser, $otherEmployee] = $this->canonicalMember('E01-B', 'Payload Impostor');
        $apparatus = $this->apparatus();
        $this->actingAsCanonicalUser($actor);

        $payload = $this->dailyPayload($apparatus);
        $payload['operator_name'] = $otherEmployee->name;
        $payload['rank'] = $otherEmployee->rank;
        $payload['employee_id'] = $otherEmployee->id;
        $payload['user_id'] = $otherUser->id;
        $payload['actor_id'] = $otherUser->id;
        $payload['submitted_by'] = $otherUser->id;

        $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $payload)
            ->assertCreated();

        $this->assertDatabaseHas('apparatus_inspections', [
            'actor_user_id' => $actor->id,
            'employee_id' => $actorEmployee->id,
            'operator_name' => $actorEmployee->name,
            'rank' => $actorEmployee->rank,
        ]);
        $this->assertDatabaseMissing('apparatus_inspections', [
            'actor_user_id' => $otherUser->id,
        ]);
    }

    public function test_daily_checkout_fails_closed_for_an_unlinked_user(): void
    {
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_profile_id' => null,
        ]);
        $apparatus = $this->apparatus();
        $this->actingAsCanonicalUser($user);

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->dailyPayload($apparatus),
        )->assertForbidden();

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_disabled_user_cannot_submit_operational_work(): void
    {
        [$actor] = $this->canonicalMember('E01-DISABLED', 'Disabled Actor');
        $apparatus = $this->apparatus();
        $this->actingAsCanonicalUser($actor);
        $actor->forceFill(['account_status' => AccountStatus::Disabled])->save();

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->dailyPayload($apparatus),
        )->assertUnauthorized();

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_security_version_change_invalidates_offline_replay_authentication(): void
    {
        [$actor] = $this->canonicalMember('E01-STALE', 'Stale Session Actor');
        $apparatus = $this->apparatus();
        $this->actingAsCanonicalUser($actor);
        $actor->increment('security_version');

        $this->postJson(
            "/api/public/apparatuses/{$apparatus->id}/inspections",
            $this->dailyPayload($apparatus),
        )->assertUnauthorized();

        $this->assertDatabaseCount('apparatus_inspections', 0);
    }

    public function test_member_context_exposes_only_non_secret_offline_affinity_metadata(): void
    {
        [$actor] = $this->canonicalMember('E01-CONTEXT', 'Context Actor');
        $this->actingAsCanonicalUser($actor);

        $this->getJson('/api/me/context')
            ->assertOk()
            ->assertJsonPath('identity.user_id', $actor->id)
            ->assertJsonPath('offline.security_version', 1)
            ->assertJsonMissingPath('offline.session_id')
            ->assertJsonMissingPath('offline.token');
    }

    public function test_inventory_pin_is_station_authorization_not_human_identity(): void
    {
        [$actor, $actorEmployee] = $this->canonicalMember('E01-C', 'Inventory Actor');
        [, $otherEmployee] = $this->canonicalMember('E01-D', 'Forged Inventory Actor');
        $station = $this->station();
        $station->forceFill(['inventory_pin_hash' => Hash::make('9191')])->save();
        $category = InventoryCategory::query()->create(['name' => 'E01 Supplies']);
        $catalogItem = InventoryItem::query()->create([
            'category_id' => $category->id,
            'name' => 'E01 Gloves',
            'par_quantity' => 10,
        ]);
        $stationItem = StationInventoryItem::query()->create([
            'station_id' => $station->id,
            'inventory_item_id' => $catalogItem->id,
            'on_hand' => 8,
        ]);
        $this->actingAsCanonicalUser($actor);

        $verified = $this->postJson('/api/v2/station-inventory/verify-pin', [
            'station_id' => $station->id,
            'pin' => '9191',
            'actor_name' => $otherEmployee->name,
            'actor_shift' => 'B-Day',
        ])->assertOk();

        $this->assertStringNotContainsString('actor_name', (string) $verified->json('inventory_url'));
        $this->assertDatabaseHas('station_inventory_audits', [
            'station_id' => $station->id,
            'actor_user_id' => $actor->id,
            'actor_employee_id' => $actorEmployee->id,
            'actor_name' => $actorEmployee->name,
            'action' => 'pin_verified',
        ]);

        $parts = parse_url((string) $verified->json('inventory_url'));
        $updateUrl = $parts['path'].'/item/'.$stationItem->id.'?'.$parts['query'];
        $this->putJson($updateUrl, [
            'on_hand' => 3,
            'actor_name' => $otherEmployee->name,
            'actor_shift' => 'Forged Shift',
        ])->assertOk();

        $this->assertDatabaseHas('station_inventory_audits', [
            'station_id' => $station->id,
            'actor_user_id' => $actor->id,
            'actor_employee_id' => $actorEmployee->id,
            'actor_name' => $actorEmployee->name,
            'action' => 'count_updated',
        ]);
    }

    public function test_legacy_inventory_submission_uses_canonical_actor_and_treats_shift_as_context(): void
    {
        [$actor, $actorEmployee] = $this->canonicalMember('E01-E', 'Inventory Submission Actor');
        $station = $this->station();
        $this->actingAsCanonicalUser($actor);

        $this->postJson('/api/station-inventory-submissions', [
            'station_id' => $station->id,
            'employee_name' => 'Forged Browser Name',
            'shift' => 'C',
            'items' => [[
                'category_id' => 'garbage_paper',
                'item_id' => 'paper_towels',
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('station_inventory_submissions', [
            'created_by' => $actor->id,
            'actor_employee_id' => $actorEmployee->id,
            'employee_name' => $actorEmployee->name,
            'shift' => 'C',
        ]);
    }

    public function test_station_inspection_replay_is_actor_bound_and_idempotent(): void
    {
        [$actor] = $this->canonicalMember('E01-STATION-A', 'Station Inspector');
        [$otherActor] = $this->canonicalMember('E01-STATION-B', 'Other Inspector');
        $station = $this->station();
        $this->actingAsCanonicalUser($actor);
        $payload = [
            'client_submission_id' => 'e0100000-0000-4000-8000-000000000020',
            'station' => "Station {$station->station_number}",
            'inspection_type' => 'daily',
            'date' => '2026-09-01',
            'checklist' => [[
                'id' => 'bay-floor',
                'label' => 'Bay floor clear',
                'category' => 'Apparatus Bay',
                'status' => 'pass',
            ]],
        ];

        $this->postJson('/api/public/station_inspection', $payload)
            ->assertCreated()
            ->assertJsonPath('inspector_id', $actor->id);
        $this->postJson('/api/public/station_inspection', $payload)
            ->assertOk()
            ->assertJsonPath('inspector_id', $actor->id);

        $this->assertDatabaseCount('station_inspections', 1);

        $this->actingAsCanonicalUser($otherActor);
        $this->postJson('/api/public/station_inspection', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'OFFLINE_QUEUE_OWNER_MISMATCH')
            ->assertJsonMissingPath('owner_user_id');
        $this->assertDatabaseCount('station_inspections', 1);
    }

    /** @return array{User, Employee} */
    private function canonicalMember(string $employeeNumber, string $name): array
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeNumber,
            'name' => $name,
            'rank' => 'Firefighter',
            'password' => 'not-used-by-e01-tests',
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_profile_id' => $employee->id,
        ]);

        return [$user, $employee];
    }

    private function station(): Station
    {
        return Station::query()->firstOrCreate(
            ['station_number' => 91],
            [
                'name' => 'Station 91',
                'address' => '91 Test Street',
                'is_active' => true,
            ],
        );
    }

    private function apparatus(): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $this->station()->id,
            'unit_id' => 'E91',
            'name' => 'Engine 91',
            'type' => 'Engine',
            'vehicle_number' => 'E91',
            'designation' => 'E91',
            'slug' => 'engine-91',
            'make' => 'Fixture',
            'model' => 'Fixture',
            'year' => 2026,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
        ]);
    }

    /** @return array<string, mixed> */
    private function dailyPayload(Apparatus $apparatus): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $checklist = $resolution['checklist'];
        self::assertIsArray($checklist);

        return [
            'client_submission_id' => 'e0100000-0000-4000-8000-000000000001',
            'checklist_version' => $resolution['checklist_version'],
            'operator_name' => 'Browser Actor',
            'rank' => 'Browser Rank',
            'shift' => 'A',
            'unit_number' => 'CLIENT-VALUE',
            'defects' => [],
            'compartments' => array_map(static function (array $compartment): array {
                $compartmentId = (string) ($compartment['id'] ?? $compartment['title']);

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
            }, $checklist['compartments']),
        ];
    }
}
