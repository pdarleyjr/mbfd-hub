<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Captain5\Captain5DutyAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Services\Captain5\Captain5DutyRoster;
use App\Support\Captain5\DutyCalendar;
use App\Support\Fleet\InspectionReviewers;
use DateTimeInterface;

/**
 * The single Captain 5 EMS Operations Center authorization decision
 * (docs/captain5-ems-operations-center.md section 2). Routes, APIs, attachment downloads,
 * communications, issue mutations and message threads all ask this class.
 *
 * Gate abilities deliberately differ from the permission name (`captain5.admin`), so Spatie's
 * Gate::before can never short-circuit the account and override checks here.
 */
final class Captain5Access
{
    public const PERMISSION_ADMIN = 'captain5.admin';

    public const GATE_VIEW = 'captain5-view';

    public const GATE_ACT = 'captain5-act';

    public const GATE_MANAGE_ASSIGNMENTS = 'captain5-manage-assignments';

    public const GATE_ADMIN = 'captain5-admin';

    /** @var array<string, Captain5AccessDecision> */
    private array $memo = [];

    public function __construct(
        private readonly Captain5DutyRoster $roster,
        private readonly Command300Access $command300,
    ) {}

    public function decide(?User $user, ?DateTimeInterface $moment = null): Captain5AccessDecision
    {
        $onDuty = $this->roster->onDuty($moment);
        if (! $user instanceof User || ! $user->isAuthenticationAllowed() || $user->must_change_password) {
            return Captain5AccessDecision::none($onDuty);
        }

        $key = $user->getKey().'|'.$onDuty->dutyDate->format('Y-m-d').'|'.($moment?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'));

        return $this->memo[$key] ??= $this->evaluate($user, $moment);
    }

    /**
     * Same answer as decide()->canViewData. Administrators are answered without the duty roster
     * (admin pages and nav badges call this on every request); everyone else gets the full decision.
     */
    public function canView(?User $user): bool
    {
        if ($user instanceof User && $this->usable($user)) {
            if ($user->hasRole('super_admin')) {
                return true;
            }
            if (in_array('admin.access', $this->directGrants($user), true) && ! $this->isDenied($user)) {
                return true;
            }
        }

        return $this->decide($user)->canViewData;
    }

    public function canAct(?User $user): bool
    {
        return $this->decide($user)->canAct;
    }

    public function canManageAssignments(?User $user): bool
    {
        return $this->decide($user)->canManageAssignments;
    }

    /**
     * Same answer as decide()->canConfigure, without the duty roster: configuration depends only on
     * the account, the Admin Panel entitlement, the direct `captain5.admin` grant and a deny override.
     */
    public function canConfigure(?User $user): bool
    {
        if (! $user instanceof User || ! $this->usable($user)) {
            return false;
        }
        if ($user->hasRole('super_admin')) {
            return true; // protected recovery access: a deny override never applies
        }
        $direct = $this->directGrants($user);

        return in_array('admin.access', $direct, true) && in_array(self::PERMISSION_ADMIN, $direct, true)
            && ! $this->isDenied($user);
    }

    private function usable(User $user): bool
    {
        return $user->isAuthenticationAllowed() && ! $user->must_change_password;
    }

    /** @return list<string> */
    private function directGrants(User $user): array
    {
        return $user->permissions()->where('guard_name', 'web')
            ->whereIn('name', ['admin.access', self::PERMISSION_ADMIN])->pluck('name')->all();
    }

    private function isDenied(User $user): bool
    {
        $employee = $this->currentEmployee($user);

        return $employee !== null && Captain5AccessOverride::query()->current()
            ->where('employee_profile_id', $employee->getKey())
            ->where('effect', Captain5AccessOverride::EFFECT_DENY)->exists();
    }

    /** Forget per-request memoization (after an override or assignment change). */
    public function flush(): void
    {
        $this->memo = [];
        $this->roster->flush();
    }

    /** Trim, collapse whitespace and lower-case; unknown titles never match. */
    public static function normalizeRank(?string $rank): string
    {
        return Command300Access::normalizeRank($rank);
    }

    private function evaluate(User $user, ?DateTimeInterface $moment): Captain5AccessDecision
    {
        $moment ??= now();
        $onDuty = $this->roster->onDuty($moment);
        $superAdmin = $user->hasRole('super_admin');
        $direct = $user->permissions()->where('guard_name', 'web')
            ->whereIn('name', ['admin.access', self::PERMISSION_ADMIN])->pluck('name')->all();
        $adminPanel = $superAdmin || in_array('admin.access', $direct, true);
        $reviewer = InspectionReviewers::canReview($user);
        $employee = $this->currentEmployee($user);
        $employeeId = $employee !== null ? (int) $employee->getKey() : null;

        $override = $employeeId !== null
            ? Captain5AccessOverride::query()->current()->where('employee_profile_id', $employeeId)->first()
            : null;
        $denied = $override?->effect === Captain5AccessOverride::EFFECT_DENY;

        if ($denied && ! $superAdmin) {
            // Deny beats every automatic qualification. Only super_admin (protected recovery) survives.
            return Captain5AccessDecision::none($onDuty, true);
        }

        $sources = [];
        if ($superAdmin) {
            $sources[] = Captain5AccessDecision::SOURCE_SUPER_ADMIN;
        } elseif ($adminPanel) {
            $sources[] = Captain5AccessDecision::SOURCE_ADMINISTRATOR;
        }

        $rank = $employee !== null ? self::normalizeRank($employee->rank) : '';
        $officerRanks = array_map(static fn (string $r): string => self::normalizeRank($r), (array) config('captain5.officer_ranks', []));
        if ($rank !== '' && in_array($rank, $officerRanks, true)) {
            $sources[] = Captain5AccessDecision::SOURCE_OFFICER_RANK;
        }

        $captain5Seat = $employeeId !== null ? $this->roster->captain5Seats()->get($employeeId) : null;
        $emsSeat = $employeeId !== null ? $this->roster->emsCaptainSeats()->get($employeeId) : null;
        if ($captain5Seat !== null) {
            $sources[] = Captain5AccessDecision::SOURCE_CAPTAIN5_SEAT;
        }
        if ($emsSeat !== null) {
            $sources[] = Captain5AccessDecision::SOURCE_EMS_CAPTAIN_SEAT;
        }

        $acting = $employeeId !== null && $onDuty->assignment instanceof Captain5DutyAssignment
            && (int) $onDuty->assignment->employee_profile_id === $employeeId
                ? $onDuty->assignment
                : null;
        if ($acting !== null) {
            $sources[] = Captain5AccessDecision::SOURCE_ACTING;
        }

        if ($override?->effect === Captain5AccessOverride::EFFECT_ALLOW) {
            $sources[] = $override->level === Captain5AccessOverride::LEVEL_ACT
                ? Captain5AccessDecision::SOURCE_ALLOW_ACT
                : Captain5AccessDecision::SOURCE_ALLOW_VIEW;
        }
        if ($reviewer) {
            $sources[] = Captain5AccessDecision::SOURCE_REVIEWER;
        }

        $canView = $sources !== [];
        $captain5Act = $captain5Seat !== null || $emsSeat !== null || $acting !== null
            || in_array(Captain5AccessDecision::SOURCE_ALLOW_ACT, $sources, true);
        $onDutyAuthority = $acting !== null || (
            $captain5Seat !== null
            && $onDuty->source === \App\Services\Captain5\OnDutyCaptain5::SOURCE_BID
            && $onDuty->isEmployee($employeeId)
        );
        $configure = $adminPanel && ($superAdmin || in_array(self::PERMISSION_ADMIN, $direct, true));
        $managerRanks = array_map(static fn (string $r): string => self::normalizeRank($r), (array) config('captain5.assignment_manager_ranks', []));
        $manageAssignments = $configure || $emsSeat !== null
            || ($rank !== '' && in_array($rank, $managerRanks, true))
            || $this->command300->automaticSource($user) === Command300Access::SOURCE_ASSIGNMENT;

        return new Captain5AccessDecision(
            sources: $sources,
            canViewPage: $canView,
            canViewData: $canView,
            canAct: $captain5Act || $reviewer,
            hasOnDutyAuthority: $onDutyAuthority,
            canDismiss: $onDutyAuthority || $emsSeat !== null || $reviewer,
            canManageAssignments: $manageAssignments,
            canConfigure: $configure,
            isReviewer: $reviewer,
            denied: $denied,
            bidShiftLetter: $captain5Seat !== null ? DutyCalendar::letterFromBidShift($captain5Seat->shift_label) : null,
            actingAssignment: $acting,
            onDuty: $onDuty,
        );
    }

    private function currentEmployee(User $user): ?Employee
    {
        if ($user->employee_profile_id === null) {
            return null;
        }
        $employee = $user->relationLoaded('employeeProfile') ? $user->employeeProfile : $user->employeeProfile()->first();

        return $employee instanceof Employee && (string) $employee->employee_id === (string) $user->employee_id
            && $employee->roster_status !== 'departed' ? $employee : null;
    }
}
