<?php

declare(strict_types=1);

namespace App\Services\Display;

/**
 * Weighted station-readiness model for the command display.
 *
 * Pure, side-effect-free calculator. Produces a 0-100 score, a status band,
 * and human-readable reasons — never a bare number. Inputs are simple scalars
 * so the caller (DisplaySnapshotService) keeps all data access in one place
 * and this class stays trivially testable.
 *
 * Weighting (sums to 100):
 *   - 40  explicit Daily Checkout completion (checked + attention / required rigs)
 *   - 25  station inspection currency (passed within 30d)
 *   - 15  inverse of unresolved equipment-request load
 *   - 10  apparatus status / open-defect health (in-service ratio + penalty)
 *   - 10  source freshness (snapshot age)
 */
final class DisplayReadiness
{
    public const STATUS_READY = 'READY';

    public const STATUS_ATTENTION = 'ATTENTION';

    public const STATUS_INCOMPLETE = 'INCOMPLETE';

    public const STATUS_CRITICAL = 'CRITICAL';

    public const STATUS_UNKNOWN = 'UNKNOWN';

    private const W_CHECKOUT = 40.0;

    private const W_STATION_INSPECTION = 25.0;

    private const W_REQUEST_LOAD = 15.0;

    private const W_STATUS_DEFECTS = 10.0;

    private const W_FRESHNESS = 10.0;

    /**
     * @return array{percent: int, status: string, reasons: list<string>}
     */
    public static function compute(
        int $requiredApparatusCount,
        int $checkedApparatusCount,
        int $attentionApparatusCount,
        int $reviewPendingApparatusCount,
        int $notCheckedApparatusCount,
        int $unknownApparatusCount,
        int $inServiceCount,
        int $outOfServiceCount,
        int $maintenanceCount,
        int $openDefects,
        int $criticalDefects,
        ?string $lastStationInspectionStatus,
        ?int $stationInspectionAgeDays,
        int $pendingEquipmentRequests,
        int $criticalPendingEquipmentRequests,
        int $snapshotAgeSeconds
    ): array {
        $reasons = [];

        // No Daily Checkout policy signal and no station inspection signal =>
        // nothing to grade on.
        if ($requiredApparatusCount === 0 && $unknownApparatusCount === 0 && $lastStationInspectionStatus === null) {
            return [
                'percent' => 0,
                'status' => self::STATUS_UNKNOWN,
                'reasons' => ['No apparatus or recent station inspection data available'],
            ];
        }

        // 1. Explicit Daily Checkout completion (40). A repeated submission
        // is already collapsed to its apparatus identity by the compliance
        // service before reaching this pure calculator. An approved checkout
        // with an unresolved critical defect is `attention`: it is completed
        // for the owner-approved Daily denominator, while the final readiness
        // state below remains ATTENTION.
        if ($requiredApparatusCount > 0) {
            $completedApparatusCount = $checkedApparatusCount + $attentionApparatusCount;
            $ratio = min(1.0, $completedApparatusCount / $requiredApparatusCount);
            $checkoutScore = self::W_CHECKOUT * $ratio;
            $reasons[] = sprintf(
                '%d of %d apparatus completed Daily Checkout',
                min($completedApparatusCount, $requiredApparatusCount),
                $requiredApparatusCount
            );
        } elseif ($unknownApparatusCount > 0) {
            // Missing policy classification must not silently receive the
            // full checkout score.
            $checkoutScore = 0.0;
        } else {
            // No apparatus explicitly requires Daily Checkout: this dimension
            // does not apply, so do not penalise the station for that alone.
            $checkoutScore = self::W_CHECKOUT;
            $reasons[] = 'No apparatus explicitly requires Daily Checkout';
        }

        if ($unknownApparatusCount > 0) {
            $reasons[] = sprintf(
                '%d apparatus %s daily checkout policy classification',
                $unknownApparatusCount,
                $unknownApparatusCount === 1 ? 'needs' : 'need'
            );
        }

        if ($reviewPendingApparatusCount > 0) {
            $reasons[] = sprintf(
                '%d apparatus checkout%s require%s review',
                $reviewPendingApparatusCount,
                $reviewPendingApparatusCount === 1 ? '' : 's',
                $reviewPendingApparatusCount === 1 ? 's' : ''
            );
        }

        if ($attentionApparatusCount > 0) {
            $reasons[] = sprintf(
                '%d checked apparatus %s an unresolved critical defect',
                $attentionApparatusCount,
                $attentionApparatusCount === 1 ? 'has' : 'have'
            );
        }

        if ($notCheckedApparatusCount > 0) {
            $reasons[] = sprintf(
                '%d required apparatus %s not checked today',
                $notCheckedApparatusCount,
                $notCheckedApparatusCount === 1 ? 'is' : 'are'
            );
        }

        // 2. Station inspection currency (25).
        if ($lastStationInspectionStatus === null) {
            $inspectionScore = 0.0;
            $reasons[] = 'No station inspection in the last 30 days';
        } elseif (strtolower($lastStationInspectionStatus) === 'pass') {
            $inspectionScore = self::W_STATION_INSPECTION;
            $reasons[] = $stationInspectionAgeDays !== null
                ? sprintf('Station inspection passed %dd ago', $stationInspectionAgeDays)
                : 'Station inspection passed';
        } elseif (strtolower($lastStationInspectionStatus) === 'needs_attention') {
            $inspectionScore = self::W_STATION_INSPECTION * 0.5;
            $reasons[] = 'Station inspection flagged needs attention';
        } else {
            $inspectionScore = 0.0;
            $reasons[] = 'Station inspection failed';
        }

        // 3. Inverse of unresolved equipment-request load (15).
        // 0 pending => full; load tapers the score, critical pending hits harder.
        $loadPenalty = min(1.0, ($pendingEquipmentRequests * 0.15) + ($criticalPendingEquipmentRequests * 0.35));
        $requestScore = self::W_REQUEST_LOAD * (1.0 - $loadPenalty);
        if ($pendingEquipmentRequests > 0) {
            $reasons[] = $criticalPendingEquipmentRequests > 0
                ? sprintf(
                    '%d equipment request%s pending (%d critical)',
                    $pendingEquipmentRequests,
                    $pendingEquipmentRequests === 1 ? '' : 's',
                    $criticalPendingEquipmentRequests
                )
                : sprintf(
                    '%d equipment request%s pending',
                    $pendingEquipmentRequests,
                    $pendingEquipmentRequests === 1 ? '' : 's'
                );
        } else {
            $reasons[] = 'No pending equipment requests';
        }

        // 4. Apparatus status / open-defect health (10).
        $actualApparatusCount = $inServiceCount + $outOfServiceCount + $maintenanceCount;
        if ($actualApparatusCount > 0) {
            $inServiceRatio = min(1.0, $inServiceCount / $actualApparatusCount);
            $defectPenalty = min(0.5, ($openDefects * 0.05) + ($criticalDefects * 0.15));
            $statusScore = max(0.0, self::W_STATUS_DEFECTS * ($inServiceRatio - $defectPenalty));
        } else {
            $statusScore = self::W_STATUS_DEFECTS;
        }
        if ($outOfServiceCount > 0) {
            $reasons[] = sprintf(
                '%d apparatus out of service',
                $outOfServiceCount
            );
        }
        if ($maintenanceCount > 0) {
            $reasons[] = sprintf(
                '%d apparatus in maintenance',
                $maintenanceCount
            );
        }
        if ($criticalDefects > 0) {
            $reasons[] = sprintf(
                '%d critical defect%s open',
                $criticalDefects,
                $criticalDefects === 1 ? '' : 's'
            );
        } elseif ($openDefects > 0) {
            $reasons[] = sprintf(
                '%d open defect%s',
                $openDefects,
                $openDefects === 1 ? '' : 's'
            );
        }

        // 5. Source freshness (10). Full credit under 5 min, decays to zero by 1h.
        $freshnessRatio = self::freshnessRatio($snapshotAgeSeconds);
        $freshnessScore = self::W_FRESHNESS * $freshnessRatio;
        if ($freshnessRatio < 1.0) {
            $reasons[] = sprintf('Data is %ds old', $snapshotAgeSeconds);
        }

        $percent = (int) round(
            $checkoutScore + $inspectionScore + $requestScore + $statusScore + $freshnessScore
        );
        $percent = max(0, min(100, $percent));

        $status = self::statusForPercent($percent);
        if (
            $status === self::STATUS_READY
            && (
                $unknownApparatusCount > 0
                || $attentionApparatusCount > 0
                || $reviewPendingApparatusCount > 0
                || $notCheckedApparatusCount > 0
            )
        ) {
            $status = self::STATUS_ATTENTION;
        }

        return [
            'percent' => $percent,
            'status' => $status,
            'reasons' => array_values($reasons),
        ];
    }

    private static function freshnessRatio(int $ageSeconds): float
    {
        if ($ageSeconds <= 300) {
            return 1.0;
        }
        if ($ageSeconds >= 3600) {
            return 0.0;
        }

        // Linear decay between 5 minutes and 60 minutes.
        return max(0.0, 1.0 - (($ageSeconds - 300) / (3600 - 300)));
    }

    private static function statusForPercent(int $percent): string
    {
        return match (true) {
            $percent >= 85 => self::STATUS_READY,
            $percent >= 70 => self::STATUS_ATTENTION,
            $percent >= 50 => self::STATUS_INCOMPLETE,
            default => self::STATUS_CRITICAL,
        };
    }
}
