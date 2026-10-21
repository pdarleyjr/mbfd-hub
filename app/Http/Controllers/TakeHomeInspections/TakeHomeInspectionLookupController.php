<?php

declare(strict_types=1);

namespace App\Http\Controllers\TakeHomeInspections;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TakeHomeVehicleInspection;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Services\Fleet\UnitMasterVehicleChangeRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Narrow lookups for the inspection form. Results carry only what the form needs
 * (identity fields, plate and a short assignment label), never the full roster
 * record, and employee results carry only name and employee ID.
 */
final class TakeHomeInspectionLookupController extends Controller
{
    private const VEHICLE_LIMIT = 12;

    private const EMPLOYEE_LIMIT = 10;

    public function vehicles(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return $this->json(['results' => [], 'exact' => []]);
        }
        $term = mb_substr($term, 0, 64);
        $upper = mb_strtoupper($term);
        $like = '%'.$this->escapeLike(mb_strtolower($term)).'%';

        $exact = UnitMasterVehicle::query()
            ->whereRaw('UPPER(TRIM(veh_number)) = ?', [$upper])
            ->orderBy('id')
            ->get();

        $results = UnitMasterVehicle::query()
            ->where(function (Builder $query) use ($like): void {
                foreach (['veh_number', 'tag_number', 'make', 'model', 'serial_number', 'employee_or_vehicle_name'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$like]);
                }
            })
            ->orderByRaw('CASE WHEN UPPER(TRIM(veh_number)) = ? THEN 0 WHEN UPPER(veh_number) LIKE ? THEN 1 ELSE 2 END', [$upper, $this->escapeLike($upper).'%'])
            ->orderBy('veh_number')
            ->limit(self::VEHICLE_LIMIT)
            ->get();

        return $this->json([
            'results' => $results->map(fn (UnitMasterVehicle $vehicle): array => $this->summary($vehicle))->values(),
            'exact' => $exact->map(fn (UnitMasterVehicle $vehicle): array => $this->summary($vehicle))->values(),
        ]);
    }

    public function vehicle(UnitMasterVehicle $vehicle): JsonResponse
    {
        return $this->json(['vehicle' => $this->detail($vehicle)]);
    }

    /** Add a vehicle that is not on the roster yet, from inside the inspection flow. */
    public function storeVehicle(Request $request, UnitMasterVehicleChangeRecorder $recorder): JsonResponse
    {
        $data = $request->validate([
            'veh_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9\- ]*$/'],
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'color' => ['nullable', 'string', 'max:64'],
            'tag_number' => ['nullable', 'string', 'max:32'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'asset_category' => ['nullable', Rule::in(array_keys(UnitMasterVehicle::CATEGORY_LABELS))],
        ], [
            'veh_number.regex' => 'Use letters, numbers and dashes for the vehicle number.',
            'year.regex' => 'Enter a four-digit model year.',
        ]);
        $data = array_map(fn ($value) => is_string($value) ? (trim(preg_replace('/\s+/u', ' ', $value) ?? '') ?: null) : $value, $data);

        return DB::transaction(function () use ($data, $request, $recorder): JsonResponse {
            if (DB::connection()->getDriverName() === 'pgsql') {
                // Serialize concurrent adds of the same number (veh_number is indexed, not unique).
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['thvi-veh:'.mb_strtoupper((string) $data['veh_number'])]);
            }
            $existing = UnitMasterVehicle::query()
                ->whereRaw('UPPER(TRIM(veh_number)) = ?', [mb_strtoupper((string) $data['veh_number'])])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $this->json([
                    'message' => "Vehicle #{$existing->veh_number} is already on the roster. Select it instead.",
                    'vehicle' => $this->detail($existing),
                ], 409);
            }
            if (! empty($data['serial_number'])) {
                $vinOwner = UnitMasterVehicle::query()
                    ->whereRaw('UPPER(TRIM(serial_number)) = ?', [mb_strtoupper((string) $data['serial_number'])])
                    ->first();
                if ($vinOwner !== null) {
                    return $this->json([
                        'message' => "That VIN / serial is already recorded on vehicle #{$vinOwner->veh_number}. Check the number, or select that vehicle.",
                        'vehicle' => $this->detail($vinOwner),
                    ], 409);
                }
            }

            $vehicle = UnitMasterVehicle::query()->create([
                'veh_number' => $data['veh_number'],
                'make' => $data['make'],
                'model' => $data['model'],
                'year' => $data['year'] ?? null,
                'color' => $data['color'] ?? null,
                'tag_number' => $data['tag_number'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'asset_category' => $data['asset_category'] ?? UnitMasterVehicle::CATEGORY_VEHICLE,
                'needs_fleet_review' => true,
                'record_source' => UnitMasterVehicleChange::SOURCE_TAKE_HOME_NEW,
            ]);
            $recorder->recordCreated($vehicle, UnitMasterVehicleChange::SOURCE_TAKE_HOME_NEW, [
                'user' => $request->user(),
                'note' => 'Added during a Take Home Vehicle Inspection; awaiting Fleet verification.',
            ]);

            return $this->json(['vehicle' => $this->detail($vehicle)], 201);
        });
    }

    public function employees(Request $request): JsonResponse
    {
        $term = mb_substr(trim((string) $request->query('q', '')), 0, 64);
        if (mb_strlen($term) < 2) {
            return $this->json(['results' => []]);
        }
        $like = '%'.$this->escapeLike(mb_strtolower($term)).'%';
        $prefix = $this->escapeLike(mb_strtolower($term)).'%';

        $employees = Employee::query()
            ->where(fn (Builder $query) => $query->where('roster_status', 'active')->orWhereNull('roster_status'))
            ->where(function (Builder $query) use ($like, $prefix): void {
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(display_name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(employee_id) LIKE ? ESCAPE '!'", [$prefix]);
            })
            ->orderBy('name')
            ->limit(self::EMPLOYEE_LIMIT)
            ->get(['id', 'name', 'display_name', 'employee_id']);

        return $this->json([
            'results' => $employees->map(fn (Employee $employee): array => $this->employeePayload($employee))->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(UnitMasterVehicle $vehicle): array
    {
        return [
            'id' => $vehicle->getKey(),
            'veh_number' => $vehicle->veh_number,
            'label' => trim(implode(' ', array_filter([$vehicle->year, $vehicle->make, $vehicle->model]))) ?: 'Vehicle details not recorded',
            'tag_number' => $vehicle->tag_number,
            'assigned' => $vehicle->employee_or_vehicle_name,
            'category' => $vehicle->category(),
            'category_label' => $vehicle->categoryLabel(),
            'nonroad' => $vehicle->isNonroad(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(UnitMasterVehicle $vehicle): array
    {
        $driver = $vehicle->currentDriver;
        $lastOdometer = TakeHomeVehicleInspection::query()
            ->where('unit_master_vehicle_id', $vehicle->getKey())
            ->whereNotNull('odometer')
            ->latest('submitted_at')
            ->value('odometer');

        return $this->summary($vehicle) + [
            'fields' => [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'color' => $vehicle->color,
                'tag_number' => $vehicle->tag_number,
                'serial_number' => $vehicle->serial_number,
                'dept_code' => $vehicle->dept_code,
            ],
            'needs_fleet_review' => (bool) $vehicle->needs_fleet_review,
            'current_driver' => $driver instanceof Employee ? $this->employeePayload($driver) : null,
            'last_odometer' => $lastOdometer !== null ? (int) $lastOdometer : null,
        ];
    }

    /** @return array{id: int, name: string, employee_id: ?string} */
    private function employeePayload(Employee $employee): array
    {
        return [
            'id' => (int) $employee->getKey(),
            'name' => (string) ($employee->display_name ?: $employee->name),
            'employee_id' => filled($employee->employee_id) ? (string) $employee->employee_id : null,
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
