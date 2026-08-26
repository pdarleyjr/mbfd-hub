<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workgroup;

use App\Http\Controllers\Controller;
use App\Models\Workgroup;
use App\Support\Security\SafeHtml;
use App\Support\Workgroups\WorkgroupReportSessionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class WorkgroupReportController extends Controller
{
    public function __construct(private readonly WorkgroupReportSessionResolver $sessions) {}

    public function saverReport(Request $request)
    {
        $session = $this->sessions->resolve($request);
        $workgroup = $session->getAttribute('workgroup');
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

        return view('filament.workgroup.pages.saver-report', [
            'reportHtml' => $reportHtml,
            'workgroupName' => $workgroup->name,
            'sessionName' => $session->name,
            'generatedAt' => now()->format('F j, Y'),
        ]);
    }
}
