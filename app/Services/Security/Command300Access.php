<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\DailyRoster\RosterSection;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\User;
use App\Services\DailyRoster\RosterSeatAccess;

/**
 * The single 300 Command authorization decision. The 300 profile, its
 * communications, 300 video-conferencing command controls and the Command
 * Board's Hub sign-in all ask this class; nothing else re-derives eligibility.
 *
 * Eligible = an active account with no pending password setup, and ANY of:
 * administrator (super_admin or current Admin Panel entitlement), the
 * authoritative personnel rank Captain, Division Chief, Deputy Fire Chief or
 * Fire Chief, a verified current 300 bid assignment, working the 300 seat on
 * the accepted TeleStaff roster for the duty day on the clock, or a direct
 * `command.300.access` grant.
 */
final class Command300Access
{
    public const PERMISSION = 'command.300.access';

    /**
     * Gate ability name. Deliberately NOT the permission name: Spatie's own
     * Gate::before would otherwise pass any holder of the permission (even via
     * a role) and skip this class's account and direct-grant checks.
     */
    public const GATE = 'access-command-300';

    public const SOURCE_ADMINISTRATOR = 'administrator';

    public const SOURCE_CAPTAIN = 'captain';

    public const SOURCE_DIVISION_CHIEF = 'division_chief';

    public const SOURCE_DEPUTY_FIRE_CHIEF = 'deputy_fire_chief';

    public const SOURCE_FIRE_CHIEF = 'fire_chief';

    public const SOURCE_ASSIGNMENT = 'assignment_300';

    public const SOURCE_ROSTER = 'roster_300';

    public const SOURCE_GRANT = 'explicit_grant';

    /** The published bid unit that is the 300 command seat (exact match only). */
    public const COMMAND_UNIT_LABEL = '300';

    private const COMMAND_RANKS = [
        'captain' => self::SOURCE_CAPTAIN,
        'division chief' => self::SOURCE_DIVISION_CHIEF,
        'deputy fire chief' => self::SOURCE_DEPUTY_FIRE_CHIEF,
        'fire chief' => self::SOURCE_FIRE_CHIEF,
    ];

    private const SOURCE_LABELS = [
        self::SOURCE_ADMINISTRATOR => 'Automatic — Administrator',
        self::SOURCE_CAPTAIN => 'Automatic — Captain',
        self::SOURCE_DIVISION_CHIEF => 'Automatic — Division Chief',
        self::SOURCE_DEPUTY_FIRE_CHIEF => 'Automatic — Deputy Fire Chief',
        self::SOURCE_FIRE_CHIEF => 'Automatic — Fire Chief',
        self::SOURCE_ASSIGNMENT => 'Automatic — 300 assignment',
        self::SOURCE_ROSTER => "Automatic — On today's TeleStaff roster",
        self::SOURCE_GRANT => 'Explicit access grant',
    ];

    public function __construct(private readonly RosterSeatAccess $rosterSeats) {}

    public function allows(?User $user): bool
    {
        return $this->source($user) !== null;
    }

    /** The first qualification that applies, or null when access is denied. */
    public function source(?User $user): ?string
    {
        if (! $user instanceof User || ! $this->accountUsable($user)) {
            return null;
        }
        if ($user->hasRole('super_admin')) {
            return self::SOURCE_ADMINISTRATOR; // Roles are already loaded; skip the grant read.
        }
        $direct = $this->directGrants($user);

        return $this->automaticSourceFrom($user, $direct)
            ?? (in_array(self::PERMISSION, $direct, true) ? self::SOURCE_GRANT : null);
    }

    /** Qualification that does not depend on the optional direct grant. */
    public function automaticSource(User $user): ?string
    {
        return $this->automaticSourceFrom($user, $this->directGrants($user));
    }

    /** Whether this member would have access if the roster did not list them. */
    public function qualifiesBesidesRoster(User $user): bool
    {
        $source = $this->source($user);

        return $source !== null
            && ($source !== self::SOURCE_ROSTER || in_array(self::PERMISSION, $this->directGrants($user), true));
    }

    /** @param list<string> $direct */
    private function automaticSourceFrom(User $user, array $direct): ?string
    {
        // Same contract as User::hasCurrentAdminPanelEntitlement() (super_admin
        // or a direct admin.access grant), read from one permission query.
        if ($user->hasRole('super_admin') || in_array('admin.access', $direct, true)) {
            return self::SOURCE_ADMINISTRATOR;
        }
        $employee = $this->currentEmployee($user);
        if ($employee === null) {
            return null;
        }

        return self::COMMAND_RANKS[self::normalizeRank($employee->rank)]
            ?? ($this->hasVerifiedCurrentAssignment($employee) ? self::SOURCE_ASSIGNMENT : null)
            ?? ($this->rosterSeats->holdsSeat($employee, RosterSection::ROLE_COMMAND_300) ? self::SOURCE_ROSTER : null);
    }

    /** @return list<string> Direct (never role-inherited) web grants this policy reads. */
    private function directGrants(User $user): array
    {
        return $user->permissions()->where('guard_name', 'web')
            ->whereIn('name', ['admin.access', self::PERMISSION])->pluck('name')->all();
    }

    public function sourceLabel(?string $source): string
    {
        return self::SOURCE_LABELS[$source] ?? 'No access';
    }

    /** Trim, collapse whitespace and lower-case; unknown titles never match. */
    public static function normalizeRank(?string $rank): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $rank)));
    }

    private function accountUsable(User $user): bool
    {
        return $user->isAuthenticationAllowed() && ! $user->must_change_password;
    }

    private function currentEmployee(User $user): ?Employee
    {
        if ($user->employee_profile_id === null) {
            return null;
        }
        $employee = $user->relationLoaded('employeeProfile') ? $user->employeeProfile : $user->employeeProfile()->first();

        // isAuthenticationAllowed() already proved the linkage is current; keep
        // the guard so a stale preloaded relation cannot grant a rank.
        return $employee instanceof Employee && (string) $employee->employee_id === (string) $user->employee_id
            && $employee->roster_status !== 'departed' ? $employee : null;
    }

    /**
     * A 300 assignment counts only when it is the member's single current,
     * integrity-checked row in the latest published bid year. Superseded or
     * older-year rows never qualify.
     */
    private function hasVerifiedCurrentAssignment(Employee $employee): bool
    {
        $rows = EmployeeBidAssignment::query()->where('employee_profile_id', $employee->getKey())
            ->where('payload_version', 2)->whereNull('superseded_at')
            ->where('bid_year', fn ($latest) => $latest->from('employee_bid_assignments')->selectRaw('max(bid_year)')
                ->where('payload_version', 2)->whereNull('superseded_at'))
            ->limit(2)->get();
        if ($rows->count() !== 1) {
            return false;
        }
        $assignment = $rows->first();
        foreach (['source_result_hash', 'source_workbook_sha256', 'payload_hash'] as $hash) {
            if (! preg_match('/^[a-f0-9]{64}$/D', (string) $assignment->getAttribute($hash))) {
                return false;
            }
        }

        return trim((string) $assignment->unit_label) === self::COMMAND_UNIT_LABEL
            && $assignment->term_label === $assignment->bid_year.'–'.($assignment->bid_year + 1)
            && in_array($assignment->assignment_source, ['bid_award', 'retained_nonbiddable'], true);
    }
}
