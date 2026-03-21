<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusDefect;
use App\Jobs\PmAlertNotificationJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                $checklistType = 'engine';
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
            'defects.*.photo' => 'nullable|string',
            'officer_signature' => 'nullable|string',
        ]);

        $apparatus = Apparatus::findOrFail($id);

        // Save officer signature if provided
        $signaturePath = null;
        if ($request->officer_signature) {
            $sig = $request->officer_signature;
            if (preg_match('/^data:image\/(\w+);base64,/', $sig, $matches)) {
                $sig = substr($sig, strpos($sig, ',') + 1);
                $ext = $matches[1];
            } else {
                $ext = 'png';
            }
            $decoded = base64_decode($sig);
            $filename = 'signatures/' . Str::uuid() . '.' . $ext;
            Storage::disk('public')->put($filename, $decoded);
            $signaturePath = $filename;
        }

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
            'completed_at' => now(),
        ]);

        // Track if any critical defects found
        $hasCriticalDefects = false;

        foreach ($request->defects ?? [] as $defectData) {
            $photoPath = null;

            if (!empty($defectData['photo'])) {
                $photo = $defectData['photo'];
                if (preg_match('/^data:image\/(\w+);base64,/', $photo, $matches)) {
                    $photo = substr($photo, strpos($photo, ',') + 1);
                    $extension = $matches[1];
                } else {
                    $extension = 'jpg';
                }
                $decodedImage = base64_decode($photo);
                $filename = 'defects/' . Str::uuid() . '.' . $extension;
                Storage::disk('public')->put($filename, $decodedImage);
                $photoPath = $filename;
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

        return response()->json($inspection->load('apparatus'), 201);
    }
}
