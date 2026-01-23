<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusDefect;
use Illuminate\Http\Request;

class ApparatusController extends Controller
{
    public function index()
    {
        return response()->json(Apparatus::all());
    }

    public function checklist($id)
    {
        $apparatus = Apparatus::findOrFail($id);
        
        // Determine checklist type based on unit_id prefix
        $unitId = strtoupper(trim($apparatus->unit_id));
        $checklistFile = $this->getChecklistFileForUnit($unitId);
        
        $checklistPath = storage_path("checklists/{$checklistFile}");
        
        // Fallback to default if file doesn't exist
        if (!file_exists($checklistPath)) {
            $checklistPath = storage_path("checklists/default_checklist.json");
        }
        
        $checklistData = json_decode(file_get_contents($checklistPath), true);
        
        return response()->json($checklistData);
    }
    
    private function getChecklistFileForUnit(string $unitId): string
    {
        // Extract the first letter(s) to determine apparatus type
        // E = Engine, R = Rescue, L/T = Ladder/Tower, A = Air, B = Battalion/Brush
        
        if (preg_match("/^E\s?\d/i", $unitId)) {
            return "engine_checklist.json";
        }
        
        if (preg_match("/^R\s?\d/i", $unitId)) {
            return "rescue_checklist.json";
        }
        
        if (preg_match("/^(L|T)\s?\d/i", $unitId)) {
            return "ladder1_checklist.json";
        }
        
        // Default for other types (Air, Battalion, Brush, Boat, Utility, Tanker)
        return "default_checklist.json";
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
            ApparatusDefect::recordDefect(
                $apparatus->id,
                $defectData['compartment'],
                $defectData['item'],
                $defectData['status'],
                $defectData['notes'] ?? null
            );
        }

        return response()->json($inspection->load('apparatus'), 201);
    }
}
