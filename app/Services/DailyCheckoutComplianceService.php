<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusOperationalStatusEvent;
use App\Models\Station;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Resolves Daily Checkout compliance from the explicit apparatus policy and
 * canonical, approved inspection records. Staffing complements are not a
 * Daily Checkout source of truth.
 */
final class DailyCheckoutComplianceService
{
    public const TIMEZONE = 'America/New_York';

    /**
     * @param  Collection<int, Apparatus>  $apparatuses
     * @return array<string, mixed>
     */
    public function summaryForApparatuses(Collection $apparatuses, ?CarbonImmutable $now = null): array
    {
        [$startOfDay, $startOfNextDay] = $this->localDayWindow($now);

        return $this->summary(
            $apparatuses,
            $this->inspectionSignals($apparatuses, $startOfDay, $startOfNextDay),
            $this->unresolvedCriticalDefectApparatusIds($apparatuses),
            $this->statusTransitionSignals($apparatuses, $startOfDay, $startOfNextDay),
        );
    }

    /**
     * Builds each station's summary from batched signal queries. Callers should
     * eager-load the apparatuses relation before invoking this method.
     *
     * @param  Collection<int, Station>  $stations
     * @return array<int, array<string, mixed>>
     */
    public function summariesForStations(Collection $stations, ?CarbonImmutable $now = null): array
    {
        [$startOfDay, $startOfNextDay] = $this->localDayWindow($now);
        /** @var Collection<int, Collection<int, Apparatus>> $apparatusByStation */
        $apparatusByStation = $stations->mapWithKeys(function (Station $station): array {
            /** @var Collection<int, Apparatus> $apparatuses */
            $apparatuses = $station->apparatuses;

            return [(int) $station->id => $apparatuses];
        });
        /** @var Collection<int, Apparatus> $allApparatuses */
        $allApparatuses = $apparatusByStation->flatten(1);
        $inspectionSignals = $this->inspectionSignals($allApparatuses, $startOfDay, $startOfNextDay);
        $criticalDefectApparatusIds = $this->unresolvedCriticalDefectApparatusIds($allApparatuses);
        $statusTransitionSignals = $this->statusTransitionSignals($allApparatuses, $startOfDay, $startOfNextDay);

        return $apparatusByStation
            ->map(function (Collection $stationApparatuses) use (
                $inspectionSignals,
                $criticalDefectApparatusIds,
                $statusTransitionSignals,
            ): array {
                /** @var Collection<int, Apparatus> $stationApparatuses */
                return $this->summary(
                    $stationApparatuses,
                    $inspectionSignals,
                    $criticalDefectApparatusIds,
                    $statusTransitionSignals,
                );
            })
            ->all();
    }

    /**
     * Canonical invariants:
     *
     * - required_total = checked + attention + review_pending + not_checked
     * - completed = checked + attention
     * - completion_percent is null (never 100/NaN) when required_total is zero
     *
     * @param  Collection<int, Apparatus>  $apparatuses
     * @param  array<int, array{latest_approved_completed_at: ?CarbonImmutable, has_pending_submission: bool}>  $inspectionSignals
     * @param  list<int>  $criticalDefectApparatusIds
     * @param  array<int, array{return_checkout_required: bool, return_checkout_cutoff: ?CarbonImmutable}>  $statusTransitionSignals
     * @return array<string, mixed>
     */
    private function summary(
        Collection $apparatuses,
        array $inspectionSignals,
        array $criticalDefectApparatusIds,
        array $statusTransitionSignals,
    ): array {
        $requiredTotal = 0;
        $checked = 0;
        $attention = 0;
        $reviewPending = 0;
        $notChecked = 0;
        $pendingSubmissionCount = 0;
        $classificationRequired = 0;
        $outOfService = 0;
        $exempt = 0;
        $explicitExempt = 0;
        $reserve = 0;
        $administrative = 0;
        $inactive = 0;
        $notRequired = 0;
        $returnCheckoutRequired = 0;
        $matrix = [];
        $criticalDefectLookup = array_fill_keys($criticalDefectApparatusIds, true);

        foreach ($apparatuses->unique(fn (Apparatus $apparatus): int => (int) $apparatus->id) as $apparatus) {
            $apparatusId = (int) $apparatus->id;
            $requirement = $this->requirementValue($apparatus);
            $isOutOfService = $this->isOutOfService((string) $apparatus->getAttribute('status'));
            $signal = $inspectionSignals[$apparatusId] ?? [
                'latest_approved_completed_at' => null,
                'has_pending_submission' => false,
            ];
            $returnSignal = $statusTransitionSignals[$apparatusId] ?? [
                'return_checkout_required' => false,
                'return_checkout_cutoff' => null,
            ];

            if ($signal['has_pending_submission']) {
                $pendingSubmissionCount++;
            }

            if ($requirement !== 'required' && $requirement !== 'unknown') {
                $notRequired++;
                match ($requirement) {
                    'exempt' => $explicitExempt++,
                    'reserve' => $reserve++,
                    'administrative' => $administrative++,
                    'inactive' => $inactive++,
                    default => null,
                };
            }

            // Policy classification is never inferred. A missing classification
            // fails closed and remains separately visible to every consumer.
            $requiresClassification = $requirement === 'unknown';
            if ($requiresClassification) {
                $classificationRequired++;
            }

            // OOS is a first-class operational state. It wins matrix display,
            // never contributes to the required denominator, and cannot be
            // represented as checked even if an approved checkout exists.
            if ($isOutOfService) {
                $outOfService++;
                $matrix[] = $this->matrixRow(
                    apparatusId: $apparatusId,
                    state: 'out_of_service',
                    requirement: $requirement,
                    outOfService: true,
                    classificationRequired: $requiresClassification,
                    includedInRequiredTotal: false,
                    includedInCompleted: false,
                    hasPendingSubmission: $signal['has_pending_submission'],
                    returnCheckoutRequired: false,
                    returnCheckoutVerified: false,
                );

                continue;
            }

            if ($requiresClassification) {
                $matrix[] = $this->matrixRow(
                    apparatusId: $apparatusId,
                    state: 'classification_required',
                    requirement: $requirement,
                    outOfService: false,
                    classificationRequired: true,
                    includedInRequiredTotal: false,
                    includedInCompleted: false,
                    hasPendingSubmission: $signal['has_pending_submission'],
                    returnCheckoutRequired: false,
                    returnCheckoutVerified: false,
                );

                continue;
            }

            // Explicit non-required policies remain visible but are not part of
            // the operational completion denominator. All of them normalize to
            // the exclusive canonical exempt state; the source policy remains
            // available in daily_checkout_requirement and legacy counters.
            if ($requirement !== 'required') {
                $exempt++;
                $matrix[] = $this->matrixRow(
                    apparatusId: $apparatusId,
                    state: 'exempt',
                    requirement: $requirement,
                    outOfService: false,
                    classificationRequired: false,
                    includedInRequiredTotal: false,
                    includedInCompleted: false,
                    hasPendingSubmission: $signal['has_pending_submission'],
                    returnCheckoutRequired: false,
                    returnCheckoutVerified: false,
                );

                continue;
            }

            $requiredTotal++;
            $checkoutRequiredAfterReturn = $returnSignal['return_checkout_required'];
            if ($checkoutRequiredAfterReturn) {
                $returnCheckoutRequired++;
            }
            $latestApprovedAt = $signal['latest_approved_completed_at'];
            $hasQualifyingApprovedCheckout = $latestApprovedAt !== null
                && (! $checkoutRequiredAfterReturn
                    || $latestApprovedAt->greaterThan($returnSignal['return_checkout_cutoff']));
            $returnCheckoutVerified = $checkoutRequiredAfterReturn && $hasQualifyingApprovedCheckout;

            if ($hasQualifyingApprovedCheckout && isset($criticalDefectLookup[$apparatusId])) {
                $attention++;
                $matrix[] = $this->matrixRow(
                    apparatusId: $apparatusId,
                    state: 'attention',
                    requirement: 'required',
                    outOfService: false,
                    classificationRequired: false,
                    includedInRequiredTotal: true,
                    includedInCompleted: true,
                    hasPendingSubmission: $signal['has_pending_submission'],
                    returnCheckoutRequired: $checkoutRequiredAfterReturn,
                    returnCheckoutVerified: $returnCheckoutVerified,
                );

                continue;
            }

            if ($hasQualifyingApprovedCheckout) {
                $checked++;
                $matrix[] = $this->matrixRow(
                    apparatusId: $apparatusId,
                    state: 'checked',
                    requirement: 'required',
                    outOfService: false,
                    classificationRequired: false,
                    includedInRequiredTotal: true,
                    includedInCompleted: true,
                    hasPendingSubmission: $signal['has_pending_submission'],
                    returnCheckoutRequired: $checkoutRequiredAfterReturn,
                    returnCheckoutVerified: $returnCheckoutVerified,
                );

                continue;
            }

            if ($signal['has_pending_submission']) {
                $reviewPending++;
                $matrix[] = $this->matrixRow(
                    apparatusId: $apparatusId,
                    state: 'review_pending',
                    requirement: 'required',
                    outOfService: false,
                    classificationRequired: false,
                    includedInRequiredTotal: true,
                    includedInCompleted: false,
                    hasPendingSubmission: true,
                    returnCheckoutRequired: $checkoutRequiredAfterReturn,
                    returnCheckoutVerified: false,
                );

                continue;
            }

            $notChecked++;
            $matrix[] = $this->matrixRow(
                apparatusId: $apparatusId,
                state: 'not_checked',
                requirement: 'required',
                outOfService: false,
                classificationRequired: false,
                includedInRequiredTotal: true,
                includedInCompleted: false,
                hasPendingSubmission: false,
                returnCheckoutRequired: $checkoutRequiredAfterReturn,
                returnCheckoutVerified: false,
            );
        }

        $completed = $checked + $attention;
        $completionAvailable = $requiredTotal > 0;
        $completionPercent = $completionAvailable
            ? round(($completed / $requiredTotal) * 100, 1)
            : null;

        return [
            // Canonical contract. New consumers must use these keys.
            'required_total' => $requiredTotal,
            'checked' => $checked,
            'attention' => $attention,
            'review_pending' => $reviewPending,
            'not_checked' => $notChecked,
            'completed' => $completed,
            'out_of_service' => $outOfService,
            'exempt' => $exempt,
            'classification_required' => $classificationRequired,
            'completion_percent' => $completionPercent,
            'completion_available' => $completionAvailable,
            'pending_submission_count' => $pendingSubmissionCount,
            'return_checkout_required_count' => $returnCheckoutRequired,
            'matrix' => $matrix,

            // Temporary compatibility aliases. These deliberately keep the
            // former public surface stable while consumers migrate.
            'required_count' => $requiredTotal,
            'completed_required_count' => $completed,
            'checked_count' => $checked,
            'attention_count' => $attention,
            'review_pending_count' => $reviewPending,
            'not_checked_count' => $notChecked,
            'missing_required_count' => $notChecked,
            'exempt_count' => $explicitExempt,
            'reserve_count' => $reserve,
            'administrative_count' => $administrative,
            'inactive_count' => $inactive,
            'not_required_count' => $notRequired,
            'unknown_count' => $classificationRequired,
            'out_of_service_count' => $outOfService,
        ];
    }

    /**
     * @param  Collection<int, Apparatus>  $apparatuses
     * @return array<int, array{latest_approved_completed_at: ?CarbonImmutable, has_pending_submission: bool}>
     */
    private function inspectionSignals(
        Collection $apparatuses,
        CarbonImmutable $startOfDay,
        CarbonImmutable $startOfNextDay,
    ): array {
        $apparatusIds = $this->apparatusIds($apparatuses);
        if ($apparatusIds === []) {
            return [];
        }

        $signals = [];
        $inspections = ApparatusInspection::query()
            ->whereIn('apparatus_id', $apparatusIds)
            // This is an intentional data-integrity cutover. Historical rows
            // predate server-side checklist reconciliation and therefore cannot
            // silently satisfy the canonical Daily Checkout signal.
            ->whereNotNull('client_submission_id')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $startOfDay)
            ->where('completed_at', '<', $startOfNextDay)
            ->get(['apparatus_id', 'review_status', 'completed_at']);

        foreach ($inspections as $inspection) {
            $apparatusId = (int) $inspection->apparatus_id;
            $signals[$apparatusId] ??= [
                'latest_approved_completed_at' => null,
                'has_pending_submission' => false,
            ];
            $reviewStatus = strtolower((string) $inspection->review_status);
            if ($reviewStatus === 'pending_review') {
                $signals[$apparatusId]['has_pending_submission'] = true;

                continue;
            }

            if ($reviewStatus !== 'approved') {
                continue;
            }

            $completedAt = $this->asImmutable($inspection->completed_at);
            $latestApprovedAt = $signals[$apparatusId]['latest_approved_completed_at'];
            if ($completedAt !== null && ($latestApprovedAt === null || $completedAt->greaterThan($latestApprovedAt))) {
                $signals[$apparatusId]['latest_approved_completed_at'] = $completedAt;
            }
        }

        return $signals;
    }

    /**
     * Determines whether an in-service apparatus returned from OOS during the
     * local day. Only the append-only status ledger is authoritative here: a
     * generic apparatus updated_at may be a notes, meter, or policy edit and
     * must never be misclassified as an operational-status transition.
     *
     * @param  Collection<int, Apparatus>  $apparatuses
     * @return array<int, array{return_checkout_required: bool, return_checkout_cutoff: ?CarbonImmutable}>
     */
    private function statusTransitionSignals(
        Collection $apparatuses,
        CarbonImmutable $startOfDay,
        CarbonImmutable $startOfNextDay,
    ): array {
        $apparatusIds = $this->apparatusIds($apparatuses);
        if ($apparatusIds === []) {
            return [];
        }

        $eventsByApparatus = ApparatusOperationalStatusEvent::query()
            ->whereIn('apparatus_id', $apparatusIds)
            ->where('changed_at', '<', $startOfNextDay)
            ->orderBy('apparatus_id')
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['id', 'apparatus_id', 'previous_status', 'status', 'changed_at'])
            ->groupBy('apparatus_id');

        $signals = [];
        foreach ($apparatuses->unique(fn (Apparatus $apparatus): int => (int) $apparatus->id) as $apparatus) {
            $apparatusId = (int) $apparatus->id;
            $openOutOfServiceEpisodeAt = null;
            $returnCheckoutCutoff = null;

            foreach ($eventsByApparatus->get($apparatusId, collect()) as $event) {
                $changedAt = $this->asImmutable($event->changed_at);
                if ($changedAt === null) {
                    continue;
                }

                $previousStatusWasOutOfService = $this->isOutOfService((string) $event->previous_status);
                if ($this->isOutOfService((string) $event->status)) {
                    $openOutOfServiceEpisodeAt = $changedAt;
                    $returnCheckoutCutoff = null;

                    continue;
                }

                // The first event after this ledger is introduced can itself
                // be the OOS -> operational return. Its previous_status is
                // authoritative even though no older ledger row exists.
                if ($previousStatusWasOutOfService) {
                    $openOutOfServiceEpisodeAt ??= $changedAt;
                }

                if (
                    $openOutOfServiceEpisodeAt !== null
                    && $this->isInService((string) $event->status)
                ) {
                    if ($changedAt->greaterThanOrEqualTo($startOfDay)) {
                        $returnCheckoutCutoff = $changedAt;
                    }

                    // Once the apparatus reaches an operational state, the
                    // OOS episode is closed. Later ordinary status edits must
                    // never be mistaken for another return-to-service event.
                    $openOutOfServiceEpisodeAt = null;
                }
            }

            $currentStatusIsInService = $this->isInService((string) $apparatus->getAttribute('status'));
            $signals[$apparatusId] = [
                'return_checkout_required' => $currentStatusIsInService && $returnCheckoutCutoff !== null,
                'return_checkout_cutoff' => $returnCheckoutCutoff,
            ];
        }

        return $signals;
    }

    /**
     * @param  Collection<int, Apparatus>  $apparatuses
     * @return list<int>
     */
    private function unresolvedCriticalDefectApparatusIds(Collection $apparatuses): array
    {
        $apparatusIds = $this->apparatusIds($apparatuses);
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

    /**
     * @return array{apparatus_id: int, state: string, daily_checkout_requirement: string, out_of_service: bool, classification_required: bool, included_in_required_total: bool, included_in_completed: bool, has_pending_submission: bool, return_checkout_required: bool, return_checkout_verified: bool}
     */
    private function matrixRow(
        int $apparatusId,
        string $state,
        string $requirement,
        bool $outOfService,
        bool $classificationRequired,
        bool $includedInRequiredTotal,
        bool $includedInCompleted,
        bool $hasPendingSubmission,
        bool $returnCheckoutRequired,
        bool $returnCheckoutVerified,
    ): array {
        return [
            'apparatus_id' => $apparatusId,
            'state' => $state,
            'daily_checkout_requirement' => $requirement,
            'out_of_service' => $outOfService,
            'classification_required' => $classificationRequired,
            'included_in_required_total' => $includedInRequiredTotal,
            'included_in_completed' => $includedInCompleted,
            'has_pending_submission' => $hasPendingSubmission,
            'return_checkout_required' => $returnCheckoutRequired,
            'return_checkout_verified' => $returnCheckoutVerified,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function localDayWindow(?CarbonImmutable $now): array
    {
        $localNow = ($now ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $startOfDay = $localNow->startOfDay();

        return [$startOfDay->utc(), $startOfDay->addDay()->utc()];
    }

    /**
     * @param  Collection<int, Apparatus>  $apparatuses
     * @return list<int>
     */
    private function apparatusIds(Collection $apparatuses): array
    {
        return $apparatuses
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function requirementValue(Apparatus $apparatus): string
    {
        $value = $apparatus->getRawOriginal('daily_checkout_requirement');
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['required', 'exempt', 'reserve', 'administrative', 'inactive', 'unknown'], true)
            ? $normalized
            : 'unknown';
    }

    private function isOutOfService(string $status): bool
    {
        $normalized = $this->normalizedStatus($status);

        return in_array($normalized, ['out_of_service', 'oos', 'down', 'retired'], true);
    }

    private function isInService(string $status): bool
    {
        return in_array($this->normalizedStatus($status), ['in_service', 'active', 'available', 'ready'], true);
    }

    private function normalizedStatus(string $status): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($status)));
    }

    private function asImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return null;
    }
}
