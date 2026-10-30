<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Exceptions\MemberBootstrapStateChanged;
use App\Jobs\SendBudgetedEmail;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\MemberActivationAttempt;
use App\Models\MemberOnboardingInvitation;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * First-time account claiming from records the Hub already holds: Employee ID,
 * the approved City email, and the shift and unit from the latest bid results.
 *
 * These are things a member knows, not something they hold: this is not MFA.
 * It may only ever activate a never-activated ordinary member account and can
 * never reset or replace an established credential.
 */
final class MemberSelfServiceActivation
{
    public function __construct(
        private readonly AccountSecurityService $security,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    public function enabled(): bool
    {
        // Claiming sets a local password, so it follows the local-login switch.
        return (bool) config('identity.self_service_activation.enabled')
            && (bool) config('identity.local_login_enabled')
            && config('identity.credential_authority') === 'local';
    }

    public function emailRequestEnabled(): bool
    {
        return (bool) config('identity.self_service_activation.email_request_enabled');
    }

    /**
     * Choices for the form, taken from the latest bid results so a member
     * selects rather than types. Empty when no results have been published.
     *
     * @return array{shifts:list<string>,units:list<string>,positions:list<string>}
     */
    public function bidChoices(): array
    {
        $year = $this->latestBidYear();
        if ($year === null) {
            return ['shifts' => [], 'units' => [], 'positions' => []];
        }
        $current = EmployeeBidAssignment::query()->where('bid_year', $year)->whereNull('superseded_at');
        $shifts = (clone $current)->distinct()->orderBy('shift_label')->pluck('shift_label')->all();
        $units = (clone $current)->distinct()->pluck('unit_label')->all();
        sort($units, SORT_NATURAL | SORT_FLAG_CASE);

        return ['shifts' => $shifts, 'units' => $units, 'positions' => $this->nonBidPositions($year)];
    }

    /**
     * Returns the activated account, or null for every kind of refusal.
     * Members who bid answer with shift and unit; members whose position does
     * not bid answer with that position. Neither group can use the other's path.
     */
    public function claim(
        string $employeeId,
        string $email,
        ?string $shift,
        ?string $unit,
        ?string $position,
        string $passwordHash,
        CarbonImmutable $at,
    ): ?User {
        $activated = DB::transaction(function () use ($employeeId, $email, $shift, $unit, $position, $passwordHash, $at): ?User {
            $identity = $this->soleIdentity($employeeId);
            if ($identity === null) {
                return null;
            }
            // Same order as email completion: Invitation -> User -> Employee.
            MemberOnboardingInvitation::query()->where('user_id', $identity['user_id'])->lockForUpdate()->get(['id']);
            $user = User::query()->lockForUpdate()->find($identity['user_id']);
            $employee = Employee::query()->lockForUpdate()->find($identity['employee_profile_id']);
            if (! $user instanceof User || ! $employee instanceof Employee || ! $this->eligible($user, $employee)) {
                return null;
            }
            $record = $this->trustedRecord($employee);
            if ($record === null) {
                return null;
            }
            $attempt = MemberActivationAttempt::query()->where('employee_profile_id', $employee->id)->lockForUpdate()->first()
                ?? new MemberActivationAttempt(['employee_profile_id' => $employee->id]);
            if ($attempt->locked_at !== null) {
                return null;
            }

            // Evaluate every answer so a refusal never reveals which one was wrong.
            $matches = [
                hash_equals($record['email'], strtolower(trim($email))),
                hash_equals((string) $record['shift'], (string) $shift),
                hash_equals((string) $record['unit'], (string) $unit),
                hash_equals((string) $record['position'], (string) $position),
            ];
            if (in_array(false, $matches, true)) {
                $this->recordFailure($attempt, $user, $at);

                return null;
            }

            try {
                $activated = $this->security->completeSelfServiceActivation(
                    $user->id, $employee->id, $user->security_version, $passwordHash, $at,
                );
            } catch (MemberBootstrapStateChanged) {
                return null;
            }
            $attempt->forceFill(['activated_at' => $at])->save();

            return $activated;
        }, 3);

        if ($activated instanceof User) {
            $this->notifyMember($activated, $at);
        }

        return $activated;
    }

    public function eligible(User $user, Employee $employee): bool
    {
        return $user->employee_profile_id === $employee->id
            && $user->employee_id === $employee->employee_id
            && $user->getRawOriginal('account_status') === AccountStatus::PendingActivation->value
            && $user->bootstrap_onboarding_eligible
            && $user->bootstrap_onboarding_completed_at === null
            && $employee->roster_status === 'active'
            && ! $this->hasElevatedAccess($user);
    }

    /** Privileged accounts must be activated through the administrator-issued invitation. */
    public function hasElevatedAccess(User $user): bool
    {
        return $user->roles()->where('name', '!=', 'member')->exists()
            || $user->permissions()->exists();
    }

    /**
     * The answers a member must give. Null when the Hub does not hold a
     * complete, unambiguous record, which leaves email activation as the route.
     *
     * @return array{email:string,shift:string|null,unit:string|null,position:string|null}|null
     */
    private function trustedRecord(Employee $employee): ?array
    {
        $email = strtolower(trim((string) $employee->city_email));
        $approved = MemberOnboardingRosterBinding::query()
            ->where('employee_profile_id', $employee->id)
            ->where('employee_id', $employee->employee_id)
            ->where('city_email', $email)
            ->exists();
        $year = $this->latestBidYear();
        if ($email === '' || ! $approved || $year === null) {
            return null;
        }
        $assignments = EmployeeBidAssignment::query()
            ->where('employee_profile_id', $employee->id)
            ->where('bid_year', $year)->whereNull('superseded_at')->limit(2)->get(['shift_label', 'unit_label']);
        if ($assignments->count() > 1) {
            return null;
        }
        if ($assignments->isEmpty()) {
            // A position that does not bid: the position itself is the answer.
            $position = trim((string) $employee->rank);

            return $position === '' ? null : ['email' => $email, 'shift' => null, 'unit' => null, 'position' => $position];
        }
        $shift = (string) $assignments->first()->getAttribute('shift_label');
        $unit = (string) $assignments->first()->getAttribute('unit_label');

        return $shift === '' || $unit === '' ? null : ['email' => $email, 'shift' => $shift, 'unit' => $unit, 'position' => null];
    }

    /**
     * Positions held by active personnel with no result in the latest bid.
     *
     * @return list<string>
     */
    private function nonBidPositions(int $year): array
    {
        $positions = Employee::query()
            ->where('roster_status', 'active')
            ->whereNotNull('rank')
            ->whereNotIn('id', EmployeeBidAssignment::query()->select('employee_profile_id')
                ->where('bid_year', $year)->whereNull('superseded_at'))
            ->distinct()->pluck('rank')
            ->map(static fn (mixed $rank): string => trim((string) $rank))
            ->filter()->unique()->values()->all();
        sort($positions, SORT_NATURAL | SORT_FLAG_CASE);

        return $positions;
    }

    private function latestBidYear(): ?int
    {
        $year = EmployeeBidAssignment::query()->whereNull('superseded_at')->max('bid_year');

        return $year === null ? null : (int) $year;
    }

    /** @return array{user_id:int,employee_profile_id:int}|null */
    private function soleIdentity(string $employeeId): ?array
    {
        $employees = Employee::query()->where('employee_id', $employeeId)->limit(2)->get(['id', 'employee_id']);
        if ($employees->count() !== 1 || $employees->first()->employee_id !== $employeeId) {
            return null;
        }
        $employee = $employees->first();
        $linked = User::query()->where('employee_profile_id', $employee->id)->limit(2)->pluck('id');
        $identified = User::query()->where('employee_id', $employeeId)->limit(2)->pluck('id');
        if ($linked->count() !== 1 || $identified->count() !== 1 || $linked->first() !== $identified->first()) {
            return null;
        }

        return ['user_id' => (int) $linked->first(), 'employee_profile_id' => (int) $employee->id];
    }

    private function recordFailure(MemberActivationAttempt $attempt, User $user, CarbonImmutable $at): void
    {
        $attempts = $attempt->failed_attempts + 1;
        $locked = $attempts >= max(1, (int) config('identity.self_service_activation.max_failed_attempts', 5));
        $attempt->forceFill([
            'failed_attempts' => $attempts,
            'last_failed_at' => $at,
            'locked_at' => $locked ? $at : null,
        ])->save();
        $this->audit->record($user, $user, 'member_self_service_activation_denied', 'denied',
            $locked ? 'verification_locked' : 'verification_mismatch',
            ['employee_profile_id' => $attempt->employee_profile_id, 'failed_attempts' => $attempts],
        );
    }

    /** A notice that cannot even be queued must not undo or block the activation. */
    private function notifyMember(User $user, CarbonImmutable $at): void
    {
        $address = strtolower(trim((string) $user->employeeProfile?->city_email));
        if ($address === '') {
            return;
        }
        try {
            $when = HubTimezone::dateTime($at);
            SendBudgetedEmail::dispatch(
                [$address],
                'Your MBFD Hub account was activated',
                "Your MBFD Hub account was activated and a password was set on {$when}.\n\nIf this was you, no action is needed.\n\nIf this was NOT you, contact MBFD Hub support right away so the account can be secured.",
                null,
                'member_self_service_activation',
                (string) $user->id,
                $user->id,
            );
        } catch (Throwable $exception) {
            Log::warning('member_self_service_activation_notice_failed', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
