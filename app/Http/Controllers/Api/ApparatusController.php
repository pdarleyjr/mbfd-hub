<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AuditEquipmentAfterInspection;
use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Employee;
use App\Support\Security\Base64Image;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApparatusController extends Controller
{
    public function index()
    {
        // Internal/identifying fields are redacted from this public (unauthenticated)
        // endpoint. The daily-checkout SPA only needs operational/status fields; VIN,
        // Snipe-IT asset identifiers, internal notes, and physical location are not
        // exposed to anonymous callers.
        $hidden = ['vin', 'snipeit_asset_id', 'snipeit_asset_tag', 'notes', 'current_location'];

        $apparatuses = Apparatus::all()->map(function ($apparatus) use ($hidden) {
            $data = $apparatus->toArray();
            $data['pm_health'] = $apparatus->getPmHealthStatus();

            foreach ($hidden as $key) {
                unset($data[$key]);
            }

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
        if (! file_exists($checklistPath)) {
            $checklistPath = storage_path('app/checklists/default_checklist.json');
        }

        $checklist = [];
        if (file_exists($checklistPath)) {
            $checklist = json_decode(file_get_contents($checklistPath), true);
        }

        // Redact internal/identifying apparatus fields from this public endpoint.
        $apparatusData = $apparatus->toArray();
        foreach (['vin', 'snipeit_asset_id', 'snipeit_asset_tag', 'notes', 'current_location'] as $key) {
            unset($apparatusData[$key]);
        }

        return response()->json([
            'apparatus' => $apparatusData,
            'checklist' => $checklist,
            'open_defects' => $apparatus->openDefects,
        ]);
    }

    public function storeInspection(Request $request, $id)
    {
        $validated = $request->validate([
            'operator_name' => 'required|string|max:255',
            'rank' => 'required|string|max:100',
            'shift' => 'nullable|string|max:20',
            'unit_number' => 'nullable|string|max:100',
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

        // The client may display a unit number, but the persisted identity must
        // always come from the apparatus selected by its unique route ID.
        $today = now()->format('Y-m-d');
        $designation = $apparatus->designation ?? $apparatus->name ?? 'UNK';
        $designationTag = preg_replace('/[^A-Z0-9]/i', '', $designation);

        $inspection = ApparatusInspection::create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => $validated['operator_name'],
            'rank' => $validated['rank'],
            'shift' => $validated['shift'] ?? null,
            'unit_number' => $apparatus->vehicle_number,
            'engine_hours' => $validated['engine_hours'] ?? null,
            'miles' => $validated['miles'] ?? null,
            'vehicle_number' => $apparatus->vehicle_number,
            'designation_at_time' => $apparatus->designation,
            'results' => $validated['compartments'] ?? null,
            'officer_signature' => $signaturePath,
            'employee_id' => $employeeId,
            'review_status' => 'approved',
            'completed_at' => now(),
        ]);

        // The database ID is concurrency-safe, unlike a daily count. The unique
        // index makes the invariant explicit for imports and future writers too.
        $inspectionRef = "INS-{$designationTag}-{$today}-".str_pad((string) $inspection->id, 6, '0', STR_PAD_LEFT);
        $inspection->update(['inspection_reference' => $inspectionRef]);

        // Track if any critical defects found
        $hasCriticalDefects = false;

        foreach ($request->defects ?? [] as $defectData) {
            $photoPath = null;

            if (! empty($defectData['photo'])) {
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
                $photoPath,
                $inspection->id,
            );
        }

        // SECURITY (H-01): a public, unauthenticated submission must NOT directly
        // drive an apparatus Out of Service. When critical defects are reported,
        // hold the inspection for review instead of mutating operational status.
        // An authorized user applies the out-of-service hold via approve().
        if ($hasCriticalDefects) {
            $inspection->update(['review_status' => 'pending_review']);
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
     * Approve a pending-review inspection (authenticated/authorized only).
     *
     * SECURITY (H-01): this is the only path that may flip an apparatus to
     * "Out of Service" as a result of a reported critical defect. The public
     * submission endpoint records the inspection as 'pending_review'; an
     * authorized reviewer confirms it here, which applies the operational hold.
     */
    public function approveInspection(Request $request, $id)
    {
        $inspection = ApparatusInspection::with('apparatus')->findOrFail($id);

        if ($inspection->review_status === 'pending_review') {
            $apparatus = $inspection->apparatus;

            if ($apparatus !== null && $apparatus->status !== 'Out of Service') {
                $apparatus->update(['status' => 'Out of Service']);
            }
        }

        $inspection->update(['review_status' => 'approved']);

        return response()->json($inspection->fresh()->load('apparatus'));
    }

    /**
     * Return employee list for the operator name dropdown.
     */
    public function employees()
    {
        // employee_id is also the portal login identifier. The public kiosk
        // directory only needs an opaque database key to link the inspection.
        $employees = Employee::select('id', 'name', 'rank')
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
