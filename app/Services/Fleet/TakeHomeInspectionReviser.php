<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionRevision;
use App\Models\User;
use App\Support\Fleet\TakeHomeInspectionAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a member's correction to their own submitted Take Home Vehicle Inspection.
 *
 * The signed inspection row is never changed. Each save writes one immutable
 * revision holding what changed (before and after), the complete corrected answers
 * and who made it. Idempotent on client_revision_id, so a double tap or a retry
 * records one revision. Saving with nothing changed records nothing.
 *
 * The vehicle, its roster details, photos and the signature are not part of a
 * correction: they feed the master roster and the signed record.
 */
final class TakeHomeInspectionReviser
{
    /** Flat answer keys compared between versions, in display order. */
    private const COMPARED = ['driver_name', 'driver_employee_number', 'odometer', 'odometer_exception', 'keys_present', 'acceptable_for_use', 'comments'];

    /** @param array<string, mixed> $data validated UpdateTakeHomeInspectionRequest data */
    public function revise(User $user, TakeHomeVehicleInspection $inspection, array $data): ?TakeHomeVehicleInspectionRevision
    {
        $existing = $this->replay($user, $inspection, (string) $data['client_revision_id']);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $inspection, $data): ?TakeHomeVehicleInspectionRevision {
                /** @var TakeHomeVehicleInspection $locked */
                $locked = TakeHomeVehicleInspection::query()->lockForUpdate()->findOrFail($inspection->getKey());
                if (! TakeHomeInspectionAccess::canEdit($user, $locked)) {
                    throw new AuthorizationException($locked->isArchived()
                        ? 'Fleet has cleared this inspection, so it can no longer be changed.'
                        : 'Only the member who submitted this inspection can change it.');
                }

                $current = $locked->load('latestRevision')->effective()->answers();
                $next = $this->correctedAnswers($locked, $current, $data);
                $changes = $this->changes($current, $next);
                if ($changes === []) {
                    return null;
                }

                $number = (int) TakeHomeVehicleInspectionRevision::query()
                    ->where('take_home_vehicle_inspection_id', $locked->getKey())
                    ->max('revision_number') + 1;

                return TakeHomeVehicleInspectionRevision::query()->create([
                    'take_home_vehicle_inspection_id' => $locked->getKey(),
                    'revision_number' => $number,
                    'revised_by_user_id' => $user->getKey(),
                    'revised_by_name' => (string) ($user->display_name ?: $user->name ?: $user->email),
                    'client_revision_id' => $data['client_revision_id'],
                    'changed_fields' => $changes,
                    'answers' => $next,
                    'reason' => $this->text($data['reason'] ?? null),
                    'added_problem' => $this->addsProblem($current, $next),
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent retry of the same save won the unique index: return its revision.
            return $this->replay($user, $inspection, (string) $data['client_revision_id']) ?? throw $exception;
        }
    }

    private function replay(User $user, TakeHomeVehicleInspection $inspection, string $clientRevisionId): ?TakeHomeVehicleInspectionRevision
    {
        $existing = TakeHomeVehicleInspectionRevision::query()->where('client_revision_id', $clientRevisionId)->first();
        if ($existing === null) {
            return null;
        }
        if ((int) $existing->take_home_vehicle_inspection_id !== (int) $inspection->getKey()
            || (int) $existing->revised_by_user_id !== (int) $user->getKey()) {
            throw ValidationException::withMessages(['client_revision_id' => 'These changes could not be saved. Open the inspection and edit it again.']);
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function correctedAnswers(TakeHomeVehicleInspection $inspection, array $current, array $data): array
    {
        $driverName = $this->clean($data['driver_name'] ?? null);
        $driverNumber = $this->clean($data['driver_employee_number'] ?? null);
        $sameDriver = $driverName === $current['driver_name'] && $driverNumber === $current['driver_employee_number'];

        $odometer = isset($data['odometer']) ? (int) $data['odometer'] : null;
        $exception = $odometer === null ? ($data['odometer_exception'] ?? null) : null;
        [$flagged, $note] = $odometer === ($current['odometer'] === null ? null : (int) $current['odometer'])
            ? [(bool) $current['odometer_flagged'], $current['odometer_note']]
            : $this->odometerCheck($inspection, $odometer);

        $checks = $this->checks($data);

        return [
            // A changed driver is a typed name: it is no longer the verified directory record.
            'driver_employee_id' => $sameDriver ? $current['driver_employee_id'] : null,
            'driver_name' => $driverName,
            'driver_employee_number' => $driverNumber,
            'driver_verified' => $sameDriver ? (bool) $current['driver_verified'] : false,
            'odometer' => $odometer,
            'odometer_exception' => $exception,
            'odometer_flagged' => $flagged,
            'odometer_note' => $note,
            'checks' => $checks,
            'has_issues' => collect($checks)->contains('status', TakeHomeVehicleInspection::STATUS_ISSUE),
            'has_not_checked' => collect($checks)->contains(fn (array $check): bool => ! in_array($check['status'], [TakeHomeVehicleInspection::STATUS_GOOD, TakeHomeVehicleInspection::STATUS_ISSUE], true)),
            'keys_present' => isset($data['keys_present']) ? (bool) $data['keys_present'] : null,
            'acceptable_for_use' => isset($data['acceptable_for_use']) ? (bool) $data['acceptable_for_use'] : null,
            'comments' => $this->text($data['comments'] ?? null),
        ];
    }

    /** @return list<array{key: string, label: string, status: ?string, issue_description: ?string, notes: ?string}> */
    private function checks(array $data): array
    {
        $checks = [];
        foreach (TakeHomeVehicleInspection::CHECKS as $key => [$label]) {
            $status = $data['checks'][$key]['status'] ?? null;
            $checks[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'issue_description' => $status === TakeHomeVehicleInspection::STATUS_ISSUE
                    ? $this->text($data['checks'][$key]['issue_description'] ?? null)
                    : null,
                'notes' => $this->text($data['checks'][$key]['notes'] ?? null),
            ];
        }

        return $checks;
    }

    /** The same rule as at submission: lower than the vehicle's previous reading is flagged. */
    private function odometerCheck(TakeHomeVehicleInspection $inspection, ?int $reading): array
    {
        if ($reading === null) {
            return [false, null];
        }
        $previous = TakeHomeVehicleInspection::query()
            ->where('unit_master_vehicle_id', $inspection->unit_master_vehicle_id)
            ->whereKeyNot($inspection->getKey())
            ->where('submitted_at', '<', $inspection->submitted_at)
            ->whereNotNull('odometer')
            ->latest('submitted_at')
            ->first(['odometer', 'submitted_at', 'reference']);
        if ($previous !== null && $reading < (int) $previous->odometer) {
            return [true, sprintf(
                'Lower than the previous reading of %s mi on %s (%s).',
                number_format((int) $previous->odometer),
                $previous->submitted_at->clone()->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y'),
                $previous->reference,
            )];
        }

        return [false, null];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(array $before, array $after): array
    {
        $from = $this->flatten($before);
        $to = $this->flatten($after);
        $changes = [];
        foreach ($to as $field => $value) {
            if (($from[$field] ?? null) !== $value) {
                $changes[$field] = ['from' => $from[$field] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private function flatten(array $answers): array
    {
        $flat = [];
        foreach (self::COMPARED as $field) {
            $value = $answers[$field] ?? null;
            $flat[$field] = match ($field) {
                'odometer' => $value === null ? null : (int) $value,
                'keys_present', 'acceptable_for_use' => $value === null ? null : (bool) $value,
                default => $value === null || $value === '' ? null : (string) $value,
            };
        }
        $byKey = collect((array) ($answers['checks'] ?? []))->keyBy('key');
        foreach (array_keys(TakeHomeVehicleInspection::CHECKS) as $key) {
            foreach (['status', 'issue_description', 'notes'] as $part) {
                $value = $byKey->get($key)[$part] ?? null;
                $flat["checks.{$key}.{$part}"] = $value === null || $value === '' ? null : (string) $value;
            }
        }

        return $flat;
    }

    /** A new Issue found, a new "not acceptable for use", or a newly flagged odometer. */
    private function addsProblem(array $before, array $after): bool
    {
        $issues = fn (array $answers): array => collect((array) ($answers['checks'] ?? []))
            ->where('status', TakeHomeVehicleInspection::STATUS_ISSUE)->pluck('key')->all();

        return array_diff($issues($after), $issues($before)) !== []
            || ($after['acceptable_for_use'] === false && $before['acceptable_for_use'] !== false)
            || ($after['odometer_flagged'] && ! $before['odometer_flagged']);
    }

    private function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }
}
