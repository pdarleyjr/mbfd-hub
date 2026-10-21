<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Employee;
use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Services\PersonnelRequests\SignatureImageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Writes one immutable Take Home Vehicle Inspection.
 *
 * Idempotent on client_submission_id (double taps, retries and refreshes return
 * the original record). Vehicle details the inspector edited are synced to the
 * master roster when they fill an empty field and held as proposed corrections
 * for Fleet when they would replace an existing value. Nothing about the
 * correction blocks the inspection.
 */
final class TakeHomeInspectionSubmitter
{
    /** Master fields an inspector may fill or propose corrections to. */
    public const EDITABLE_FIELDS = ['make', 'model', 'year', 'color', 'tag_number', 'serial_number', 'dept_code'];

    public function __construct(
        private readonly UnitMasterVehicleChangeRecorder $recorder,
        private readonly SignatureImageService $signatures,
    ) {}

    /** @param array<string, mixed> $data validated StoreTakeHomeInspectionRequest data */
    public function submit(User $user, array $data): TakeHomeVehicleInspection
    {
        $existing = TakeHomeVehicleInspection::query()->where('client_submission_id', $data['client_submission_id'])->first();
        if ($existing !== null) {
            return $this->replay($existing, $user);
        }

        $photoIds = $this->photoUuids($data);
        $this->assertPhotosReady($user, $photoIds);
        $signature = $this->signatures->store((string) $data['signature'], 'take-home-inspections');

        try {
            return DB::transaction(function () use ($user, $data, $photoIds, $signature): TakeHomeVehicleInspection {
                $vehicle = UnitMasterVehicle::query()->lockForUpdate()->findOrFail((int) $data['vehicle_id']);
                // Re-check inside the lock so a concurrent retry cannot attach the same photos twice.
                $photos = $this->lockPhotos($user, $photoIds);
                $driver = $this->resolveDriver($data);
                [$odometerFlagged, $odometerNote] = $this->odometerCheck($vehicle, $data);
                $checks = $this->normalizedChecks($data);
                $submitted = $this->submittedVehicleValues($vehicle, $data);
                $now = CarbonImmutable::now('UTC');

                $inspection = TakeHomeVehicleInspection::query()->create([
                    'reference' => $this->newReference($now),
                    'client_submission_id' => $data['client_submission_id'],
                    'unit_master_vehicle_id' => $vehicle->getKey(),
                    'inspector_user_id' => $user->getKey(),
                    'inspector_name' => $this->inspectorName($user),
                    'driver_employee_id' => $driver['id'],
                    'driver_name' => $driver['name'],
                    'driver_employee_number' => $driver['number'],
                    'driver_verified' => $driver['verified'],
                    'vehicle_snapshot' => $this->snapshot($vehicle, $submitted, $driver),
                    'odometer' => $data['odometer'] ?? null,
                    'odometer_exception' => ($data['odometer'] ?? null) === null ? ($data['odometer_exception'] ?? null) : null,
                    'odometer_flagged' => $odometerFlagged,
                    'odometer_note' => $odometerNote,
                    'checks' => $checks,
                    'has_issues' => collect($checks)->contains('status', TakeHomeVehicleInspection::STATUS_ISSUE),
                    'has_not_checked' => collect($checks)->contains('status', TakeHomeVehicleInspection::STATUS_NOT_CHECKED),
                    'keys_present' => (bool) $data['keys_present'],
                    'acceptable_for_use' => (bool) $data['acceptable_for_use'],
                    'comments' => $this->text($data['comments'] ?? null),
                    'acknowledged_at' => $now,
                    'signature_path' => $signature['path'],
                    'signature_sha256' => $signature['sha256'],
                    'started_at' => $this->startedAt($data['started_at'] ?? null, $now),
                    'submitted_at' => $now,
                ]);

                foreach ($photos as $photo) {
                    $photo->forceFill(['take_home_vehicle_inspection_id' => $inspection->getKey()])->save();
                }

                $this->syncVehicle($vehicle, $submitted, $driver, $user, $inspection);

                return $inspection;
            });
        } catch (\Throwable $exception) {
            Storage::disk($signature['disk'])->delete($signature['path']);

            // A concurrent duplicate of the same submission won the unique index: return it.
            $winner = TakeHomeVehicleInspection::query()->where('client_submission_id', $data['client_submission_id'])->first();
            if ($winner !== null) {
                return $this->replay($winner, $user);
            }

            throw $exception;
        }
    }

    private function replay(TakeHomeVehicleInspection $existing, User $user): TakeHomeVehicleInspection
    {
        if ((int) $existing->inspector_user_id !== (int) $user->getKey()) {
            throw ValidationException::withMessages(['client_submission_id' => 'This inspection could not be submitted. Please start a new inspection.']);
        }

        return $existing;
    }

    /** @return array<string, array{angle: string, check_key: ?string}> */
    private function photoUuids(array $data): array
    {
        $ids = [];
        $references = 0;
        foreach (array_keys(TakeHomeVehicleInspection::PHOTO_ANGLES) as $angle) {
            $ids[(string) $data['photos'][$angle]] = ['angle' => $angle, 'check_key' => null];
            $references++;
        }
        foreach (array_keys(TakeHomeVehicleInspection::CHECKS) as $key) {
            if (($data['checks'][$key]['status'] ?? null) !== TakeHomeVehicleInspection::STATUS_ISSUE) {
                continue; // Photos for a check the inspector changed back to Good are simply not attached.
            }
            foreach ((array) ($data['issue_photos'][$key] ?? []) as $uuid) {
                $ids[(string) $uuid] = ['angle' => TakeHomeVehicleInspection::ISSUE_ANGLE, 'check_key' => $key];
                $references++;
            }
        }
        if (count($ids) !== $references) {
            throw ValidationException::withMessages(['photos' => 'Each photo can be used only once. Retake the repeated photo.']);
        }

        return $ids;
    }

    /** @param array<string, array{angle: string, check_key: ?string}> $ids */
    private function assertPhotosReady(User $user, array $ids): void
    {
        $this->matchingPhotos($user, $ids, false);
    }

    /** @return \Illuminate\Support\Collection<int, TakeHomeVehicleInspectionPhoto> */
    private function lockPhotos(User $user, array $ids)
    {
        return $this->matchingPhotos($user, $ids, true);
    }

    private function matchingPhotos(User $user, array $ids, bool $lock)
    {
        $query = TakeHomeVehicleInspectionPhoto::query()
            ->whereIn('uuid', array_keys($ids))
            ->where('uploaded_by_user_id', $user->getKey())
            ->whereNull('take_home_vehicle_inspection_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $photos = $query->get()->keyBy('uuid');
        $disk = Storage::disk(TakeHomeInspectionPhotoStore::disk());

        $errors = [];
        foreach ($ids as $uuid => $expected) {
            $photo = $photos->get($uuid);
            $field = $expected['check_key'] === null ? 'photos.'.$expected['angle'] : 'issue_photos.'.$expected['check_key'];
            $valid = $photo !== null
                && $photo->angle === $expected['angle']
                && $photo->check_key === $expected['check_key']
                && $disk->exists($photo->path);
            if (! $valid) {
                $label = $expected['check_key'] === null
                    ? TakeHomeVehicleInspection::PHOTO_ANGLES[$expected['angle']]
                    : 'issue photo for '.TakeHomeVehicleInspection::CHECKS[$expected['check_key']][0];
                $errors[$field] = "The {$label} did not finish uploading. Take or choose it again.";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $photos->values();
    }

    /** @return array{id: ?int, name: string, number: ?string, verified: bool} */
    private function resolveDriver(array $data): array
    {
        if (! empty($data['driver_employee_id'])) {
            $employee = Employee::query()->findOrFail((int) $data['driver_employee_id']);

            return [
                'id' => (int) $employee->getKey(),
                'name' => (string) ($employee->display_name ?: $employee->name),
                'number' => filled($employee->employee_id) ? (string) $employee->employee_id : null,
                'verified' => true,
            ];
        }

        return [
            'id' => null,
            'name' => (string) $this->clean($data['driver_name'] ?? null),
            'number' => $this->clean($data['driver_employee_number'] ?? null),
            'verified' => false,
        ];
    }

    /** @return array{0: bool, 1: ?string} */
    private function odometerCheck(UnitMasterVehicle $vehicle, array $data): array
    {
        $reading = $data['odometer'] ?? null;
        if ($reading === null) {
            return [false, null];
        }
        $previous = TakeHomeVehicleInspection::query()
            ->where('unit_master_vehicle_id', $vehicle->getKey())
            ->whereNotNull('odometer')
            ->latest('submitted_at')
            ->first(['odometer', 'submitted_at', 'reference']);
        if ($previous !== null && (int) $reading < (int) $previous->odometer) {
            return [true, sprintf(
                'Lower than the previous reading of %s mi on %s (%s).',
                number_format((int) $previous->odometer),
                $previous->submitted_at->clone()->timezone('America/New_York')->format('M j, Y'),
                $previous->reference,
            )];
        }

        return [false, null];
    }

    /** @return list<array{key: string, label: string, status: string, issue_description: ?string, notes: ?string}> */
    private function normalizedChecks(array $data): array
    {
        $checks = [];
        foreach (TakeHomeVehicleInspection::CHECKS as $key => [$label]) {
            $status = (string) $data['checks'][$key]['status'];
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

    /** @return array<string, ?string> */
    private function submittedVehicleValues(UnitMasterVehicle $vehicle, array $data): array
    {
        $values = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            $values[$field] = array_key_exists($field, $data['vehicle'] ?? [])
                ? $this->clean($data['vehicle'][$field])
                : $this->clean($vehicle->getAttribute($field));
        }

        return $values;
    }

    /** Snapshot of the vehicle exactly as the inspector attested to it. */
    private function snapshot(UnitMasterVehicle $vehicle, array $submitted, array $driver): array
    {
        return array_merge([
            'id' => (int) $vehicle->getKey(),
            'veh_number' => $vehicle->veh_number,
        ], $submitted, [
            'asset_category' => $vehicle->category(),
            'category_label' => $vehicle->categoryLabel(),
            'fleet_class_description' => $vehicle->fleet_class_description,
            'employee_or_vehicle_name' => $vehicle->employee_or_vehicle_name,
            'section' => $vehicle->section,
            'current_driver' => $driver['name'],
            'needs_fleet_review' => (bool) $vehicle->needs_fleet_review,
        ]);
    }

    private function syncVehicle(UnitMasterVehicle $vehicle, array $submitted, array $driver, User $user, TakeHomeVehicleInspection $inspection): void
    {
        $context = [
            'source_reference' => $inspection->reference,
            'user' => $user,
            'inspection_id' => $inspection->getKey(),
        ];

        foreach ($submitted as $field => $value) {
            $current = $this->clean($vehicle->getAttribute($field));
            if ($value === null || $this->sameValue($current, $value)) {
                continue; // A cleared field never erases master data.
            }

            $conflict = $this->identifierConflict($vehicle, $field, $value);
            if ($conflict !== null) {
                $this->recorder->propose($vehicle, $field, $value, UnitMasterVehicleChange::SOURCE_TAKE_HOME, $context + ['note' => $conflict]);

                continue;
            }

            if ($current === null || strtoupper((string) $current) === 'N/A') {
                $this->recorder->apply($vehicle, $field, $value, UnitMasterVehicleChange::SOURCE_TAKE_HOME, $context);
            } else {
                $this->recorder->propose($vehicle, $field, $value, UnitMasterVehicleChange::SOURCE_TAKE_HOME, $context);
            }
        }

        if ($driver['verified'] && $driver['id'] !== null && (int) $vehicle->current_driver_employee_id !== $driver['id']) {
            if ($vehicle->current_driver_employee_id === null) {
                $this->recorder->apply($vehicle, 'current_driver_employee_id', (string) $driver['id'], UnitMasterVehicleChange::SOURCE_TAKE_HOME, $context + ['note' => $driver['name']]);
            } else {
                $this->recorder->propose($vehicle, 'current_driver_employee_id', (string) $driver['id'], UnitMasterVehicleChange::SOURCE_TAKE_HOME, $context + ['note' => 'Reported driver: '.$driver['name']]);
            }
        }
    }

    /** A plate or VIN already recorded on another vehicle is never applied, only flagged. */
    private function identifierConflict(UnitMasterVehicle $vehicle, string $field, string $value): ?string
    {
        if (! in_array($field, ['serial_number', 'tag_number'], true) || strtoupper($value) === 'N/A' || strtoupper($value) === 'OFFROAD') {
            return null;
        }
        $other = UnitMasterVehicle::query()
            ->whereKeyNot($vehicle->getKey())
            ->whereRaw('UPPER(TRIM('.$field.')) = ?', [strtoupper($value)])
            ->value('veh_number');

        return $other === null ? null : sprintf('%s is already recorded on vehicle #%s.', UnitMasterVehicle::FIELD_LABELS[$field], $other);
    }

    private function sameValue(?string $a, ?string $b): bool
    {
        return strtoupper((string) $a) === strtoupper((string) $b);
    }

    private function startedAt(?string $clientValue, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($clientValue === null || $clientValue === '') {
            return null;
        }
        try {
            $started = CarbonImmutable::parse($clientValue)->utc();
        } catch (\Throwable) {
            return null;
        }

        // Never trust a browser clock beyond a sane window.
        return $started->betweenIncluded($now->subDay(), $now) ? $started : null;
    }

    private function newReference(CarbonImmutable $now): string
    {
        do {
            $reference = 'THI-'.$now->timezone('America/New_York')->format('ymd').'-'.Str::upper(Str::random(6));
            $reference = preg_replace('/[^A-Z0-9\-]/', 'X', $reference);
        } while (TakeHomeVehicleInspection::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function inspectorName(User $user): string
    {
        return (string) ($user->display_name ?: $user->name ?: $user->email);
    }

    /** Free text keeps its line breaks; only surrounding whitespace is removed. */
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
