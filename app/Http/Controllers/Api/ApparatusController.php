<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusDefect;
use App\Models\Employee;
use App\Jobs\AuditEquipmentAfterInspection;
use App\Jobs\PmAlertNotificationJob;
use App\Support\Security\Base64Image;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApparatusController extends Controller
{
    public function index()
    {
        $apparatuses = Apparatus::all()->map(function ($apparatus) {
            $data = $apparatus->toArray();
            $data['pm_health'] = $apparatus->getPmHealthStatus();
            return $data;
        });

        return response()->json($apparatuses);
    }

    public function checklist($id)
    {
        $apparatus = Apparatus::findOrFail($id);

        // Determine checklist file based on apparatus type and designation
        $checklistType = 'default';
        if ($apparatus->type) {
            $type = strtolower($apparatus->type);
            if (str_contains($type, 'engine')) {
                // E2 has specialized equipment (Paratech, TNT tools, etc.)
                $designation = strtolower($apparatus->designation ?? '');
                $name = strtolower($apparatus->name ?? '');
                if (preg_match('/e\s*2\b/', $designation) || preg_match('/e\s*2\b/', $name)) {
                    $checklistType = 'engine2';
                } else {
                    $checklistType = 'engine';
                }
            } elseif (str_contains($type, 'ladder')) {
                // Use designation to differentiate ladder types
                // L 3 -> ladder3, all others (L 1, L 11) -> ladder1
                $designation = strtolower($apparatus->designation ?? '');
                $name = strtolower($apparatus->name ?? '');
                if (preg_match('/l\s*3\b/', $designation) || preg_match('/l\s*3\b/', $name)) {
                    $checklistType = 'ladder3';
                } else {
                    $checklistType = 'ladder1';
                }
            } elseif (str_contains($type, 'rescue')) {
                $checklistType = 'rescue';
            }
        }

        // Load checklist JSON from storage
        $checklistPath = storage_path("app/checklists/{$checklistType}_checklist.json");

        // Fallback to default if specific checklist does not exist
        if (!file_exists($checklistPath)) {
            $checklistPath = storage_path('app/checklists/default_checklist.json');
        }

        $checklist = [];
        if (file_exists($checklistPath)) {
            $checklist = json_decode(file_get_contents($checklistPath), true);
        }

        return response()->json([
            'apparatus' => $apparatus,
            'checklist' => $checklist,
            'open_defects' => $apparatus->openDefects,
        ]);
    }

    public function storeInspection(Request $request, $id)
    {
        $request->validate([
            'operator_name' => 'required|string',
            'rank' => 'required|string',
            'shift' => 'nullable|string',
            'unit_number' => 'nullable|string',
            'engine_hours' => 'nullable|numeric|min:0',
            'miles' => 'nullable|integer|min:0',
            'compartments' => 'nullable|array',
            'defects' => 'nullable|array',
            'defects.*.compartment' => 'required|string',
            'defects.*.item' => 'required|string',
            'defects.*.status' => 'required|string|in:Present,Missing,Damaged',
            'defects.*.notes' => 'nullable|string',
            'defects.*.photo' => 'nullable|string|max:7000000',
            'officer_signature' => 'nullable|string|max:7000000',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ]);

        $apparatus = Apparatus::findOrFail($id);

        // Save officer signature if provided
        $signaturePath = null;
        if ($request->officer_signature) {
            $signaturePath = $this->storeImageOrFail(
                $request->officer_signature,
                'signatures',
                'signature',
                'officer_signature'
            );
        }

        // Resolve employee_id if provided
        $employeeId = $request->employee_id;

        // Generate unique inspection reference
        $today = now()->format('Y-m-d');
        $designation = $apparatus->designation ?? $apparatus->name ?? 'UNK';
        $designationTag = preg_replace('/[^A-Z0-9]/i', '', $designation);
        $todayCount = ApparatusInspection::where('apparatus_id', $apparatus->id)
            ->whereDate('created_at', $today)
            ->count() + 1;
        $inspectionRef = "INS-{$designationTag}-{$today}-" . str_pad((string) $todayCount, 4, '0', STR_PAD_LEFT);

        $inspection = ApparatusInspection::create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => $request->operator_name,
            'rank' => $request->rank,
            'shift' => $request->shift,
            'unit_number' => $request->unit_number,
            'vehicle_number' => $apparatus->vehicle_number,
            'designation_at_time' => $apparatus->designation,
            'results' => $request->compartments,
            'officer_signature' => $signaturePath,
            'employee_id' => $employeeId,
            'inspection_reference' => $inspectionRef,
            'completed_at' => now(),
        ]);

        // Track if any critical defects found
        $hasCriticalDefects = false;

        foreach ($request->defects ?? [] as $defectData) {
            $photoPath = null;

            if (!empty($defectData['photo'])) {
                $photoPath = $this->storeImageOrFail(
                    $defectData['photo'],
                    'defects',
                    'defect',
                    'defects.photo'
                );
            }

            // Check for critical defects (Missing or Damaged)
            if (in_array($defectData['status'], ['Missing', 'Damaged'])) {
                $hasCriticalDefects = true;
            }

            ApparatusDefect::recordDefect(
                $apparatus->id,
                $defectData['compartment'],
                $defectData['item'],
                $defectData['status'],
                $defectData['notes'] ?? null,
                $photoPath
            );
        }

        // HOLD logic: If critical defects found, set apparatus to Out of Service
        if ($hasCriticalDefects) {
            $apparatus->update(['status' => 'Out of Service']);
        }

        // Update meter readings with positive increment validation
        if ($request->has('engine_hours') && $request->engine_hours !== null) {
            $newHours = floatval($request->engine_hours);
            $currentHours = floatval($apparatus->current_engine_hours ?? 0);
            
            // Only update if new value is greater (positive increment)
            if ($newHours > $currentHours) {
                $apparatus->current_engine_hours = $newHours;
            }
        }

        if ($request->has('miles') && $request->miles !== null) {
            $newMiles = intval($request->miles);
            $currentMiles = intval($apparatus->current_miles ?? 0);
            
            // Only update if new value is greater (positive increment)
            if ($newMiles > $currentMiles) {
                $apparatus->current_miles = $newMiles;
            }
        }

        // Save apparatus if meter data was updated
        if ($apparatus->isDirty('current_engine_hours') || $apparatus->isDirty('current_miles')) {
            // Capture PM status BEFORE saving to detect threshold crossings
            $previousHealth = $apparatus->getOriginal('current_engine_hours')
                ? (new Apparatus(array_merge($apparatus->getOriginal(), ['current_engine_hours' => $apparatus->getOriginal('current_engine_hours')])))->getPmHealthStatus()['status']
                : 'green';

            $apparatus->reported_at = now();
            $apparatus->save();

            // Dispatch PM alert check (async) after saving new meter readings
            PmAlertNotificationJob::dispatch($apparatus->id, $previousHealth);
        }

        // Dispatch Snipe-IT equipment audit job (async — does not slow the form)
        if ($apparatus->snipeit_asset_id) {
            AuditEquipmentAfterInspection::dispatch($inspection->id, $apparatus->id)
                ->delay(now()->addSeconds(5));
        }

        return response()->json($inspection->load('apparatus'), 201);
    }

    /**
     * Return employee list for the operator name dropdown.
     */
    public function employees()
    {
        $employees = Employee::select('id', 'employee_id', 'name', 'rank')
            ->orderBy('name')
            ->get();

        return response()->json($employees);
    }

    private function storeImageOrFail(string $payload, string $directory, string $prefix, string $field): string
    {
        $path = Base64Image::store($payload, $directory, $prefix);

        if ($path === null) {
            throw ValidationException::withMessages([
                $field => 'The uploaded image must be a valid JPEG, PNG, WebP, or GIF image.',
            ]);
        }

        return $path;
    }
}
