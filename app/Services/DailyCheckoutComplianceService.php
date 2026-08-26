<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resolves Daily Checkout compliance from the explicit apparatus policy and
 * completed inspection records. Staffing complements are intentionally not a
 * Daily Checkout source of truth.
 */
final class DailyCheckoutComplianceService
{
    public const TIMEZONE = 'America/New_York';

    /**
     * @param  Collection<int, object>  $apparatuses
     * @return array{required_count: int, completed_required_count: int, checked_count: int, attention_count: int, review_pending_count: int, not_checked_count: int, missing_required_count: int, exempt_count: int, reserve_count: int, administrative_count: int, inactive_count: int, not_required_count: int, unknown_count: int, out_of_service_count: int, matrix: list<array{apparatus_id: int, state: string, daily_checkout_requirement: string, out_of_service: bool}>}
     */
    public function summaryForApparatuses(Collection $apparatuses, ?CarbonImmutable $now = null): array
    {
        [$startOfDay, $startOfNextDay] = $this->localDayWindow($now);

        $inspectionSignals = $this->inspectionSignals($apparatuses, $startOfDay, $startOfNextDay);
        $criticalDefectApparatusIds = $this->unresolvedCriticalDefectApparatusIds($apparatuses);

        return $this->summary($apparatuses, $inspectionSignals, $criticalDefectApparatusIds);
    }

    /**
     * Builds each station's summary from one completed-inspection query.
     * Callers should eager-load the apparatuses relation before invoking this.
     *
     * @param  Collection<int, object>  $stations
     * @return array<int, array{required_count: int, completed_required_count: int, checked_count: int, attention_count: int, review_pending_count: int, not_checked_count: int, missing_required_count: int, exempt_count: int, reserve_count: int, administrative_count: int, inactive_count: int, not_required_count: int, unknown_count: int, out_of_service_count: int, matrix: list<array{apparatus_id: int, state: string, daily_checkout_requirement: string, out_of_service: bool}>}>
     */
    public function summariesForStations(Collection $stations, ?CarbonImmutable $now = null): array
    {
        [$startOfDay, $startOfNextDay] = $this->localDayWindow($now);
        $apparatusByStation = $stations->mapWithKeys(
            fn (object $station): array => [(int) $station->id => $station->apparatuses]
        );
        $allApparatuses = $apparatusByStation->flatten(1);
        $inspectionSignals = $this->inspectionSignals($allApparatuses, $startOfDay, $startOfNextDay);
        $criticalDefectApparatusIds = $this->unresolvedCriticalDefectApparatusIds($allApparatuses);

        return $apparatusByStation
            ->map(fn (Collection $apparatuses): array => $this->summary(
                $apparatuses,
                $inspectionSignals,
                $criticalDefectApparatusIds,
            ))
            ->all();
    }

    /**
     * @param  Collection<int, object>  $apparatuses
     * @param  array<int, array{completed: bool, pending_review: bool}>  $inspectionSignals
     * @param  list<int>  $criticalDefectApparatusIds
     * @return array{required_count: int, completed_required_count: int, checked_count: int, attention_count: int, review_pending_count: int, not_checked_count: int, missing_required_count: int, exempt_count: int, reserve_count: int, administrative_count: int, inactive_count: int, not_required_count: int, unknown_count: int, out_of_service_count: int, matrix: list<array{apparatus_id: int, state: string, daily_checkout_requirement: string, out_of_service: bool}>}
     */
    private function summary(
        Collection $apparatuses,
        array $inspectionSignals,
        array $criticalDefectApparatusIds,
    ): array {
        $requiredCount = 0;
        $completedRequiredCount = 0;
        $checkedCount = 0;
        $attentionCount = 0;
        $reviewPendingCount = 0;
        $notCheckedCount = 0;
        $exemptCount = 0;
        $reserveCount = 0;
        $administrativeCount = 0;
        $inactiveCount = 0;
        $notRequiredCount = 0;
        $unknownCount = 0;
        $outOfServiceCount = 0;
        $criticalDefectLookup = array_fill_keys($criticalDefectApparatusIds, true);
        $matrix = [];

        foreach ($apparatuses->unique(fn (object $apparatus): int => (int) $apparatus->id) as $apparatus) {
            $apparatusId = (int) $apparatus->id;
            $isOutOfService = $this->isOutOfService((string) $apparatus->getAttribute('status'));
            if ($isOutOfService) {
                $outOfServiceCount++;
            }

            $requirement = $this->requirementValue($apparatus);
            if ($requirement === 'unknown') {
                $unknownCount++;

                $matrix[] = [
                    'apparatus_id' => $apparatusId,
                    'state' => 'unknown',
                    'daily_checkout_requirement' => 'unknown',
                    'out_of_service' => $isOutOfService,
                ];

                continue;
            }

            if ($requirement !== 'required') {
                $notRequiredCount++;
                match ($requirement) {
                    'exempt' => $exemptCount++,
                    'reserve' => $reserveCount++,
                    'administrative' => $administrativeCount++,
                    'inactive' => $inactiveCount++,
                    default => null,
                };

                $matrix[] = [
                    'apparatus_id' => $apparatusId,
                    'state' => $requirement,
                    'daily_checkout_requirement' => $requirement,
                    'out_of_service' => $isOutOfService,
                ];

                continue;
            }

            $requiredCount++;
            $signal = $inspectionSignals[$apparatusId] ?? ['completed' => false, 'pending_review' => false];
            if (! $signal['completed']) {
                $notCheckedCount++;
                $state = 'not_checked';
            } elseif ($signal['pending_review']) {
                $completedRequiredCount++;
                $reviewPendingCount++;
                $state = 'review_pending';
            } elseif (isset($criticalDefectLookup[$apparatusId])) {
                $completedRequiredCount++;
                $attentionCount++;
                $state = 'attention';
            } else {
                $completedRequiredCount++;
                $checkedCount++;
                $state = 'checked';
            }

            $matrix[] = [
                'apparatus_id' => $apparatusId,
                'state' => $state,
                'daily_checkout_requirement' => 'required',
                'out_of_service' => $isOutOfService,
            ];
        }

        return [
            'required_count' => $requiredCount,
            'completed_required_count' => $completedRequiredCount,
            'checked_count' => $checkedCount,
            'attention_count' => $attentionCount,
            'review_pending_count' => $reviewPendingCount,
            'not_checked_count' => $notCheckedCount,
            'missing_required_count' => $notCheckedCount,
            'exempt_count' => $exemptCount,
            'reserve_count' => $reserveCount,
            'administrative_count' => $administrativeCount,
            'inactive_count' => $inactiveCount,
            'not_required_count' => $notRequiredCount,
            'unknown_count' => $unknownCount,
            'out_of_service_count' => $outOfServiceCount,
            'matrix' => $matrix,
        ];
    }

    /**
     * @param  Collection<int, object>  $apparatuses
     * @return array<int, array{completed: bool, pending_review: bool}>
     */
    private function inspectionSignals(
        Collection $apparatuses,
        CarbonImmutable $startOfDay,
        CarbonImmutable $startOfNextDay,
    ): array {
        $apparatusIds = $apparatuses
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($apparatusIds === []) {
            return [];
        }

        $signals = [];
        $inspections = ApparatusInspection::query()
            ->whereIn('apparatus_id', $apparatusIds)
            // This is an intentional data-integrity cutover. Historical rows
            // predate the server-side checklist reconciliation and therefore
            // have no durable client submission identifier. They remain in
            // history but cannot silently satisfy the new compliance signal.
            ->whereNotNull('client_submission_id')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $startOfDay)
            ->where('completed_at', '<', $startOfNextDay)
            ->get(['apparatus_id', 'review_status']);

        foreach ($inspections as $inspection) {
            $apparatusId = (int) $inspection->apparatus_id;
            $signals[$apparatusId] ??= ['completed' => true, 'pending_review' => false];
            if (strtolower((string) $inspection->review_status) === 'pending_review') {
                $signals[$apparatusId]['pending_review'] = true;
            }
        }

        return $signals;
    }

    /**
     * @param  Collection<int, object>  $apparatuses
     * @return list<int>
     */
    private function unresolvedCriticalDefectApparatusIds(Collection $apparatuses): array
    {
        $apparatusIds = $apparatuses
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($apparatusIds === []) {
            return [];
        }

        return ApparatusDefect::query()
            ->whereIn('apparatus_id', $apparatusIds)
            ->where('resolved', false)
            ->whereIn('status', ['Missing', 'Damaged'])
            ->select('apparatus_id')
            ->distinct()
            ->pluck('apparatus_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function localDayWindow(?CarbonImmutable $now): array
    {
        $localNow = ($now ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $startOfDay = $localNow->startOfDay();

        return [$startOfDay->utc(), $startOfDay->addDay()->utc()];
    }

    private function requirementValue(object $apparatus): string
    {
        $value = $apparatus->getRawOriginal('daily_checkout_requirement');
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['required', 'exempt', 'reserve', 'administrative', 'inactive', 'unknown'], true)
            ? $normalized
            : 'unknown';
    }

    private function isOutOfService(string $status): bool
    {
        $normalized = strtolower(trim($status));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return in_array($normalized, ['out_of_service', 'oos', 'down', 'retired'], true);
    }
}
