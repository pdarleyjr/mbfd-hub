<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections\Concerns;

use App\Enums\AccountStatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\User;
use App\Services\ApparatusInspectionExceptionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;

/**
 * Daily Checkout submissions for unit E2 (400 h, 1000 mi, vehicle V-202) and the
 * reviewers who decide what automatic processing holds.
 */
trait BuildsInspectionReviewFixtures
{
    use BuildsVehicleSwapFixtures;

    /** A payload whose paper mileage matches the reported meters. */
    protected function meterPayload(?int $miles, ?float $hours, bool $withFinding = false): array
    {
        $payload = $this->payload(null, $withFinding);
        $payload['miles'] = $miles;
        $payload['engine_hours'] = $hours;
        $this->setField($payload, 'mileage', $miles);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    protected function submit(array $payload): TestResponse
    {
        return $this->postJson($this->submissionUrl(), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{compartment_id: string, item_id: string}>
     */
    protected function firstItems(array $payload, int $count = 2): array
    {
        $items = [];
        foreach (array_slice($payload['compartments'][0]['items'], 0, $count) as $item) {
            $items[] = ['compartment_id' => $payload['compartments'][0]['id'], 'item_id' => $item['id']];
        }

        return $items;
    }

    /** An administrator who may approve, reject and reconcile. */
    protected function reviewer(bool $mayUpdateApparatus = false): User
    {
        $reviewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        $reviewer->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        // Registered before any check so the permission registry never caches a list without it.
        $update = Permission::findOrCreate('update_apparatus', 'web');
        if ($mayUpdateApparatus) {
            $reviewer->givePermissionTo($update);
        }

        return $reviewer->fresh();
    }

    /** @param array<string, mixed> $decision */
    protected function decideMeter(ApparatusInspectionException $hold, User $reviewer, array $decision): ApparatusInspectionException
    {
        $current = $hold->apparatus->fresh()->getAttribute('current_'.$hold->field);

        return app(ApparatusInspectionExceptionService::class)->reconcile($hold->id, $reviewer, [
            'reason' => 'Reviewed the reading.',
            'expected_current_value' => $current,
            ...$decision,
        ]);
    }

    protected function holdFor(ApparatusInspection $inspection, string $field): ApparatusInspectionException
    {
        return ApparatusInspectionException::query()
            ->where('apparatus_inspection_id', $inspection->id)->where('field', $field)->firstOrFail();
    }

    protected function assertCan(User $user, string $ability, ApparatusInspection $inspection, bool $expected): void
    {
        $this->assertSame($expected, Gate::forUser($user)->allows($ability, $inspection));
    }
}
