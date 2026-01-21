<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusDefect;
use Illuminate\Http\Request;

class AdminMetricsController extends Controller
{
    public function index()
    {
        $openDefectsCount = ApparatusDefect::where('resolved', false)->count();
        $inspectionsToday = ApparatusInspection::whereDate('completed_at', today())->count();
        $totalApparatuses = Apparatus::count();

        return response()->json([
            'open_defects_count' => $openDefectsCount,
            'inspections_today' => $inspectionsToday,
            'total_apparatuses' => $totalApparatuses,
        ]);
    }
}