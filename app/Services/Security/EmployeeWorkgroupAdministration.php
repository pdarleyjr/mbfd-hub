<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EmployeeWorkgroupAdministration
{
    public function __construct(private readonly SecurityAuditRecorder $audit) {}

    /** @param array<array-key, mixed> $memberships Untrusted repeater state. */
    public function sync(User $actor, User $target, array $memberships, string $currentPassword, string $reason): void
    {
        try {
            DB::transaction(function () use ($actor, $target, $memberships, $currentPassword, $reason): void {
                $users = User::query()->whereKey([$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $currentActor = $users->get($actor->id);
                $currentTarget = $users->get($target->id);
                if (! $currentActor instanceof User || ! $currentTarget instanceof User
                    || ! $currentActor->isAuthenticationAllowed() || $currentActor->is($currentTarget)
                    || (! $currentActor->hasRole('super_admin') && ! $currentActor->can('admin.workgroups.manage'))) {
                    throw new AuthorizationException('Current workgroup management authority for another account is required.');
                }
                if (! Hash::check($currentPassword, $currentActor->getAuthPassword())) {
                    throw new CurrentPasswordMismatch;
                }
                $validated = Validator::make(['memberships' => $memberships, 'reason' => $reason], [
                    'reason' => ['required', 'string', 'max:500'],
                    'memberships' => ['present', 'array'],
                    'memberships.*' => ['required', 'array:workgroup_id,role,is_active,count_evaluations'],
                    'memberships.*.workgroup_id' => ['required', 'integer', 'min:1', 'distinct'],
                    'memberships.*.role' => ['required', 'in:admin,facilitator,member'],
                    'memberships.*.is_active' => ['required', 'boolean'],
                    'memberships.*.count_evaluations' => ['required', 'boolean'],
                ])->validate();
                $requested = collect($validated['memberships'])->keyBy(fn (array $row): int => (int) $row['workgroup_id']);
                $existingIds = WorkgroupMember::query()->where('user_id', $currentTarget->id)->pluck('workgroup_id');
                $groupIds = $existingIds->merge($requested->keys())->unique()->sort()->values();
                $groups = Workgroup::query()->whereKey($groupIds->all())->orderBy('id')->lockForUpdate()->get();
                if ($groups->count() !== $groupIds->count()) {
                    throw ValidationException::withMessages(['memberships' => 'A selected workgroup no longer exists. Reload the profile.']);
                }
                $existing = WorkgroupMember::query()->where('user_id', $currentTarget->id)->orderBy('workgroup_id')->lockForUpdate()->get();
                if ($existing->pluck('workgroup_id')->diff($groupIds)->isNotEmpty()) {
                    throw ValidationException::withMessages(['memberships' => 'Workgroup assignments changed concurrently. Reload the profile.']);
                }
                $before = $existing->map(fn (WorkgroupMember $member): array => $this->snapshot($member))->all();
                foreach ($existing as $member) {
                    if (! $requested->has($member->workgroup_id) && $member->is_active) {
                        $member->update(['is_active' => false]);
                    }
                }
                // addUsers intentionally preserves active roles and cannot express
                // inactive entries. Update these explicit fields without deleting
                // pivots, so submissions, attendance, files and notes retain IDs.
                foreach ($requested->sortKeys() as $groupId => $row) {
                    $member = WorkgroupMember::query()->firstOrCreate(['user_id' => $currentTarget->id, 'workgroup_id' => $groupId], [
                        'role' => $row['role'], 'is_active' => (bool) $row['is_active'], 'count_evaluations' => (bool) $row['count_evaluations'],
                    ]);
                    $member->update(['role' => $row['role'], 'is_active' => (bool) $row['is_active'], 'count_evaluations' => (bool) $row['count_evaluations']]);
                }
                $after = WorkgroupMember::query()->where('user_id', $currentTarget->id)->orderBy('workgroup_id')->get()
                    ->map(fn (WorkgroupMember $member): array => $this->snapshot($member))->all();
                $this->audit->record($currentActor, $currentTarget, 'change_employee_workgroups', 'allowed', trim($reason), ['before' => $before, 'after' => $after]);
            });
        } catch (Throwable $exception) {
            $this->audit->record($actor, $target, 'change_employee_workgroups', $exception instanceof AuthorizationException || $exception instanceof ValidationException ? 'denied' : 'failed', mb_substr(trim($reason), 0, 500));
            throw $exception;
        }
    }

    /** @return array{id:int,workgroup_id:int,role:string,is_active:bool,count_evaluations:bool} */
    private function snapshot(WorkgroupMember $member): array
    {
        return ['id' => $member->id, 'workgroup_id' => $member->workgroup_id, 'role' => $member->role, 'is_active' => $member->is_active, 'count_evaluations' => $member->count_evaluations];
    }
}
