<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Roster names that open the member's profile. A roster row carries only the TeleStaff Employee ID, so a link goes
 * to one resolver that finds the profile by that verified ID (never by name) and checks access again on arrival.
 *
 * Whether the viewer may open profiles is decided once per request; a viewer who may not keeps the roster exactly
 * as it was.
 */
final class RosterProfileLinks
{
    private const REQUEST_KEY = 'daily-roster.profile-links';

    /** Whether the signed-in viewer may open personnel profiles. Computed once per request. */
    public function enabled(): bool
    {
        $actor = auth('web')->user();
        if (! $actor instanceof User) {
            return false;
        }
        $request = request();
        $key = self::REQUEST_KEY.'.'.$actor->getKey();
        $cached = $request->attributes->get($key);
        if (is_bool($cached)) {
            return $cached;
        }
        $enabled = $actor->isAuthenticationAllowed() && EmployeeResource::canViewAny();
        $request->attributes->set($key, $enabled);

        return $enabled;
    }

    /** The resolver link for a roster row, or null for a vacant row, a row without an ID, or a viewer without access. */
    public function url(?string $employeeId): ?string
    {
        $employeeId = trim((string) $employeeId);
        if ($employeeId === '' || ! self::isWellFormed($employeeId) || ! $this->enabled()) {
            return null;
        }

        return route('daily-roster.profile', ['employeeId' => $employeeId]);
    }

    public static function isWellFormed(string $employeeId): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $employeeId) === 1;
    }

    /**
     * The one profile a roster Employee ID names: an exact match first, then a single match that differs only in
     * leading zeros. Returns the matches so a caller can tell none from more than one.
     *
     * @return Collection<int, Employee>
     */
    public function matches(string $employeeId): Collection
    {
        $exact = Employee::query()->where('employee_id', $employeeId)->limit(2)->get();
        if ($exact->isNotEmpty()) {
            return $exact;
        }
        $normalized = ltrim($employeeId, '0');
        if ($normalized === '') {
            return collect();
        }

        // Candidates end with the same digits; the comparison itself is exact after removing leading zeros.
        return Employee::query()->where('employee_id', 'like', '%'.addcslashes($normalized, '%_\\'))->limit(50)->get()
            ->filter(fn (Employee $employee): bool => ltrim((string) $employee->employee_id, '0') === $normalized)
            ->values();
    }
}
