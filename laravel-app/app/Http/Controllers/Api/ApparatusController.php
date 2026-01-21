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
        // Assuming checklist is based on type, but since not specified, return apparatus with open defects
        return response()->json([
            'apparatus' => $apparatus,
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