<?php

namespace App\Http\Controllers;

use App\Models\Workgroup;
use App\Models\WorkgroupSession;
use App\Services\Workgroup\WorkgroupAIService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReportExportController extends Controller
{
    /**
     * Export the AI Executive Report as PDF.
     */
    public function exportExecutiveReport(Request $request)
    {
        $sessionId = $request->query('session_id');
        $workgroup = Workgroup::first();

        if (!$workgroup) {
            abort(404, 'No workgroup found.');
        }

        $session = $sessionId ? WorkgroupSession::find($sessionId) : null;

        // Try to get cached report first
        $cacheKey = $session
            ? "workgroup_ai_exec_report_{$session->id}"
            : "workgroup_ai_exec_report_overall_{$workgroup->id}";
        $cached = Cache::get($cacheKey);

        $reportHtml = '';
        if ($cached) {
            $reportHtml = is_array($cached) ? ($cached['report'] ?? json_encode($cached)) : (string) $cached;
        }

        if (empty($reportHtml)) {
            // Generate fresh if not cached
            $aiService = app(WorkgroupAIService::class);
            $result = $aiService->generateExecutiveReport($workgroup, $session);
            $reportHtml = is_array($result) ? ($result['report'] ?? '') : (string) $result;
        }

        if (empty($reportHtml)) {
            abort(404, 'No report content available. Generate the report first.');
        }

        $title = $session
            ? "Executive Report — {$session->name}"
            : "Executive Report — Overall Project Evaluation";

        $pdf = Pdf::loadView('filament.workgroup.pages.saver-report-pdf', [
            'title' => $title,
            'reportHtml' => $reportHtml,
            'generatedAt' => now()->format('F j, Y g:i A'),
            'workgroupName' => $workgroup->name,
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = 'MBFD_Executive_Report_' . ($session ? $session->name : 'Overall') . '_' . now()->format('Y-m-d') . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Export the SAVER Purchasing Report as PDF.
     */
    public function exportSaverReport(Request $request)
    {
        $sessionId = $request->query('session_id');
        $workgroup = Workgroup::first();

        if (!$workgroup) {
            abort(404, 'No workgroup found.');
        }

        $session = $sessionId ? WorkgroupSession::find($sessionId) : null;

        // Try cache first
        $cached = Cache::get("workgroup_saver_report_{$workgroup->id}_{$session?->id}");

        $reportHtml = $cached ?? '';

        if (empty($reportHtml)) {
            // Generate fresh
            $aiService = app(WorkgroupAIService::class);
            $reportHtml = $aiService->generateSaverReport($workgroup, $session);
        }

        if (empty($reportHtml) || str_contains($reportHtml, 'text-red-600')) {
            abort(404, 'No SAVER report content available. Generate the report first.');
        }

        $title = $session
            ? "SAVER Purchasing Report — {$session->name}"
            : "SAVER Purchasing Report — All Sessions";

        $pdf = Pdf::loadView('filament.workgroup.pages.saver-report-pdf', [
            'title' => $title,
            'reportHtml' => $reportHtml,
            'generatedAt' => now()->format('F j, Y g:i A'),
            'workgroupName' => $workgroup->name,
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = 'MBFD_SAVER_Report_' . ($session ? $session->name : 'Overall') . '_' . now()->format('Y-m-d') . '.pdf';
        return $pdf->download($filename);
    }
}
