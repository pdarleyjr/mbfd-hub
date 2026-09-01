<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final readonly class AuthenticatedMemberContext
{
    /**
     * @param  list<string>  $abilities
     * @param  list<array{id: int, name: string, membership_role: string}>  $workgroups
     * @param  array{id: int, name: string, membership_role: string|null}|null  $activeWorkgroup
     */
    public function __construct(
        private User $user,
        private ?Employee $employee,
        private array $abilities,
        private array $workgroups,
        private ?array $activeWorkgroup,
    ) {}

    public function user(): User
    {
        return $this->user;
    }

    public function employee(): ?Employee
    {
        return $this->employee;
    }

    public function actor(): AuthenticatedActor
    {
        return new AuthenticatedActor($this->user, $this->employee);
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return $this->abilities;
    }

    /** @return list<array{id: int, name: string, membership_role: string}> */
    public function workgroups(): array
    {
        return $this->workgroups;
    }

    /** @return array{id: int, name: string, membership_role: string|null}|null */
    public function activeWorkgroup(): ?array
    {
        return $this->activeWorkgroup;
    }

    public function requireAbility(string $ability, mixed $subject = null): void
    {
        $gate = Gate::forUser($this->user);

        if ($subject === null) {
            if (! in_array($ability, $this->abilities, true)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            return;
        }

        $gate->authorize($ability, $subject);
    }

    /**
     * @return array{
     *     version: int,
     *     identity: array{user_id: int, has_personnel_profile: bool},
     *     personnel: array{employee_profile_id: int, employee_number: string, name: string, rank: string}|null,
     *     authorization: array{
     *         abilities: list<string>,
     *         workgroups: list<array{id: int, name: string, membership_role: string}>,
     *         active_workgroup: array{id: int, name: string, membership_role: string|null}|null
     *     },
     *     operational_context: array{station: null, apparatus: null, room: null, shift: null, device: null},
     *     session: array{authenticated: true}
     * }
     */
    public function toClientArray(): array
    {
        return [
            'version' => 1,
            'identity' => [
                'user_id' => (int) $this->user->getKey(),
                'has_personnel_profile' => $this->employee instanceof Employee,
            ],
            'personnel' => $this->employee instanceof Employee ? [
                'employee_profile_id' => (int) $this->employee->getKey(),
                'employee_number' => (string) $this->employee->employee_id,
                'name' => (string) $this->employee->name,
                'rank' => (string) $this->employee->rank,
            ] : null,
            'authorization' => [
                'abilities' => $this->abilities,
                'workgroups' => $this->workgroups,
                'active_workgroup' => $this->activeWorkgroup,
            ],
            'operational_context' => [
                'station' => null,
                'apparatus' => null,
                'room' => null,
                'shift' => null,
                'device' => null,
            ],
            'session' => [
                'authenticated' => true,
            ],
        ];
    }
}
