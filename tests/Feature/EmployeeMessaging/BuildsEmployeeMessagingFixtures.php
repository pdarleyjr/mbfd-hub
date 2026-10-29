<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\User;
use App\Services\EmployeeMessaging\EmployeeLogisticsMessagingService;
use App\Services\Security\EmployeeLogisticsMessagingAccess;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/** Synthetic members, Logistics responders and helpers for the Employee <-> Logistics messaging suites. */
trait BuildsEmployeeMessagingFixtures
{
    private int $messagingSeq = 0;

    protected function service(): EmployeeLogisticsMessagingService
    {
        return app(EmployeeLogisticsMessagingService::class);
    }

    protected function access(): EmployeeLogisticsMessagingAccess
    {
        return app(EmployeeLogisticsMessagingAccess::class);
    }

    /** A fresh, valid client request id (36 chars, letters/digits/hyphens). */
    protected function rid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * An active member with a current linked personnel profile.
     *
     * @param  array<string, mixed>  $employee  overrides for the personnel row
     * @param  array<string, mixed>  $user  overrides for the account row
     */
    protected function messagingMember(string $name = 'Pat Member', array $employee = [], array $user = []): User
    {
        $n = ++$this->messagingSeq;
        $profile = Employee::query()->create(array_merge([
            'employee_id' => 'EMP-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'rank' => 'Firefighter',
            'station' => 'Station 1',
            'roster_status' => 'active',
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
        ], $employee));

        return User::factory()->create(array_merge([
            'account_status' => AccountStatus::Active,
            'employee_profile_id' => $profile->id,
            'employee_id' => $profile->employee_id,
            'name' => $name,
            'must_change_password' => false,
        ], $user));
    }

    /**
     * An account with no personnel profile (an administrator), holding exactly the given roles and
     * direct permissions.
     *
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    protected function messagingAdmin(array $roles = [], array $permissions = [], array $attributes = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create(array_merge([
            'account_status' => AccountStatus::Active,
            'name' => 'Lou Logistics '.++$this->messagingSeq,
            'must_change_password' => false,
        ], $attributes));
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** Admin Panel entitlement plus the logistics_admin role: view and reply. */
    protected function logisticsResponder(array $attributes = []): User
    {
        return $this->messagingAdmin(['logistics_admin'], ['admin.access'], $attributes);
    }

    /** Admin Panel entitlement plus only the direct view capability: read-only. */
    protected function logisticsViewer(): User
    {
        return $this->messagingAdmin([], ['admin.access', EmployeeLogisticsMessagingAccess::PERMISSION_VIEW]);
    }

    /** An administrator who reaches /admin and Communications but has no messaging capability. */
    protected function unrelatedAdmin(): User
    {
        return $this->messagingAdmin([], ['admin.access', 'admin.communications.view', 'admin.communications.send']);
    }

    protected function memberSends(User $member, string $body = 'Hello Logistics, my boots arrived damaged.', ?string $subject = null): EmployeeLogisticsMessage
    {
        return $this->service()->startThread($member, $body, $subject, $this->rid());
    }

    protected function memberReplies(User $member, EmployeeLogisticsMessage|EmployeeLogisticsThread|int $in, string $body = 'Any update on this?'): EmployeeLogisticsMessage
    {
        return $this->service()->replyAsMember($member, $this->threadIdOf($in), $body, $this->rid());
    }

    protected function logisticsReplies(User $responder, EmployeeLogisticsMessage|EmployeeLogisticsThread|int $in, string $body = 'We are looking into it.'): EmployeeLogisticsMessage
    {
        return $this->service()->replyAsLogistics($responder, $this->threadIdOf($in), $body, $this->rid());
    }

    protected function threadIdOf(EmployeeLogisticsMessage|EmployeeLogisticsThread|int $in): int
    {
        return match (true) {
            $in instanceof EmployeeLogisticsMessage => (int) $in->thread_id,
            $in instanceof EmployeeLogisticsThread => (int) $in->getKey(),
            default => $in,
        };
    }

    /** The thread row exactly as stored right now. */
    protected function storedThread(EmployeeLogisticsMessage|EmployeeLogisticsThread|int $in): EmployeeLogisticsThread
    {
        return EmployeeLogisticsThread::query()->findOrFail($this->threadIdOf($in));
    }

    /**
     * Assert the callable throws exactly this exception type (or a subclass) and return it.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function assertFailsWith(string $class, callable $callable): Throwable
    {
        try {
            $callable();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($class, $exception, 'Unexpected exception: '.$exception::class.': '.$exception->getMessage());

            return $exception;
        }
        $this->fail('Expected '.$class.' but nothing was thrown.');
    }

    /** Assert the callable fails validation on the given field. */
    protected function assertInvalid(callable $callable, string $field = 'body'): ValidationException
    {
        $exception = $this->assertFailsWith(ValidationException::class, $callable);
        $this->assertArrayHasKey($field, $exception->errors());

        return $exception;
    }
}
