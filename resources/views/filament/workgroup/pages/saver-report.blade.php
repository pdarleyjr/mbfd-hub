<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <x-hub.head />
    <title>SAVER Executive Report — {{ $workgroupName ?? 'MBFD' }}</title>
    @vite(['resources/css/reports/saver-report.css', 'resources/css/app.css'])
</head>
<body data-hub-ui="2" data-hub-portal="report" class="hub-report hub-report--saver-report">
    <div class="hub-report-navigation"><x-hub-header back-href="/workgroups/session-results" back-label="Back to Session Results" max-width="max-w-7xl" /></div>
    <div class="print-controls">
        <button class="print-btn print-btn--primary" onclick="window.print()">
            🖨️ Print Report
        </button>
    </div>

    <div class="report-container">
        <div class="report-header">
            <div class="org-name">Miami Beach Fire Department</div>
            <div class="report-type">System Assessment and Validation for Emergency Responders</div>
            <h1>SAVER Executive Purchasing Report</h1>
            <div class="meta">
                <span>{{ $workgroupName ?? 'Health & Safety Committee' }}</span>
                <span>·</span>
                <span>{{ $sessionName ?? 'All Sessions' }}</span>
                <span>·</span>
                <span>{{ $generatedAt ?? now((string) config('hub.timezone'))->format('F j, Y') }}</span>
            </div>
            <div class="saver-badges">
                <span class="saver-badge saver-badge--cap">Capability</span>
                <span class="saver-badge saver-badge--usa">Usability</span>
                <span class="saver-badge saver-badge--aff">Affordability</span>
                <span class="saver-badge saver-badge--mnt">Maintainability</span>
                <span class="saver-badge saver-badge--dep">Deployability</span>
            </div>
        </div>

        {{-- AI-generated report content --}}
        @if(!empty($reportHtml))
        <div class="saver-report-body hub-report-table" tabindex="0" role="region" aria-label="SAVER report content">
            {{-- SECURITY: Defense-in-depth — re-sanitize at the render boundary
                 in case a stale, pre-sanitization cache entry survives the
                 WorkgroupAIService::sanitizeReportPayload() upgrade. --}}
            {!! \App\Support\Security\SafeHtml::report($reportHtml) !!}
        </div>
        @else
        <div class="sv-u1">
            <p>No SAVER report has been generated yet.</p>
            <p class="sv-u2">Return to the Session Results page and click "Generate SAVER Report".</p>
        </div>
        @endif

        <div class="report-footer">
            <p>This report was generated using AI-assisted analysis of evaluator submissions. All scores reflect aggregated evaluator assessments.</p>
            <p>Miami Beach Fire Department · Health & Safety Committee · {{ now((string) config('hub.timezone'))->format('Y') }}</p>
        </div>
    </div>
</body>
</html>
