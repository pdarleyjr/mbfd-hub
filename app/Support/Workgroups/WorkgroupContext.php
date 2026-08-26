<?php

declare(strict_types=1);

namespace App\Support\Workgroups;

use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class WorkgroupContext
{
    public const SESSION_KEY = 'workgroups.active_workgroup_id';

    public function current(User $user): ?Workgroup
    {
        $selectedId = $this->selectedId();

        if ($selectedId !== null) {
            $selected = $this->access()->scopeWorkgroups(Workgroup::query(), $user)->find($selectedId);

            if ($selected !== null) {
                return $selected;
            }

            session()->forget(self::SESSION_KEY);
        }

        $available = $this->available($user);

        return $available->count() === 1 ? $available->first() : null;
    }

    public function select(User $user, int $workgroupId): Workgroup
    {
        $workgroup = $this->access()->scopeWorkgroups(Workgroup::query(), $user)->find($workgroupId);

        abort_unless($workgroup !== null, 404);

        session()->put(self::SESSION_KEY, $workgroup->id);

        return $workgroup;
    }

    /** @return Collection<int, Workgroup> */
    public function available(User $user): Collection
    {
        return $this->access()
            ->scopeWorkgroups(Workgroup::query(), $user)
            ->orderBy('name')
            ->get();
    }

    public function requireCurrent(User $user): Workgroup
    {
        $workgroup = $this->current($user);

        abort_unless($workgroup !== null, 404);

        return $workgroup;
    }

    public function member(User $user): ?WorkgroupMember
    {
        $workgroup = $this->current($user);

        if ($workgroup === null) {
            return null;
        }

        return WorkgroupMember::query()
            ->where('user_id', $user->id)
            ->where('workgroup_id', $workgroup->id)
            ->where('is_active', true)
            ->first();
    }

    public function requireMember(User $user): WorkgroupMember
    {
        $member = $this->member($user);

        abort_unless($member !== null, 404);

        return $member;
    }

    /** @return Builder<WorkgroupSession> */
    public function sessions(User $user): Builder
    {
        return WorkgroupSession::query()->where('workgroup_id', $this->requireCurrent($user)->id);
    }

    private function selectedId(): ?int
    {
        $value = session()->get(self::SESSION_KEY);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function access(): WorkgroupAccess
    {
        return app(WorkgroupAccess::class);
    }
}
