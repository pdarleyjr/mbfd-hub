<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusDefect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ApparatusController extends Controller
{
    public function index()
    {
        return response()->json(Apparatus::all());
    }

    public function checklist($id)
    {
        $apparatus = Apparatus::findOrFail($id);
        
        // Determine checklist file based on apparatus type
        $checklistType = 'default';
        if ($apparatus->type) {
            $type = strtolower($apparatus->type);
            // Map apparatus type to checklist file
            if (str_contains($type, 'engine')) {
                $checklistType = 'engine';
            } elseif (str_contains($type, 'ladder')) {
                $checklistType = str_contains($type, 'ladder1') ? 'ladder1' : 
                                 (str_contains($type, 'ladder3') ? 'ladder3' : 'default');
            } elseif (str_contains($type, 'rescue')) {
                $checklistType = 'rescue';
            }
        }
        
        // Load checklist JSON from storage
        $checklistPath = storage_path("app/checklists/{$checklistType}_checklist.json");
        
        // Fallback to default if specific checklist doesn't exist
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
            'shift' => 'required|string',
            'unit_number' => 'nullable|string',
            'defects' => 'array',
            'defects.*.compartment' => 'required|string',
            'defects.*.item' => 'required|string',
            'defects.*.status' => 'required|string|in:Present,Missing,Damaged',
            'defects.*.notes' => 'nullable|string',
            'defects.*.photo' => 'nullable|string',
        ]);

        $apparatus = Apparatus::findOrFail($id);

        $inspection = ApparatusInspection::create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => $request->operator_name,
            'rank' => $request->rank,
            'shift' => $request->shift,
            'unit_number' => $request->unit_number,
            'completed_at' => now(),
        ]);

        foreach ($request->defects as $defectData) {
            $photoPath = null;
            
            // Handle photo if present
            if (!empty($defectData['photo'])) {
                // Strip the data:image/...;base64, prefix if present
                $photo = $defectData['photo'];
                if (preg_match('/^data:image\/(\w+);base64,/', $photo, $matches)) {
                    $photo = substr($photo, strpos($photo, ',') + 1);
                    $extension = $matches[1];
                } else {
                    $extension = 'jpg';
                }
                
                // Decode base64
                $decodedImage = base64_decode($photo);
                
                // Generate filename
                $filename = 'defects/' . Str::uuid() . '.' . $extension;
                
                // Save to public disk
                Storage::disk('public')->put($filename, $decodedImage);
                
                $photoPath = $filename;
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

        return response()->json($inspection->load('apparatus'), 201);
    }
}