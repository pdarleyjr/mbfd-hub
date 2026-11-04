<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Enums\PersonnelRequestStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestUpdate;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\Feature\EmployeeMessaging\BuildsEmployeeMessagingFixtures;

/**
 * Synthetic rig, issue, request and conversation history for the Latest activity suites. Every timestamp is relative to
 * now, so nothing here depends on the calendar.
 */
trait LatestActivityFixtures
{
    use BuildsContinuityFixtures;
    use BuildsEmployeeMessagingFixtures;
    use StationDashboardFixtures;
    use VehicleDashboardFixtures;

    private Apparatus $activityRig;

    private User $activityReporter;

    private ApparatusInspection $activityInspection;

    protected function setUpActivity(): void
    {
        Cache::flush();
        $this->setUpVehicleDashboard();
        $this->activityRig = $this->rig('Rescue 2', 'R2');
        $this->activityReporter = $this->member('Riley Reporter');
        $this->activityInspection = $this->inspectionBy($this->activityRig, $this->activityReporter, now()->subHours(3)->toDateTimeString());
    }

    /** Cached feeds from an earlier call in the same test would hide new rows. */
    protected function forgetFeeds(): void
    {
        Cache::flush();
    }

    protected function superAdmin(): User
    {
        return $this->personas()['super admin'];
    }

    /** Logistics reviewer: sees and manages equipment issues. */
    protected function reviewerAdmin(): User
    {
        return $this->personas()['logistics admin'];
    }

    /** @param list<string> $permissions */
    protected function adminCan(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $this->adminWith($permissions);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function defectIn(string $item = 'Portable radio battery', ?string $owner = null): ApparatusDefect
    {
        $defect = $this->openDefect($this->activityRig, $this->activityInspection, $this->activityReporter, $item);
        if ($owner !== null) {
            $defect->forceFill(['responsible_owner' => $owner])->save();
        }

        return $defect->refresh();
    }

    /** @param array<string, mixed> $attributes */
    protected function defectEvent(ApparatusDefect $defect, string $type, array $attributes = [], int $minutesAgo = 5): ApparatusDefectEvent
    {
        return ApparatusDefectEvent::query()->create(array_merge([
            'apparatus_defect_id' => $defect->id,
            'actor_user_id' => $this->activityReporter->id,
            'event_type' => $type,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ], $attributes));
    }

    protected function issueMessage(ApparatusDefect $defect, string $context, string $body, string $visibility = 'member', int $minutesAgo = 5, ?User $author = null): ApparatusIssueMessage
    {
        return ApparatusIssueMessage::query()->create([
            'apparatus_defect_id' => $defect->id,
            'author_user_id' => $context === 'assistant' ? null : ($author ?? $this->activityReporter)->id,
            'author_context' => $context,
            'visibility' => $visibility,
            'body' => $body,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function stationUpdate(StationRequest $request, array $attributes = [], int $minutesAgo = 5): StationRequestUpdate
    {
        $update = new StationRequestUpdate;
        $update->forceFill(array_merge([
            'station_request_id' => $request->id,
            'status' => $request->status,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ], $attributes))->save();

        return $update;
    }

    protected function personnelRequest(string $number = 'UR-1001', PersonnelRequestStatus $status = PersonnelRequestStatus::Pending): PersonnelRequest
    {
        $employee = $this->employee('Jordan Lee');
        $publicId = (string) str()->ulid();

        return PersonnelRequest::query()->create([
            'public_id' => $publicId, 'request_number' => $number,
            'type' => 'uniform', 'status' => $status,
            'beneficiary_employee_id' => $employee->id, 'requester_employee_id' => $employee->id,
            'beneficiary_name' => $employee->name, 'beneficiary_employee_number' => $employee->employee_id,
            'requester_name' => $employee->name, 'requester_employee_number' => $employee->employee_id,
            'idempotency_key' => 'fixture-'.$publicId,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function personnelUpdate(PersonnelRequest $request, string $event, array $attributes = [], int $minutesAgo = 5): PersonnelRequestUpdate
    {
        return PersonnelRequestUpdate::query()->create(array_merge([
            'personnel_request_id' => $request->id,
            'event' => $event,
            'status' => $request->status,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ], $attributes));
    }

    /** An automated reply in a Support Services conversation, written the way the assistant writes it. */
    protected function assistantReply(int $threadId, string $body = 'We received your message.'): int
    {
        return (int) DB::table('employee_logistics_messages')->insertGetId([
            'thread_id' => $threadId, 'author_user_id' => null, 'author_side' => 'assistant', 'author_name' => 'Support Services',
            'body' => $body, 'client_request_id' => (string) str()->uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<object>  $events
     * @return list<string> the keys of every event the feed returned, in order
     */
    protected function keysOf(array $events): array
    {
        return array_map(static fn ($event): string => $event->key, $events);
    }

    /**
     * @param  list<object>  $events
     * @return list<string>
     */
    protected function kindsOf(array $events): array
    {
        return array_map(static fn ($event): string => $event->kind, $events);
    }
}
