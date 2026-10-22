<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\ApparatusInspectionException;
use Illuminate\Database\Eloquent\Builder;

/**
 * A hold is an inspection exception row with blocks_acceptance = true that has
 * not been decided (docs/daily-inspection-continuity.md section 2.1).
 */
final class InspectionHold
{
    public const FIELD_REVIEW_FLAG = 'member_review_flag';

    public const FIELD_SIGNIFICANT_DAMAGE = 'significant_damage';

    public const REASON_ROLLBACK = 'rollback';

    public const REASON_MEMBER_FLAGGED = 'member_flagged';

    public const REASON_SIGNIFICANT_DAMAGE = 'new_significant_damage';

    public const METER_FIELDS = ['miles', 'engine_hours'];

    /** Exception statuses that no longer count as open. */
    public const CLOSED_STATUSES = ['resolved', 'dismissed'];

    /** @return Builder<ApparatusInspectionException> */
    public static function openQuery(int $inspectionId): Builder
    {
        return ApparatusInspectionException::query()
            ->where('apparatus_inspection_id', $inspectionId)
            ->where('blocks_acceptance', true)
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public static function isMeter(ApparatusInspectionException $exception): bool
    {
        return in_array($exception->field, self::METER_FIELDS, true);
    }
}
