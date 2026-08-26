<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workgroup;
use App\Support\Security\SafeHtml;
use App\Support\Workgroups\WorkgroupReportSessionResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReportExportController extends Controller
{
    public function __construct(private readonly WorkgroupReportSessionResolver $sessions) {}

    /**
     * Export the AI Executive Report as PDF.
     */
    public function exportExecutiveReport(Request $request)
    {
        $session = $this->sessions->resolve($request);
        $workgroup = $session->workgroup;
        abort_unless($workgroup instanceof Workgroup, 404);
        $cached = Cache::get("workgroup_ai_exec_report_{$session->id}");
        $reportHtml = is_array($cached) ? ($cached['report'] ?? null) : $cached;

        abort_unless(is_string($reportHtml) && trim($reportHtml) !== '', 404);

        $reportHtml = SafeHtml::report($reportHtml);
        abort_unless($reportHtml !== '', 404);

        $title = "Executive Report — {$session->name}";

        $pdf = Pdf::loadView('filament.workgroup.pages.saver-report-pdf', [
            'title' => $title,
            'reportHtml' => $reportHtml,
            'generatedAt' => now()->format('F j, Y g:i A'),
            'workgroupName' => $workgroup->name,
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = 'MBFD_Executive_Report_'.$session->name.'_'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Export the SAVER Purchasing Report as PDF.
     */
    public function exportSaverReport(Request $request)
    {
        $session = $this->sessions->resolve($request);
        $workgroup = $session->workgroup;
        abort_unless($workgroup instanceof Workgroup, 404);
        $reportHtml = Cache::get("workgroup_saver_report_{$session->workgroup_id}_{$session->id}");

        abort_unless(
            is_string($reportHtml)
            && trim($reportHtml) !== ''
            && ! str_contains($reportHtml, 'text-red-600'),
            404,
        );

        $reportHtml = SafeHtml::report($reportHtml);
        abort_unless($reportHtml !== '', 404);

        $title = "SAVER Purchasing Report — {$session->name}";

        $pdf = Pdf::loadView('filament.workgroup.pages.saver-report-pdf', [
            'title' => $title,
            'reportHtml' => $reportHtml,
            'generatedAt' => now()->format('F j, Y g:i A'),
            'workgroupName' => $workgroup->name,
        ]);

        $pdf->setPaper('letter', 'portrait');

        $filename = 'MBFD_SAVER_Report_'.$session->name.'_'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }
}
