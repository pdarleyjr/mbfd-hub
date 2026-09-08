<?php

declare(strict_types=1);

namespace App\Services\Workgroup;

use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class WorkgroupMembershipService
{
    /**
     * @param  list<int|string>  $userIds
     */
    public function addUsers(
        Workgroup $workgroup,
        array $userIds,
        string $role = 'member',
        bool $countEvaluations = true,
    ): int {
        if (! in_array($role, ['admin', 'facilitator', 'member'], true)) {
            throw new InvalidArgumentException('Invalid workgroup role.');
        }

        $requestedIds = collect($userIds)
            ->map(function (mixed $id): int {
                if ((is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id) && (int) $id > 0)) {
                    return (int) $id;
                }

                throw ValidationException::withMessages([
                    'user_ids' => 'One or more selected users are invalid.',
                ]);
            })
            ->unique()
            ->values();

        if ($requestedIds->isEmpty()) {
            return 0;
        }

        $validUserIds = User::query()
            ->whereKey($requestedIds->all())
            ->pluck('id')
            ->sort()
            ->values();

        if ($validUserIds->all() !== $requestedIds->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'user_ids' => 'One or more selected users no longer exist.',
            ]);
        }

        return DB::transaction(function () use ($workgroup, $validUserIds, $role, $countEvaluations): int {
            $changed = 0;

            foreach ($validUserIds as $userId) {
                $membership = WorkgroupMember::query()->firstOrCreate(
                    [
                        'workgroup_id' => $workgroup->id,
                        'user_id' => $userId,
                    ],
                    [
                        'role' => $role,
                        'is_active' => true,
                        'count_evaluations' => $countEvaluations,
                    ],
                );

                if ($membership->wasRecentlyCreated) {
                    $changed++;

                    continue;
                }

                if (! $membership->is_active) {
                    $membership->update([
                        'role' => $role,
                        'is_active' => true,
                        'count_evaluations' => $countEvaluations,
                    ]);
                    $changed++;
                }
            }

            return $changed;
        });
    }

    /** @return Builder<User> */
    public function availableUsers(Workgroup $workgroup): Builder
    {
        return User::query()
            ->whereNotIn('id', WorkgroupMember::query()
                ->select('user_id')
                ->where('workgroup_id', $workgroup->id)
                ->where('is_active', true));
    }
}
