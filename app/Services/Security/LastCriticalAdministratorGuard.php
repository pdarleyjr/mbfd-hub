<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class LastCriticalAdministratorGuard
{
    // PostgreSQL two-integer advisory namespace: "MBFD", "ADMN".
    private const LOCK_NAMESPACE = 0x4D424644;

    private const MUTATION_LOCK = 0x41444D4E;

    public function lockActiveCriticalAdministrators(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Administrative locking requires an active transaction.');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            // A changing set of active administrator rows is not a stable mutex:
            // a queued query can retain its old membership snapshot. Serialize
            // guarded administrative mutations before taking any participant row
            // locks. This transaction-scoped lock is never used by read previews
            // or ordinary login. SQLite serializes writes without this primitive.
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, self::MUTATION_LOCK]);
        }
        User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->whereIn(
                'name',
                array_values((array) config('security.critical_roles', [])),
            ))
            ->orderBy('users.id')
            ->lockForUpdate()
            ->get();
    }

    public function allowsDisable(User $target, bool $lockForUpdate = false): bool
    {
        $criticalRoles = array_values((array) config('security.critical_roles', []));
        if (! $target->hasAnyRole($criticalRoles)) {
            return true;
        }

        $query = User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $criticalRoles))
            ->orderBy('users.id');

        if ($lockForUpdate) {
            return $query->lockForUpdate()->get()->contains(
                fn (User $user): bool => ! $user->is($target),
            );
        }

        return (clone $query)->where('users.id', '!=', $target->getKey())->exists();
    }

    /**
     * @param  list<string>  $proposedRoleNames
     */
    public function allowsRoleSet(User $target, array $proposedRoleNames, bool $lockForUpdate = false): bool
    {
        $criticalRoles = array_values((array) config('security.critical_roles', []));

        if (! $target->hasAnyRole($criticalRoles)
            || $target->getRawOriginal('account_status') !== AccountStatus::Active->value
            || array_intersect($criticalRoles, $proposedRoleNames) !== []) {
            return true;
        }

        $query = User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $criticalRoles))
            ->orderBy('users.id');

        if ($lockForUpdate) {
            return $query->lockForUpdate()->get()->contains(
                fn (User $user): bool => ! $user->is($target),
            );
        }

        return (clone $query)->where('users.id', '!=', $target->getKey())->exists();
    }
}
