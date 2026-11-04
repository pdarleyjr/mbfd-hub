<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

/**
 * SQL fragments that read the triage projection from a list query without joining (a correlated scalar
 * subquery cannot multiply or hide rows, and the (subject_type, subject_id) key makes it one index lookup).
 * No user input ever reaches these strings.
 */
final class TriageSql
{
    /** The projected effective level of the record whose id is in $idColumn, or NULL when not projected yet. */
    public static function level(string $subjectType, string $idColumn): string
    {
        return "(SELECT support_triage_levels.level FROM support_triage_levels WHERE support_triage_levels.subject_type = '{$subjectType}' AND support_triage_levels.subject_id = {$idColumn})";
    }

    /**
     * Effective level of an equipment issue: flagged significant or out of service is always HIGH, otherwise the
     * projection, otherwise $fallback (the old rule) for rows not projected yet.
     */
    public static function defectLevel(string $fallback): string
    {
        return "CASE WHEN apparatus_defects.severity = 'significant' OR apparatus_defects.operational_impact = 'out_of_service' THEN 'high' ELSE COALESCE("
            .self::level(TriageProjector::DEFECT, 'apparatus_defects.id').", {$fallback}) END";
    }
}
