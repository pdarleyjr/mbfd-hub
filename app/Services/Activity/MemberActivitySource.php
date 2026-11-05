<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One domain's view of the member's own records. An adapter reads canonical foreign keys only (never
 * typed names), returns member-safe text, and runs a fixed number of queries however many rows it returns.
 */
interface MemberActivitySource
{
    /**
     * @return list<MemberActivityItem> at most $limit parents, newest records first
     */
    public function items(User $user, ?Employee $employee, CarbonImmutable $since, int $limit): array;
}
