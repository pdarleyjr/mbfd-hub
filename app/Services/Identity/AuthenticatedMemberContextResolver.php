<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\AuthenticationSession;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final readonly class AuthenticatedMemberContextResolver
{
    private const REQUEST_ATTRIBUTE = self::class;

    public function __construct(
        private SessionRegistry $sessionRegistry,
        private WorkgroupAccess $workgroupAccess,
    ) {}

    public function resolve(Request $request): AuthenticatedMemberContext
    {
        $resolved = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($resolved instanceof AuthenticatedMemberContext) {
            return $resolved;
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->isAuthenticationAllowed() || ! $request->hasSession()) {
            $this->unauthenticated();
        }

        $session = $this->authenticationSession($request);

        if (! $session instanceof AuthenticationSession
            || ! $this->sessionRegistry->isCurrent($user, $session, CarbonImmutable::now())) {
            $this->unauthenticated();
        }

        $user->loadMissing('employeeProfile:id,employee_id,name,rank');
        $employee = $user->employeeProfile;

        $abilities = $user->getAllPermissions()
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $memberships = WorkgroupMember::query()
            ->select(['id', 'workgroup_id', 'user_id', 'role', 'is_active'])
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('workgroup', static fn ($query) => $query->where('is_active', true))
            ->with('workgroup:id,name')
            ->get()
            ->sortBy(static function (WorkgroupMember $member): string {
                $workgroup = $member->workgroup;

                return $workgroup instanceof Workgroup ? (string) $workgroup->name : '';
            })
            ->values();

        $workgroups = [];

        foreach ($memberships as $membership) {
            $workgroup = $membership->workgroup;

            if (! $workgroup instanceof Workgroup) {
                continue;
            }

            $workgroups[] = [
                'id' => (int) $membership->workgroup_id,
                'name' => (string) $workgroup->name,
                'membership_role' => (string) $membership->role,
            ];
        }

        $activeWorkgroup = $this->activeWorkgroup($request, $user, $memberships->all());

        $context = new AuthenticatedMemberContext(
            user: $user,
            employee: $employee,
            abilities: $abilities,
            workgroups: $workgroups,
            activeWorkgroup: $activeWorkgroup,
        );

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $context);

        return $context;
    }

    private function authenticationSession(Request $request): ?AuthenticationSession
    {
        $laravelSessionId = $request->session()->getId();

        if ($laravelSessionId === '') {
            return null;
        }

        return AuthenticationSession::query()
            ->where('session_id_hash', hash_hmac('sha256', $laravelSessionId, (string) config('app.key')))
            ->first();
    }

    /**
     * @param  list<WorkgroupMember>  $memberships
     * @return array{id: int, name: string, membership_role: string|null}|null
     */
    private function activeWorkgroup(Request $request, User $user, array $memberships): ?array
    {
        $selectedId = $request->session()->get(WorkgroupContext::SESSION_KEY);
        $selectedId = is_int($selectedId)
            ? $selectedId
            : (is_string($selectedId) && ctype_digit($selectedId) ? (int) $selectedId : null);

        if ($selectedId !== null) {
            foreach ($memberships as $membership) {
                if ($membership->workgroup_id === $selectedId && $membership->workgroup instanceof Workgroup) {
                    return [
                        'id' => (int) $membership->workgroup_id,
                        'name' => (string) $membership->workgroup->name,
                        'membership_role' => (string) $membership->role,
                    ];
                }
            }

            if ($this->workgroupAccess->isGlobalViewer($user)) {
                $workgroup = Workgroup::query()
                    ->whereKey($selectedId)
                    ->where('is_active', true)
                    ->first(['id', 'name']);

                if ($workgroup instanceof Workgroup) {
                    return [
                        'id' => (int) $workgroup->id,
                        'name' => (string) $workgroup->name,
                        'membership_role' => null,
                    ];
                }
            }

            return null;
        }

        if (count($memberships) !== 1 || ! $memberships[0]->workgroup instanceof Workgroup) {
            return null;
        }

        return [
            'id' => (int) $memberships[0]->workgroup_id,
            'name' => (string) $memberships[0]->workgroup->name,
            'membership_role' => (string) $memberships[0]->role,
        ];
    }

    private function unauthenticated(): never
    {
        throw new AuthenticationException('Unauthenticated.', ['sanctum']);
    }
}
