<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StationInventoryController;
use App\Http\Controllers\Workgroup\FileDownloadController;
use App\Http\Controllers\ReportExportController;

Route::get('/', function () {
    return view('welcome');
});

// Fallback login route (required by Filament export download middleware)
Route::get('/login', function () {
    return redirect('/admin/login');
})->name('login');

// Pump Simulator - Public route for training
Route::view('/pump-simulator', 'pump-simulator')->name('pump-simulator');

// Apparatus Layout Planner - Public tool
Route::view('/apparatus-layout', 'apparatus-layout')->name('apparatus-layout');

// Serve manifest.json with no-cache headers to bypass CDN caching
Route::get('/manifest.json', function () {
    $response = response()->file(public_path('manifest.json'), [
        'Content-Type' => 'application/manifest+json',
    ]);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
});

// Daily Checkout SPA - catch-all for React Router
Route::get('/daily/{path?}', function () {
    return response()->file(public_path('daily/index.html'), [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->where('path', '.+');

Route::get('/__version', function() {
    $sha = cache()->remember('build_sha', 60, fn() => trim(shell_exec('git rev-parse HEAD') ?? 'unknown'));
    $branch = cache()->remember('build_branch', 60, fn() => trim(shell_exec('git rev-parse --abbrev-ref HEAD') ?? 'unknown'));
    $buildTime = cache()->remember('build_time', 60, fn() => now()->toIso8601String());
    
    return response()->json([
        'git_sha' => $sha,
        'branch' => $branch,
        'build_time' => $buildTime,
    ]);
});

// Station Inventory PDF Download
Route::get('/inventory-pdf/{submission}', [StationInventoryController::class, 'downloadPdf'])
    ->name('download-inventory-pdf')
    ->middleware('auth');

// Workgroup File Downloads & Preview
Route::get('/workgroup/file/{file}/download', [FileDownloadController::class, 'downloadFile'])
    ->name('workgroup.file.download')
    ->middleware('auth');

Route::get('/workgroup/file/{file}/preview', [FileDownloadController::class, 'previewFile'])
    ->name('workgroup.file.preview')
    ->middleware('auth');

Route::get('/workgroup/shared-upload/{upload}/download', [FileDownloadController::class, 'downloadSharedUpload'])
    ->name('workgroup.shared-upload.download')
    ->middleware('auth');

// SAVER Report — Print-ready view
Route::get('/workgroup/saver-report', function () {
    $workgroup = \App\Models\Workgroup::first();
    $aiService = app(\App\Services\Workgroup\WorkgroupAIService::class);

    $reportHtml = $aiService->getCachedSaverReport($workgroup?->id ?? 0);

    return view('filament.workgroup.pages.saver-report', [
        'reportHtml' => $reportHtml,
        'workgroupName' => $workgroup?->name ?? 'MBFD Workgroup',
        'sessionName' => 'All Sessions',
        'generatedAt' => now()->format('F j, Y'),
    ]);
})->name('workgroup.saver-report')->middleware('auth');

// Workgroup Analysis Report — Gemini-generated standalone page
Route::view('/workgroups/analysis-report', 'workgroup.analysis-report')
    ->name('workgroup.analysis-report')
    ->middleware('auth');

// Workgroup Data Dashboard — React-based Gemini dashboard
Route::view('/workgroups/data-dashboard', 'workgroup.data-dashboard')
    ->name('workgroup.data-dashboard')
    ->middleware('auth');

// Mid-Mount L1 Proposed Inventory — Self-contained React/SheetJS dashboard
Route::view('/workgroups/l1-inventory', 'workgroup.l1-inventory')
    ->name('workgroup.l1-inventory')
    ->middleware('auth');

// Workgroup Final Session Presentation — Reveal.js slide deck
Route::view('/workgroups/final-presentation', 'workgroup.final-presentation')
    ->name('workgroup.final-presentation')
    ->middleware('auth');

// MBFD Workgroup Evaluation Results — Professional Impeccable report with PDF export
Route::view('/workgroups/evaluation-report', 'workgroup.evaluation-report')
    ->name('workgroup.evaluation-report')
    ->middleware('auth');

// Workgroup Results CSV Export (authenticated)
Route::get('/workgroup-export/{tableKey}', function (string $tableKey, \Illuminate\Http\Request $request) {
    $sessionId = $request->query('session_id') ?: null;
    $evalService = app(\App\Services\Workgroup\EvaluationService::class);

    if (str_starts_with($tableKey, 'category_')) {
        $categoryName = urldecode(str_replace('category_', '', $tableKey));
        $results = $evalService->getSessionResults($sessionId);
        $targetCat = collect($results['rankable_categories'])->first(fn($c) => $c['category_name'] === $categoryName);
        return response()->streamDownload(function () use ($targetCat) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Rank','Product','Manufacturer','Model','Overall Score','Responses','Meets Threshold']);
            if ($targetCat) { foreach ($targetCat['rankings'] as $i => $item) { fputcsv($h, [$i+1, $item['product']->name??'', $item['product']->manufacturer??'', $item['product']->model??'', $item['weighted_average']??'', $item['response_count']??'', $item['meets_threshold']?'Yes':'No']); } }
            fclose($h);
        }, strtolower(str_replace(' ','_',$categoryName)).'_rankings_'.now()->format('Y-m-d').'.csv', ['Content-Type'=>'text/csv']);
    }

    if ($tableKey === 'competitor_groups') {
        $wg = \App\Models\Workgroup::first();
        $sess = $sessionId ? \App\Models\WorkgroupSession::find($sessionId) : null;
        $rankings = $wg ? $evalService->getCompetitorGroupRankings($wg, $sess) : [];
        return response()->streamDownload(function () use ($rankings) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Category','Group','Rank','Product','Brand','Avg Score','Responses']);
            foreach ($rankings as $c) { foreach ($c['groups'] as $g) { foreach ($g['rankings'] as $i => $r) { fputcsv($h, [$c['category_name'], $g['group_name'], $i+1, $r['name']??'', $r['brand']??'', $r['avg_score']??'', $r['response_count']??'']); } } }
            fclose($h);
        }, 'competitor_groups_'.now()->format('Y-m-d').'.csv', ['Content-Type'=>'text/csv']);
    }

    if ($tableKey === 'finalists') {
        $results = $evalService->getSessionResults($sessionId);
        return response()->streamDownload(function () use ($results) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Category','Rank','Product','Manufacturer','Avg Score','Responses']);
            foreach ($results['rankable_categories'] as $c) { $top = collect($c['rankings'])->filter(fn($r)=>$r['meets_threshold'])->take(2); foreach ($top as $i => $item) { fputcsv($h, [$c['category_name'], $i+1, $item['product']->name??'', $item['product']->manufacturer??'', $item['weighted_average']??'', $item['response_count']??'']); } }
            fclose($h);
        }, 'finalists_'.now()->format('Y-m-d').'.csv', ['Content-Type'=>'text/csv']);
    }

    $granular = $evalService->getGranularToolGroupings($sessionId);
    $fn = $tableKey.'_results_'.now()->format('Y-m-d').'.csv';

    if ($tableKey === 't1_standalone') {
        $t1 = $granular['t1_standalone'] ?? null;
        return response()->streamDownload(function () use ($t1) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Product','Brand','Overall','Capability','Usability','Affordability','Maintainability','Deployability','Responses']);
            if ($t1) { fputcsv($h, [$t1['name']??'', $t1['brand']??'', $t1['avg_score']??'', $t1['saver_breakdown']['capability']??'', $t1['saver_breakdown']['usability']??'', $t1['saver_breakdown']['affordability']??'', $t1['saver_breakdown']['maintainability']??'', $t1['saver_breakdown']['deployability']??'', $t1['response_count']??'']); }
            fclose($h);
        }, $fn, ['Content-Type'=>'text/csv']);
    }

    if ($tableKey === 'brand_overall') {
        $brands = $granular['brand_overall'] ?? [];
        return response()->streamDownload(function () use ($brands) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Rank','Brand','Overall Avg','Tools','Capability','Usability','Affordability','Maintainability','Deployability']);
            foreach ($brands as $b) { fputcsv($h, [$b['rank']??'', $b['brand']??'', $b['overall_avg']??'', $b['tool_count']??'', $b['saver_breakdown']['capability']??'', $b['saver_breakdown']['usability']??'', $b['saver_breakdown']['affordability']??'', $b['saver_breakdown']['maintainability']??'', $b['saver_breakdown']['deployability']??'']); }
            fclose($h);
        }, $fn, ['Content-Type'=>'text/csv']);
    }

    $items = $granular[$tableKey] ?? [];
    return response()->streamDownload(function () use ($items) {
        $h = fopen('php://output', 'w');
        fputcsv($h, ['Rank','Product','Brand','Overall','Capability','Usability','Affordability','Maintainability','Deployability','Advance Yes','Advance No','Deal Breakers','Responses']);
        foreach ($items as $i => $item) { fputcsv($h, [$i+1, $item['name']??($item['product']->name??''), $item['brand']??'', $item['avg_score']??'', $item['capability_avg']??'', $item['usability_avg']??'', $item['affordability_avg']??'', $item['maintainability_avg']??'', $item['deployability_avg']??'', $item['advance_yes']??'', $item['advance_no']??'', $item['deal_breakers']??'', $item['response_count']??'']); }
        fclose($h);
    }, $fn, ['Content-Type'=>'text/csv']);
})->name('workgroup.export.csv')->middleware('auth');

// Workgroup Report PDF Exports (authenticated)
Route::middleware(['auth'])->group(function () {
    Route::get('/reports/executive-report/pdf', [ReportExportController::class, 'exportExecutiveReport'])->name('reports.executive.pdf');
    Route::get('/reports/saver-report/pdf', [ReportExportController::class, 'exportSaverReport'])->name('reports.saver.pdf');
});

