<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAVER Executive Report — {{ $workgroupName ?? 'MBFD' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Georgia', 'Times New Roman', serif;
            color: #1a1a1a;
            line-height: 1.6;
            background: #fff;
        }

        .report-container {
            max-width: 8.5in;
            margin: 0 auto;
            padding: 0.75in 1in;
        }

        /* Header */
        .report-header {
            text-align: center;
            border-bottom: 3px solid #1E3A5F;
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }

        .report-header .org-name {
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #1E3A5F;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .report-header .report-type {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #6B7280;
            margin-bottom: 0.75rem;
        }

        .report-header h1 {
            font-size: 1.75rem;
            color: #1E3A5F;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .report-header .meta {
            font-size: 0.8125rem;
            color: #6B7280;
        }

        .report-header .meta span {
            margin: 0 0.5rem;
        }

        /* SAVER Badge Row */
        .saver-badges {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin: 1rem 0;
        }

        .saver-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .saver-badge--cap { background: #DBEAFE; color: #1E40AF; }
        .saver-badge--usa { background: #D1FAE5; color: #065F46; }
        .saver-badge--aff { background: #FEF3C7; color: #92400E; }
        .saver-badge--mnt { background: #E0E7FF; color: #3730A3; }
        .saver-badge--dep { background: #FCE7F3; color: #9D174D; }

        /* Section headings */
        h2 {
            font-size: 1.25rem;
            color: #1E3A5F;
            margin-top: 2rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.375rem;
            border-bottom: 1px solid #D1D5DB;
        }

        h3 {
            font-size: 1rem;
            color: #374151;
            margin-top: 1.25rem;
            margin-bottom: 0.5rem;
        }

        p { margin-bottom: 0.75rem; font-size: 0.9375rem; }

        ul, ol {
            margin-left: 1.5rem;
            margin-bottom: 0.75rem;
        }

        li { margin-bottom: 0.375rem; font-size: 0.9375rem; }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            font-size: 0.875rem;
        }

        th {
            background: #F3F4F6;
            color: #374151;
            font-weight: 600;
            text-align: left;
            padding: 0.5rem 0.75rem;
            border-bottom: 2px solid #D1D5DB;
        }

        td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid #E5E7EB;
        }

        tr:nth-child(even) { background: #F9FAFB; }

        strong { color: #111827; }

        /* Print button */
        .print-controls {
            position: fixed;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.5rem;
            z-index: 1000;
        }

        .print-btn {
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }

        .print-btn--primary {
            background: #1E3A5F;
            color: #fff;
        }

        .print-btn--primary:hover { background: #2563EB; }

        .print-btn--secondary {
            background: #F3F4F6;
            color: #374151;
            border: 1px solid #D1D5DB;
        }

        .print-btn--secondary:hover { background: #E5E7EB; }

        /* Disclaimer */
        .report-footer {
            margin-top: 3rem;
            padding-top: 1rem;
            border-top: 1px solid #D1D5DB;
            font-size: 0.75rem;
            color: #9CA3AF;
            text-align: center;
        }

        /* Print styles */
        @media print {
            .print-controls { display: none; }

            body { font-size: 11pt; }

            .report-container { padding: 0; max-width: none; }

            h2 { page-break-after: avoid; }

            table { page-break-inside: avoid; }

            .report-header { border-bottom-color: #000; }

            .saver-badges {
                display: flex;
                gap: 0.25rem;
            }

            .saver-badge {
                border: 1px solid #999;
                background: none;
                color: #000;
            }
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="print-controls">
        <button class="print-btn print-btn--primary" onclick="window.print()">
            🖨️ Print Report
        </button>
        <button class="print-btn print-btn--secondary" onclick="window.close()">
            ✕ Close
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
                <span>{{ $generatedAt ?? now()->format('F j, Y') }}</span>
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
        <div class="saver-report-body">
            {!! $reportHtml !!}
        </div>
        @else
        <div style="text-align: center; padding: 3rem 0; color: #6B7280;">
            <p>No SAVER report has been generated yet.</p>
            <p style="font-size: 0.8125rem;">Return to the Session Results page and click "Generate SAVER Report".</p>
        </div>
        @endif

        <div class="report-footer">
            <p>This report was generated using AI-assisted analysis of evaluator submissions. All scores reflect aggregated evaluator assessments.</p>
            <p>Miami Beach Fire Department · Health & Safety Committee · {{ now()->format('Y') }}</p>
        </div>
    </div>
</body>
</html>
