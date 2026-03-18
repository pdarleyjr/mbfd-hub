<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MBFD Workgroup Evaluation Results — Hydraulic Rescue Tool Evaluation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Source+Serif+4:ital,wght@0,300;0,400;0,600;1,300;1,400&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --color-navy: #0f172a;
            --color-slate-800: #1e293b;
            --color-slate-700: #334155;
            --color-slate-500: #64748b;
            --color-slate-400: #94a3b8;
            --color-slate-200: #e2e8f0;
            --color-slate-100: #f1f5f9;
            --color-slate-50: #f8fafc;
            --color-red: #dc2626;
            --color-red-light: #fee2e2;
            --color-emerald: #059669;
            --color-blue: #2563eb;
            --color-amber: #d97706;
            --color-body: #1a202c;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--color-slate-100);
            color: var(--color-body);
            font-size: 15px;
            line-height: 1.6;
        }

        /* ── PRINT BUTTON BAR ── */
        .print-bar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--color-navy);
            padding: 0.625rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .print-bar-title {
            color: rgba(255,255,255,0.7);
            font-size: 0.8125rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .print-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--color-red);
            color: white;
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 150ms;
        }
        .print-btn:hover { background: #b91c1c; }
        .print-btn svg { width: 1rem; height: 1rem; }

        /* ── REPORT WRAPPER ── */
        .report-wrapper {
            max-width: 900px;
            margin: 2rem auto;
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        /* ── COVER HEADER ── */
        .report-cover {
            background: linear-gradient(135deg, var(--color-navy) 0%, #1e3a5f 100%);
            padding: 3rem 3.5rem 2.5rem;
            position: relative;
            overflow: hidden;
        }
        .report-cover::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 300px; height: 300px;
            background: rgba(220, 38, 38, 0.12);
            border-radius: 50%;
        }
        .report-cover::after {
            content: '';
            position: absolute;
            bottom: -80px; left: 10%;
            width: 200px; height: 200px;
            background: rgba(37, 99, 235, 0.1);
            border-radius: 50%;
        }
        .cover-agency {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }
        .cover-badge {
            width: 48px;
            height: 48px;
            background: var(--color-red);
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cover-agency-text {
            color: rgba(255,255,255,0.9);
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .cover-agency-sub {
            color: rgba(255,255,255,0.5);
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .cover-title {
            color: white;
            font-size: 2.25rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 0.75rem;
            position: relative;
            z-index: 1;
        }
        .cover-subtitle {
            color: rgba(255,255,255,0.65);
            font-size: 1rem;
            font-weight: 400;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }
        .cover-meta {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }
        .cover-meta-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .cover-meta-label {
            color: rgba(255,255,255,0.45);
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .cover-meta-value {
            color: rgba(255,255,255,0.9);
            font-size: 0.875rem;
            font-weight: 600;
        }
        .cover-accent-line {
            width: 64px;
            height: 3px;
            background: var(--color-red);
            border-radius: 2px;
            margin: 1.5rem 0;
            position: relative;
            z-index: 1;
        }

        /* ── REPORT BODY ── */
        .report-body {
            padding: 2.5rem 3.5rem;
        }

        /* ── TOC ── */
        .toc {
            background: var(--color-slate-50);
            border: 1px solid var(--color-slate-200);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2.5rem;
        }
        .toc-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--color-slate-500);
            margin-bottom: 1rem;
        }
        .toc-list {
            list-style: none;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.375rem;
        }
        .toc-list li a {
            color: var(--color-blue);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            gap: 0.5rem;
        }
        .toc-list li a:hover { text-decoration: underline; }
        .toc-num {
            color: var(--color-slate-400);
            min-width: 1.5rem;
        }

        /* ── SECTIONS ── */
        .section { margin-bottom: 2.5rem; }
        .section + .section { border-top: 1px solid var(--color-slate-200); padding-top: 2.5rem; }

        /* ── HEADINGS ── */
        h1.section-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--color-navy);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        h1.section-title .section-num {
            background: var(--color-red);
            color: white;
            width: 2rem;
            height: 2rem;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        h2.subsection-title {
            font-size: 1.0625rem;
            font-weight: 700;
            color: var(--color-slate-800);
            margin: 1.5rem 0 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--color-slate-200);
        }
        h3.sub-subsection {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--color-slate-700);
            margin: 1.25rem 0 0.5rem;
        }

        /* ── BODY TEXT ── */
        p { color: #374151; margin-bottom: 0.875rem; }
        p:last-child { margin-bottom: 0; }
        strong { color: var(--color-navy); font-weight: 600; }

        /* ── KEY FINDINGS ── */
        .key-findings {
            background: linear-gradient(135deg, #eff6ff, #f0fdf4);
            border: 1px solid #bfdbfe;
            border-left: 4px solid var(--color-blue);
            border-radius: 0.5rem;
            padding: 1.25rem 1.5rem;
            margin: 1.25rem 0;
        }
        .key-findings-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--color-blue);
            margin-bottom: 0.75rem;
        }
        .key-findings ul {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .key-findings li {
            display: flex;
            gap: 0.625rem;
            font-size: 0.9rem;
            color: #1e3a5f;
            line-height: 1.5;
        }
        .key-findings li::before {
            content: '→';
            color: var(--color-blue);
            font-weight: 700;
            flex-shrink: 0;
        }

        /* ── KPI CARDS ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1.25rem 0;
        }
        .kpi-card {
            background: var(--color-slate-50);
            border: 1px solid var(--color-slate-200);
            border-radius: 0.625rem;
            padding: 1.25rem;
            text-align: center;
        }
        .kpi-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--color-navy);
            line-height: 1;
            margin-bottom: 0.375rem;
        }
        .kpi-value.green { color: var(--color-emerald); }
        .kpi-value.blue { color: var(--color-blue); }
        .kpi-value.red { color: var(--color-red); }
        .kpi-label {
            font-size: 0.75rem;
            color: var(--color-slate-500);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* ── TABLES ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            font-size: 0.875rem;
            border-radius: 0.5rem;
            overflow: hidden;
            border: 1px solid var(--color-slate-200);
        }
        .data-table thead th {
            background: var(--color-navy);
            color: white;
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .data-table thead th.right { text-align: right; }
        .data-table tbody td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--color-slate-100);
            color: #374151;
            vertical-align: middle;
        }
        .data-table tbody td.right { text-align: right; font-weight: 600; }
        .data-table tbody tr:hover { background: var(--color-slate-50); }
        .data-table tfoot td {
            padding: 0.75rem 1rem;
            background: var(--color-slate-100);
            font-weight: 700;
            color: var(--color-navy);
            border-top: 2px solid var(--color-slate-300);
        }
        .score-badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.875rem;
            min-width: 4rem;
            text-align: center;
        }
        .score-elite { background: #d1fae5; color: #065f46; }
        .score-high { background: #dbeafe; color: #1e40af; }
        .score-mid { background: #fef3c7; color: #92400e; }
        .score-low { background: #fee2e2; color: #991b1b; }
        .brand-pill {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--color-slate-100);
            color: var(--color-slate-700);
        }

        /* ── CHART CONTAINERS ── */
        .chart-section {
            background: var(--color-slate-50);
            border: 1px solid var(--color-slate-200);
            border-radius: 0.625rem;
            padding: 1.5rem;
            margin: 1.25rem 0;
        }
        .chart-title {
            font-size: 0.8125rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-slate-500);
            margin-bottom: 1rem;
        }
        .chart-container {
            position: relative;
            height: 240px;
        }
        .chart-container.tall { height: 300px; }

        /* ── IMAGES ── */
        .report-image {
            width: 100%;
            border-radius: 0.5rem;
            border: 1px solid var(--color-slate-200);
            margin: 1.25rem 0;
            display: block;
            max-height: 360px;
            object-fit: contain;
            background: var(--color-slate-50);
        }
        .image-caption {
            font-size: 0.75rem;
            color: var(--color-slate-500);
            text-align: center;
            font-style: italic;
            margin-top: -0.75rem;
            margin-bottom: 1.25rem;
        }
        .image-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1.25rem 0;
        }
        .image-grid .report-image { margin: 0; }

        /* ── CALLOUTS ── */
        .callout {
            border-radius: 0.5rem;
            padding: 1.125rem 1.25rem;
            margin: 1.25rem 0;
            border-left: 4px solid;
            font-size: 0.9rem;
        }
        .callout.warning {
            background: #fef9c3;
            border-color: var(--color-amber);
            color: #713f12;
        }
        .callout.info {
            background: #eff6ff;
            border-color: var(--color-blue);
            color: #1e3a5f;
        }
        .callout.note {
            background: var(--color-slate-50);
            border-color: var(--color-slate-400);
            color: var(--color-slate-700);
        }
        .callout-label {
            font-size: 0.6875rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.375rem;
            opacity: 0.7;
        }

        /* ── SPEC TABLE (attribute/value) ── */
        .spec-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            margin: 0.75rem 0;
            border: 1px solid var(--color-slate-200);
            border-radius: 0.375rem;
            overflow: hidden;
        }
        .spec-table tr:nth-child(even) { background: var(--color-slate-50); }
        .spec-table td {
            padding: 0.625rem 0.875rem;
            border-bottom: 1px solid var(--color-slate-100);
            color: #374151;
        }
        .spec-table td:first-child {
            font-weight: 600;
            color: var(--color-navy);
            width: 40%;
        }
        .spec-table tr:last-child td { border-bottom: none; }

        /* ── WINNER BANNER ── */
        .winner-banner {
            background: linear-gradient(135deg, var(--color-navy), #1e3a5f);
            border-radius: 0.625rem;
            padding: 1.5rem;
            margin: 1.25rem 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .winner-info { flex: 1; }
        .winner-label {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--color-red);
            margin-bottom: 0.25rem;
        }
        .winner-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: white;
        }
        .winner-desc {
            font-size: 0.8125rem;
            color: rgba(255,255,255,0.65);
            margin-top: 0.25rem;
        }
        .winner-score-box {
            text-align: center;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 0.5rem;
            padding: 0.875rem 1.25rem;
        }
        .winner-score-value {
            font-size: 2rem;
            font-weight: 800;
            color: #34d399;
            line-height: 1;
        }
        .winner-score-label {
            font-size: 0.625rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255,255,255,0.4);
            margin-top: 0.25rem;
        }

        /* ── HR / DIVIDER ── */
        hr.section-divider {
            border: none;
            border-top: 1px solid var(--color-slate-200);
            margin: 2rem 0;
        }

        /* ── APPENDIX TABLE ── */
        .appendix-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--color-slate-500);
            margin-bottom: 0.75rem;
        }

        /* ── FOOTER ── */
        .report-footer {
            background: var(--color-slate-50);
            border-top: 1px solid var(--color-slate-200);
            padding: 1.25rem 3.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--color-slate-500);
        }

        /* ── PRINT STYLES ── */
        @@media print {
            body { background: white; font-size: 13px; }
            .print-bar { display: none; }
            .report-wrapper {
                max-width: 100%;
                margin: 0;
                border-radius: 0;
                box-shadow: none;
                border: none;
            }
            .report-body { padding: 1.5rem 2rem; }
            .report-cover { padding: 2rem; }
            .section + .section { page-break-inside: avoid; }
            .chart-section { page-break-inside: avoid; }
            .data-table { page-break-inside: avoid; }
            .winner-banner { page-break-inside: avoid; }
            h1.section-title, h2.subsection-title { page-break-after: avoid; }
            .report-image { max-height: 280px; }
            @@page {
                margin: 0.75in;
                size: letter;
            }
            @@page :first { margin-top: 0; }
        }

        /* ── RESPONSIVE ── */
        @@media (max-width: 640px) {
            .report-body { padding: 1.5rem; }
            .report-cover { padding: 2rem 1.5rem; }
            .cover-title { font-size: 1.75rem; }
            .toc-list { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: 1fr; }
            .image-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Print Bar -->
    <div class="print-bar no-print">
        <span class="print-bar-title">MBFD Workgroup Evaluation Results — March 2026</span>
        <button class="print-btn" onclick="window.print()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
            Save as PDF / Print
        </button>
    </div>

    <div class="report-wrapper">

        <!-- Cover -->
        <div class="report-cover">
            <div class="cover-agency">
                <div class="cover-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" width="26" height="26"><path d="M11.47 3.841a.75.75 0 0 1 1.06 0l8.69 8.69a.75.75 0 1 0 1.06-1.061l-8.689-8.69a2.25 2.25 0 0 0-3.182 0l-8.69 8.69a.75.75 0 1 0 1.061 1.06l8.69-8.689Z"/><path d="m12 5.432 8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 0 1-.75-.75v-4.5a.75.75 0 0 0-.75-.75h-3a.75.75 0 0 0-.75.75V21a.75.75 0 0 1-.75.75H5.625a1.875 1.875 0 0 1-1.875-1.875v-6.198a2.29 2.29 0 0 0 .091-.086L12 5.432Z"/></svg>
                </div>
                <div>
                    <div class="cover-agency-text">Miami Beach Fire Department</div>
                    <div class="cover-agency-sub">EQUIPMENT EVALUATION DIVISION</div>
                </div>
            </div>
            <div class="cover-accent-line"></div>
            <h1 class="cover-title">Workgroup Evaluation Results<br>Hydraulic Rescue Tool Assessment</h1>
            <p class="cover-subtitle">Technical Product Comparison Analysis — Extrication, Saws & Vehicle Stabilization</p>
            <div class="cover-meta">
                <div class="cover-meta-item">
                    <span class="cover-meta-label">Report Date</span>
                    <span class="cover-meta-value">March 18, 2026</span>
                </div>
                <div class="cover-meta-item">
                    <span class="cover-meta-label">Products Evaluated</span>
                    <span class="cover-meta-value">14 Products</span>
                </div>
                <div class="cover-meta-item">
                    <span class="cover-meta-label">Categories</span>
                    <span class="cover-meta-value">6 Operational</span>
                </div>
                <div class="cover-meta-item">
                    <span class="cover-meta-label">Data Source</span>
                    <span class="cover-meta-value">Product_Data_Master.csv</span>
                </div>
            </div>
        </div>

        <!-- Report Body -->
        <div class="report-body">

            <!-- TOC -->
            <div class="toc">
                <div class="toc-title">Table of Contents</div>
                <ul class="toc-list">
                    <li><a href="#sec1"><span class="toc-num">1.</span> Executive Summary</a></li>
                    <li><a href="#sec2"><span class="toc-num">2.</span> Operational Context</a></li>
                    <li><a href="#sec3"><span class="toc-num">3.</span> Analysis</a></li>
                    <li><a href="#sec4"><span class="toc-num">4.</span> Findings</a></li>
                    <li><a href="#sec5"><span class="toc-num">5.</span> Controlled Considerations</a></li>
                    <li><a href="#appendix"><span class="toc-num">A.</span> Data Source Verification</a></li>
                </ul>
            </div>

            <!-- ══ SECTION 1: Executive Summary ══ -->
            <section class="section" id="sec1">
                <h1 class="section-title"><span class="section-num">1</span> Executive Summary</h1>

                <p>This report presents the findings of the <strong>Miami Beach Fire Department (MBFD) Workgroup</strong> evaluation of hydraulic rescue tools, rotary cut-off saws, and vehicle stabilization equipment. From a broader field of vendors and tools tested, the workgroup identified finalists for detailed analysis: the <strong>top 2 extrication tool brands</strong> (Holmatro and Hurst), the <strong>top-performing cut-off saws</strong>, the <strong>top 3 stabilization struts</strong>, and specific individual tools with targeted operational roles.</p>

                <p>This report documents <strong>14 finalist products</strong> across <strong>6 operational categories</strong>: Spreaders, Cutters, Rams, Rotary Cut-Off Saws, Vehicle Stabilization, and Specialty Tools.</p>

                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-value blue">14</div>
                        <div class="kpi-label">Products Evaluated</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value">6</div>
                        <div class="kpi-label">Operational Categories</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value green">90.72</div>
                        <div class="kpi-label">Holmatro Avg Score</div>
                    </div>
                </div>

                <div class="key-findings">
                    <div class="key-findings-title">Key Findings</div>
                    <ul>
                        <li><strong>Holmatro Pentheon Series</strong> achieved the highest average composite score across frontline hydraulic tools at <strong>90.72</strong>, leading in all four scoring dimensions: Capability, Usability, Maintainability, and Deployability.</li>
                        <li><strong>Hurst eDRAULIC E3 Series</strong> delivered strong performance with an average frontline composite score of <strong>86.53</strong>, particularly excelling in raw Capability.</li>
                        <li><strong>DeWalt DCPS612AG2 12-inch Cut-Off Saw</strong> dominated the saw category with a composite score of <strong>91.25</strong>, establishing superiority over 14-inch competitors.</li>
                        <li><strong>Holmatro V-Strut</strong> led the stabilization category with a composite score of <strong>87.28</strong>.</li>
                        <li>The finalist dataset encompasses <strong>14 products from 6 manufacturers</strong>, evaluated across 4 scoring dimensions using standardized workgroup protocols.</li>
                    </ul>
                </div>

                <img class="report-image" src="/workgroup-report/images/final_workgroup_results_p1_i7.png"
                     alt="Workgroup Evaluation Summary"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='block'">
                <div style="display:none; background:var(--color-slate-50); border:1px solid var(--color-slate-200); border-radius:0.5rem; padding:1.5rem; text-align:center; color:var(--color-slate-400); font-size:0.875rem; margin:1.25rem 0;">
                    [Workgroup Evaluation Summary Chart — Image not available]
                </div>
                <div class="image-caption">Workgroup Evaluation Summary — Overall product performance overview</div>
            </section>

            <!-- ══ SECTION 2: Operational Context ══ -->
            <section class="section" id="sec2">
                <h1 class="section-title"><span class="section-num">2</span> Problem / Operational Context</h1>

                <p>The Miami Beach Fire Department operates in a dense, high-rise urban coastal environment that imposes strict constraints on tool selection, apparatus compartment geometry, and deployment speed.</p>

                <div class="callout info">
                    <div class="callout-label">Operating Environment</div>
                    MBFD apparatus — including mid-mount ladder trucks — have limited compartment space, requiring tools that are compact in their retracted/stored state while delivering maximum capability when deployed. Miami Beach's barrier-island geography demands tools that can be rapidly deployed in congested settings with limited staging area.
                </div>

                <h2 class="subsection-title">Key Operational Constraints</h2>

                <table class="spec-table">
                    <tbody>
                        <tr>
                            <td>Apparatus Compartment Constraints</td>
                            <td>Mid-mount ladder trucks with limited compartment space — compact retracted state required</td>
                        </tr>
                        <tr>
                            <td>Urban Deployment Environment</td>
                            <td>Narrow streets, high-density corridors, barrier-island geography — rapid deployment essential</td>
                        </tr>
                        <tr>
                            <td>Coastal / Saltwater Exposure</td>
                            <td>Proximity to Atlantic Ocean and Biscayne Bay — IP58 watertight ratings operationally significant</td>
                        </tr>
                        <tr>
                            <td>Multi-Apparatus Tool Strategy</td>
                            <td>Frontline 32-inch set (spreader, cutter, ram) on primary apparatus; supplemental heavy tools on 300's new truck</td>
                        </tr>
                        <tr>
                            <td>Operational Tempo</td>
                            <td>Cordless battery operation required — eliminates hose-management overhead of traditional hydraulic systems</td>
                        </tr>
                    </tbody>
                </table>

                <img class="report-image" src="/workgroup-report/images/final_workgroup_results_p2_i9.png"
                     alt="Evaluation Framework Detail"
                     onerror="this.style.display='none'">
            </section>

            <!-- ══ SECTION 3: Analysis ══ -->
            <section class="section" id="sec3">
                <h1 class="section-title"><span class="section-num">3</span> Analysis</h1>

                <h2 class="subsection-title">3A. Evaluation Framework</h2>

                <p>The workgroup evaluation employed a standardized scoring model applied consistently across all 14 products. Evaluators assessed each tool during hands-on testing sessions documented across multiple evaluation days.</p>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Dimension</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Capability</strong></td>
                            <td>Raw performance metrics — force output, cutting capacity, spreading distance, operational power</td>
                        </tr>
                        <tr>
                            <td><strong>Usability</strong></td>
                            <td>Ergonomic design, control interface, operator comfort under sustained use, learning curve</td>
                        </tr>
                        <tr>
                            <td><strong>Maintainability</strong></td>
                            <td>Service requirements, durability, component accessibility, field-repairability</td>
                        </tr>
                        <tr>
                            <td><strong>Deployability</strong></td>
                            <td>Storage footprint, activation speed, weight, cordless readiness, compartment compatibility</td>
                        </tr>
                    </tbody>
                </table>

                <p>Each dimension was scored on a 0–100 scale. A composite <strong>Overall Score</strong> was computed as a weighted aggregate reflecting operational priorities for frontline deployment.</p>

                <hr class="section-divider">
                <h2 class="subsection-title">3B. Brand Performance</h2>

                <div class="chart-section">
                    <div class="chart-title">Brand Average Composite Scores (All Categories)</div>
                    <div class="chart-container">
                        <canvas id="brandChart"></canvas>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Brand</th>
                            <th>Products Evaluated</th>
                            <th class="right">Avg. Composite Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>DeWalt</strong></td>
                            <td>1</td>
                            <td class="right"><span class="score-badge score-elite">91.25</span></td>
                        </tr>
                        <tr>
                            <td><strong>Holmatro</strong></td>
                            <td>6</td>
                            <td class="right"><span class="score-badge score-elite">87.93</span></td>
                        </tr>
                        <tr>
                            <td><strong>Hurst</strong></td>
                            <td>4</td>
                            <td class="right"><span class="score-badge score-high">84.60</span></td>
                        </tr>
                        <tr>
                            <td><strong>Paratech</strong></td>
                            <td>1</td>
                            <td class="right"><span class="score-badge score-mid">76.13</span></td>
                        </tr>
                        <tr>
                            <td><strong>Makita</strong></td>
                            <td>1</td>
                            <td class="right"><span class="score-badge score-low">64.83</span></td>
                        </tr>
                        <tr>
                            <td><strong>Husqvarna</strong></td>
                            <td>1</td>
                            <td class="right"><span class="score-badge score-low">61.78</span></td>
                        </tr>
                    </tbody>
                </table>

                <h3 class="sub-subsection">Frontline Hydraulic Tool Dimension Comparison (Holmatro vs. Hurst)</h3>

                <div class="chart-section">
                    <div class="chart-title">Four-Dimension Breakdown — Holmatro Pentheon vs. Hurst E3</div>
                    <div class="chart-container">
                        <canvas id="dimensionChart"></canvas>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Dimension</th>
                            <th class="right">Holmatro (Pentheon)</th>
                            <th class="right">Hurst (E3)</th>
                            <th class="right">Δ Delta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Capability</strong></td>
                            <td class="right">89.41</td>
                            <td class="right">87.56</td>
                            <td class="right" style="color:var(--color-emerald)">+1.85</td>
                        </tr>
                        <tr>
                            <td><strong>Usability</strong></td>
                            <td class="right">93.99</td>
                            <td class="right">85.76</td>
                            <td class="right" style="color:var(--color-emerald)">+8.23</td>
                        </tr>
                        <tr>
                            <td><strong>Maintainability</strong></td>
                            <td class="right">89.82</td>
                            <td class="right">82.58</td>
                            <td class="right" style="color:var(--color-emerald)">+7.24</td>
                        </tr>
                        <tr>
                            <td><strong>Deployability</strong></td>
                            <td class="right">89.69</td>
                            <td class="right">82.74</td>
                            <td class="right" style="color:var(--color-emerald)">+6.95</td>
                        </tr>
                    </tbody>
                </table>

                <p>Holmatro leads across all four dimensions, with the largest gap in <strong>Usability (+8.23 points)</strong>, attributable to the 360-degree inline control handle design and On-Tool Charging system.</p>

                <img class="report-image" src="/workgroup-report/images/Holmatro Pentheon Series USA-1_p0_i4.png"
                     alt="Holmatro Pentheon Series Overview"
                     onerror="this.style.display='none'">
                <div class="image-caption">Holmatro Pentheon Series — Full product line overview</div>

                <hr class="section-divider">
                <h2 class="subsection-title">3C. Frontline Tools</h2>

                <h3 class="sub-subsection">Spreaders</h3>

                <div class="winner-banner">
                    <div class="winner-info">
                        <div class="winner-label">Category Leader — Spreaders</div>
                        <div class="winner-name">Holmatro PSP40 (32-inch Spreader)</div>
                        <div class="winner-desc">360° inline control handle · Extreme Grip Spreader Tips · IP57 watertight</div>
                    </div>
                    <div class="winner-score-box">
                        <div class="winner-score-value">92.02</div>
                        <div class="winner-score-label">Composite Score</div>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Manufacturer</th>
                            <th class="right">Score</th>
                            <th class="right">Capability</th>
                            <th class="right">Usability</th>
                            <th class="right">Deploy.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>PSP40 (32-inch Spreader)</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td class="right"><span class="score-badge score-elite">92.02</span></td>
                            <td class="right">89.41</td>
                            <td class="right">93.99</td>
                            <td class="right">89.69</td>
                        </tr>
                        <tr>
                            <td><strong>SP 777 E3 (32-inch Spreader)</strong></td>
                            <td><span class="brand-pill">Hurst</span></td>
                            <td class="right"><span class="score-badge score-elite">90.99</span></td>
                            <td class="right">87.56</td>
                            <td class="right">85.76</td>
                            <td class="right">82.74</td>
                        </tr>
                    </tbody>
                </table>

                <p>The PSP40 achieved the highest composite score among all frontline tools at <strong>92.02</strong>, leading the Hurst SP 777 E3 by 1.03 points overall. The score gap is narrowest in Capability (Δ 1.85) and widest in Usability (Δ 8.23). The Hurst SP 777 E3 counters with a higher maximum spreading force (600 kN vs. 280 kN) and IP58 watertight rating for saltwater operations.</p>

                <img class="report-image" src="/workgroup-report/images/Holmatro Pentheon Series USA-1_p11_i2.png"
                     alt="Holmatro Pentheon Spreader"
                     onerror="this.style.display='none'">
                <div class="image-caption">Holmatro PSP40 Spreader — Pentheon Series</div>

                <h3 class="sub-subsection">Cutters</h3>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Manufacturer</th>
                            <th class="right">Score</th>
                            <th class="right">Capability</th>
                            <th class="right">Usability</th>
                            <th class="right">Deploy.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>PCU30CL (Cutter)</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td class="right"><span class="score-badge score-elite">88.86</span></td>
                            <td class="right">89.41</td>
                            <td class="right">93.99</td>
                            <td class="right">89.69</td>
                        </tr>
                        <tr>
                            <td><strong>S 789 E3 (Cutter)</strong></td>
                            <td><span class="brand-pill">Hurst</span></td>
                            <td class="right"><span class="score-badge score-high">82.57</span></td>
                            <td class="right">87.56</td>
                            <td class="right">85.76</td>
                            <td class="right">82.74</td>
                        </tr>
                    </tbody>
                </table>

                <p>The Holmatro PCU30CL leads by <strong>6.29 points</strong> — the largest gap among the three frontline tool categories. The PCU30CL's 30-degree inclined jaw design maximizes working space between tool and vehicle. The Hurst S 789 E3 offers a larger cutting opening (8.07 in vs. 6.7 in), but tracked lower in ergonomic usability under continuous load.</p>

                <img class="report-image" src="/workgroup-report/images/Holmatro Pentheon Series USA-1_p13_i14.png"
                     alt="Holmatro Pentheon Cutter"
                     onerror="this.style.display='none'">
                <div class="image-caption">Holmatro PCU30CL Cutter — Pentheon Series</div>

                <h3 class="sub-subsection">Rams</h3>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Manufacturer</th>
                            <th class="right">Score</th>
                            <th class="right">Capability</th>
                            <th class="right">Usability</th>
                            <th class="right">Deploy.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>PRA40 (Ram)</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td class="right"><span class="score-badge score-elite">91.29</span></td>
                            <td class="right">89.41</td>
                            <td class="right">93.99</td>
                            <td class="right">89.69</td>
                        </tr>
                        <tr>
                            <td><strong>CR 522 E3 (Ram)</strong></td>
                            <td><span class="brand-pill">Hurst</span></td>
                            <td class="right"><span class="score-badge score-elite">86.02</span></td>
                            <td class="right">87.56</td>
                            <td class="right">85.76</td>
                            <td class="right">82.74</td>
                        </tr>
                    </tbody>
                </table>

                <p>The Holmatro PRA40 leads by <strong>5.27 points</strong>. The PRA40's integrated laser pointer and compact retracted length of only 15.2 inches make it particularly suited to mid-mount apparatus compartment constraints. At 31.1 lbs, it is 13.9 lbs lighter than the Hurst CR 522 E3. The Hurst CR 522 E3 counters with an extended length of 59.2 inches and IP58 watertight rating for saltwater deployment.</p>

                <div class="chart-section">
                    <div class="chart-title">Frontline Tool Composite Scores — All Categories &amp; Brands</div>
                    <div class="chart-container tall">
                        <canvas id="frontlineChart"></canvas>
                    </div>
                </div>

                <hr class="section-divider">
                <h2 class="subsection-title">3D. Rotary Cut-Off Saws</h2>

                <div class="winner-banner">
                    <div class="winner-info">
                        <div class="winner-label">Category Leader — Rotary Cut-Off Saws</div>
                        <div class="winner-name">DeWalt DCPS612AG2 12-inch Cut-Off Saw</div>
                        <div class="winner-desc">Gear-driven · 3-second electric brake · Integrated base wheels · Superior ergonomics</div>
                    </div>
                    <div class="winner-score-box">
                        <div class="winner-score-value">91.25</div>
                        <div class="winner-score-label">Composite Score</div>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Manufacturer</th>
                            <th>Blade</th>
                            <th class="right">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>DCPS612AG2 (12-inch Cut-Off Saw)</strong></td>
                            <td><span class="brand-pill">DeWalt</span></td>
                            <td>12"</td>
                            <td class="right"><span class="score-badge score-elite">91.25</span></td>
                        </tr>
                        <tr>
                            <td><strong>GEC01PL4 (14-inch Power Cutter)</strong></td>
                            <td><span class="brand-pill">Makita</span></td>
                            <td>14"</td>
                            <td class="right"><span class="score-badge score-low">64.83</span></td>
                        </tr>
                        <tr>
                            <td><strong>K1 Pace (14-inch Rescue Saw)</strong></td>
                            <td><span class="brand-pill">Husqvarna</span></td>
                            <td>14"</td>
                            <td class="right"><span class="score-badge score-low">61.78</span></td>
                        </tr>
                    </tbody>
                </table>

                <div class="callout note">
                    <div class="callout-label">Analysis Note</div>
                    Despite using a 12-inch blade against 14-inch competitors, the DeWalt achieved a margin of <strong>26.42 points</strong> (vs. Makita) and <strong>29.47 points</strong> (vs. Husqvarna). Gear-driven power delivery and instant electric brake rendered larger competitors "functionally obsolete in high-speed tactical scenarios."
                </div>

                <img class="report-image" src="/workgroup-report/images/DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine-1_p0_i0.png"
                     alt="DeWalt POWERSHIFT 12-inch Cut-Off Saw"
                     onerror="this.style.display='none'">
                <div class="image-caption">DeWalt DCPS612AG2 POWERSHIFT 12-inch Cut-Off Saw</div>

                <hr class="section-divider">
                <h2 class="subsection-title">3E. Vehicle Stabilization</h2>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Manufacturer</th>
                            <th>Mechanism</th>
                            <th>Weight</th>
                            <th class="right">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>V-Strut (Auto-locking)</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td>Auto-lock mechanical</td>
                            <td>15.87 lbs</td>
                            <td class="right"><span class="score-badge score-elite">87.28</span></td>
                        </tr>
                        <tr>
                            <td><strong>OmniShore (Pneumatic)</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td>Pneumatic (OmniLock)</td>
                            <td>—</td>
                            <td class="right"><span class="score-badge score-high">85.87</span></td>
                        </tr>
                        <tr>
                            <td><strong>StrutDriver (Mechanical)</strong></td>
                            <td><span class="brand-pill">Paratech</span></td>
                            <td>Manual mechanical</td>
                            <td>22.57 lbs</td>
                            <td class="right"><span class="score-badge score-mid">76.13</span></td>
                        </tr>
                    </tbody>
                </table>

                <p>The V-Strut's auto-lock system enables setup in just <strong>15 seconds</strong> — pull out and locks automatically in one movement. At 15.87 lbs, it is the lightest option. The OmniShore provides maximum versatility (shoring range 28 cm to 5.2 m) but has a documented deal-breaker concern noted in workgroup review. The Paratech provides 6,000 lb lifting capacity but scored lowest in the category.</p>

                <div class="image-grid">
                    <div>
                        <img class="report-image" src="/workgroup-report/images/Holmatro omnishore-1_p1_i55.png"
                             alt="Holmatro OmniShore Stabilization System"
                             onerror="this.style.display='none'">
                        <div class="image-caption">Holmatro OmniShore — Pneumatic System</div>
                    </div>
                    <div>
                        <img class="report-image" src="/workgroup-report/images/Holmatro omnishore-1_p4_i82.png"
                             alt="Holmatro OmniShore Detail"
                             onerror="this.style.display='none'">
                        <div class="image-caption">Holmatro OmniShore — System Detail</div>
                    </div>
                </div>

                <hr class="section-divider">
                <h2 class="subsection-title">3F. Specialty Tools</h2>

                <h3 class="sub-subsection">Holmatro T1 — Forcible Entry Tool</h3>

                <table class="spec-table">
                    <tbody>
                        <tr><td>Composite Score</td><td><span class="score-badge score-high">82.23</span></td></tr>
                        <tr><td>Weight</td><td>17.0 lbs (7.7 kg)</td></tr>
                        <tr><td>Spreading Force</td><td>33.0 kN (3.4 ton hydraulic)</td></tr>
                        <tr><td>Cutting Force</td><td>139.0 kN (14.2 ton hydraulic)</td></tr>
                        <tr><td>Opening Width</td><td>27.9 mm (1.1 inches)</td></tr>
                        <tr><td>Power Source</td><td>Manual hydraulic (2-stage hand pump)</td></tr>
                        <tr><td>Functions</td><td>Cut, Wedge, Ram, Spread, Hammer, Lift (6-in-1)</td></tr>
                    </tbody>
                </table>

                <p>The T1 is a six-function-in-one tool designed for rapid entry operations. Self-contained — no batteries, no external pump, no hoses. A 30 kg manual force on the pump rod yields up to 14.2 ton hydraulic cutting force. Documented deal-breaker concerns were noted in the workgroup evaluation.</p>

                <h3 class="sub-subsection">Hurst M40 — 40-inch Spreader (Supplemental)</h3>

                <table class="spec-table">
                    <tbody>
                        <tr><td>Composite Score</td><td><span class="score-badge score-mid">78.80</span></td></tr>
                        <tr><td>Capability Score</td><td>83.39</td></tr>
                        <tr><td>Usability Score</td><td>79.29 <em style="color:var(--color-slate-400); font-size:0.8em">(lowest in dataset)</em></td></tr>
                        <tr><td>Spreading Distance</td><td>40 inches (vs. standard 32-inch frontline)</td></tr>
                        <tr><td>Power Source</td><td>Battery (eDRAULIC E3)</td></tr>
                        <tr><td>IP Rating</td><td>IP58 (watertight for saltwater ops)</td></tr>
                        <tr><td>Deployment Assignment</td><td>300's new truck (supplemental heavy-duty)</td></tr>
                    </tbody>
                </table>

                <div class="callout warning">
                    <div class="callout-label">Deployment Note</div>
                    The M40 is designated as a supplemental tool for <strong>300's new truck</strong> (the shift battalion chief's vehicle, currently having emergency lighting installed at Fleet). Its immense physical footprint limits Usability to 79.29 — the lowest Usability score in the entire dataset — making it incompatible with standard frontline apparatus compartment constraints.
                </div>
            </section>

            <!-- ══ SECTION 4: Findings ══ -->
            <section class="section" id="sec4">
                <h1 class="section-title"><span class="section-num">4</span> Findings</h1>

                <h2 class="subsection-title">4A. Top Performers</h2>

                <h3 class="sub-subsection">Top 2 Brands — Frontline Hydraulic Tools</h3>

                <div class="winner-banner">
                    <div class="winner-info">
                        <div class="winner-label">#1 Brand — Frontline Hydraulic Tools</div>
                        <div class="winner-name">Holmatro (Pentheon Series)</div>
                        <div class="winner-desc">Led all 4 scoring dimensions · Usability advantage +8.23 pts · On-Tool Charging · 360° handle</div>
                    </div>
                    <div class="winner-score-box">
                        <div class="winner-score-value">90.72</div>
                        <div class="winner-score-label">Frontline Average</div>
                    </div>
                </div>

                <div class="winner-banner" style="background: linear-gradient(135deg, #1e293b, #334155);">
                    <div class="winner-info">
                        <div class="winner-label" style="color:#60a5fa">#2 Brand — Frontline Hydraulic Tools</div>
                        <div class="winner-name">Hurst (eDRAULIC E3 Series)</div>
                        <div class="winner-desc">Competitive Capability (87.56) · IP58 watertight · E3 Connect Wi-Fi + Captium cloud management</div>
                    </div>
                    <div class="winner-score-box">
                        <div class="winner-score-value" style="color:#60a5fa">86.53</div>
                        <div class="winner-score-label">Frontline Average</div>
                    </div>
                </div>

                <h3 class="sub-subsection">Top Tool Per Category</h3>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Top Tool</th>
                            <th>Manufacturer</th>
                            <th class="right">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Spreaders</td>
                            <td><strong>Holmatro PSP40</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td class="right"><span class="score-badge score-elite">92.02</span></td>
                        </tr>
                        <tr>
                            <td>Cutters</td>
                            <td><strong>Holmatro PCU30CL</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td class="right"><span class="score-badge score-elite">88.86</span></td>
                        </tr>
                        <tr>
                            <td>Rams</td>
                            <td><strong>Holmatro PRA40</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td class="right"><span class="score-badge score-elite">91.29</span></td>
                        </tr>
                        <tr>
                            <td>Rotary Saws</td>
                            <td><strong>DeWalt DCPS612AG2</strong></td>
                            <td><span class="brand-pill">DeWalt</span></td>
                            <td class="right"><span class="score-badge score-elite">91.25</span></td>
                        </tr>
                        <tr>
                            <td>Stabilization</td>
                            <td><strong>Holmatro V-Strut</strong></td>
                            <td><span class="brand-pill">Holmatro</span></td>
                            <td class="right"><span class="score-badge score-elite">87.28</span></td>
                        </tr>
                    </tbody>
                </table>

                <img class="report-image" src="/workgroup-report/images/final_workgroup_results_p1_i6.png"
                     alt="Workgroup Top Performer Analysis"
                     onerror="this.style.display='none'">

                <h2 class="subsection-title">4B. Cross-Category Insights</h2>

                <h3 class="sub-subsection">Ergonomics as a Performance Driver</h3>
                <p>Across all categories, products with superior ergonomic design consistently scored higher. Holmatro's 360-degree inline control handle contributed to a <strong>93.99 Usability score</strong> — the highest dimension score in the entire dataset. The DeWalt saw's gear-driven design similarly delivered category dominance despite a smaller blade diameter.</p>

                <h3 class="sub-subsection">Deployment Speed Impact</h3>
                <table class="data-table">
                    <thead>
                        <tr><th>Category</th><th>Key Deployment Differentiator</th><th class="right">Score Advantage</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Saws</td>
                            <td>Gear-driven instant-start + 3-second electric brake</td>
                            <td class="right" style="color:var(--color-emerald)">+26.42 to +29.47 pts</td>
                        </tr>
                        <tr>
                            <td>Frontline Tools</td>
                            <td>On-Tool Charging + cordless auto start/stop (Holmatro vs. Hurst)</td>
                            <td class="right" style="color:var(--color-emerald)">+6.95 pts (Deploy.)</td>
                        </tr>
                        <tr>
                            <td>Stabilization</td>
                            <td>15-second auto-lock deployment (V-Strut vs. competitors)</td>
                            <td class="right" style="color:var(--color-emerald)">+1.41 to +11.15 pts</td>
                        </tr>
                    </tbody>
                </table>

                <h3 class="sub-subsection">Tool Weight vs. Performance Correlation</h3>
                <table class="data-table">
                    <thead>
                        <tr><th>Product</th><th class="right">Weight (lbs)</th><th class="right">Score</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Holmatro PRA40 (Ram)</td><td class="right">31.1</td><td class="right"><span class="score-badge score-elite">91.29</span></td></tr>
                        <tr><td>Hurst CR 522 E3 (Ram)</td><td class="right">45.0</td><td class="right"><span class="score-badge score-elite">86.02</span></td></tr>
                        <tr><td>Holmatro V-Strut</td><td class="right">15.87</td><td class="right"><span class="score-badge score-elite">87.28</span></td></tr>
                        <tr><td>Paratech StrutDriver</td><td class="right">22.57</td><td class="right"><span class="score-badge score-mid">76.13</span></td></tr>
                        <tr><td>Hurst M40 (Heavy Spreader)</td><td class="right">—</td><td class="right"><span class="score-badge score-mid">78.80</span></td></tr>
                    </tbody>
                </table>
            </section>

            <!-- ══ SECTION 5: Controlled Considerations ══ -->
            <section class="section" id="sec5">
                <h1 class="section-title"><span class="section-num">5</span> Controlled Considerations</h1>

                <div class="callout note">
                    <div class="callout-label">Scope Note</div>
                    This section contains <strong>descriptive analysis only</strong> — no recommendations, no procurement language, no purchasing strategies. Per workgroup protocol.
                </div>

                <h2 class="subsection-title">T1 Tool — Rabbit Tool Replacement Analysis</h2>

                <p>The Holmatro T1 Forcible Entry Tool (composite score: <strong>82.23</strong>) was evaluated specifically as a <strong>potential replacement for the traditional rabbit tool</strong> (halligan bar/flathead axe combination) in forced-entry operations.</p>

                <table class="spec-table">
                    <tbody>
                        <tr>
                            <td>Door Forcing</td>
                            <td>T1's hydraulic wedging (3.4 ton) vs. rabbit tool's manual prying — hydraulic force instead of striking</td>
                        </tr>
                        <tr>
                            <td>Solo Operation</td>
                            <td>T1's detachable wedge enables single-operator entry — rabbit tool requires 2-person team</td>
                        </tr>
                        <tr>
                            <td>Cutting Capability</td>
                            <td>T1 adds hydraulic cutting (139.0 kN) — rabbit tool provides none</td>
                        </tr>
                        <tr>
                            <td>Tool Consolidation</td>
                            <td>T1 replaces halligan bar + flathead axe with one 17-lb tool</td>
                        </tr>
                        <tr>
                            <td>Limitations</td>
                            <td>Manual hydraulic — no battery; limited cutting opening 1.1 inches; documented deal-breaker concerns</td>
                        </tr>
                    </tbody>
                </table>

                <h2 class="subsection-title">M40 — 300's Truck Supplemental Deployment</h2>

                <p>The Hurst M40 (40-inch Spreader, composite score: <strong>78.80</strong>) was evaluated as a <strong>supplemental heavy-duty spreader</strong> to be deployed on dedicated apparatus — specifically <strong>300's new truck</strong> — in addition to the standard frontline extrication set.</p>

                <p>The M40's 40-inch spreading distance enables operations in scenarios exceeding standard 32-inch spreader parameters, including large commercial vehicle extrication, structural collapse scenarios, and multi-vehicle incidents. Its IP58 watertight design is significant for MBFD's coastal/saltwater operating environment.</p>

                <p>The workgroup determined the M40 is incompatible with standard frontline apparatus compartments. <strong>300's new truck</strong> — currently being outfitted with emergency lighting at Fleet — provides the dedicated compartment space required for M40 deployment alongside the standard extrication tool complement.</p>
            </section>

            <!-- ══ APPENDIX ══ -->
            <section class="section" id="appendix">
                <h1 class="section-title"><span class="section-num">A</span> Appendix: Data Source Verification</h1>

                <div class="callout note">
                    <div class="callout-label">Data Integrity</div>
                    All numerical values in this report are sourced directly from <strong>Product_Data_Master.csv</strong> (14 rows of product data, 31 columns). No values have been interpolated, estimated, or sourced from external references.
                </div>

                <div class="appendix-label">Complete Product Registry — 14 Evaluated Products</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Manufacturer</th>
                            <th class="right">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>2</td><td>Holmatro PSP40 (32-inch Spreader)</td><td>Hydraulic Tool</td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-elite">92.02</span></td></tr>
                        <tr><td>3</td><td>Holmatro PCU30CL (Cutter)</td><td>Hydraulic Tool</td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-elite">88.86</span></td></tr>
                        <tr><td>4</td><td>Holmatro PRA40 (Ram)</td><td>Hydraulic Tool</td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-elite">91.29</span></td></tr>
                        <tr><td>5</td><td>Hurst SP 777 E3 (32-inch Spreader)</td><td>Hydraulic Tool</td><td><span class="brand-pill">Hurst</span></td><td class="right"><span class="score-badge score-elite">90.99</span></td></tr>
                        <tr><td>6</td><td>Hurst S 789 E3 (Cutter)</td><td>Hydraulic Tool</td><td><span class="brand-pill">Hurst</span></td><td class="right"><span class="score-badge score-high">82.57</span></td></tr>
                        <tr><td>7</td><td>Hurst CR 522 E3 (Ram)</td><td>Hydraulic Tool</td><td><span class="brand-pill">Hurst</span></td><td class="right"><span class="score-badge score-elite">86.02</span></td></tr>
                        <tr><td>8</td><td>DeWalt DCPS612AG2 (12-inch Cut-Off Saw)</td><td>Rescue Saw</td><td><span class="brand-pill">DeWalt</span></td><td class="right"><span class="score-badge score-elite">91.25</span></td></tr>
                        <tr><td>9</td><td>Makita GEC01PL4 (14-inch Power Cutter)</td><td>Rescue Saw</td><td><span class="brand-pill">Makita</span></td><td class="right"><span class="score-badge score-low">64.83</span></td></tr>
                        <tr><td>10</td><td>Husqvarna K1 Pace (14-inch Rescue Saw)</td><td>Rescue Saw</td><td><span class="brand-pill">Husqvarna</span></td><td class="right"><span class="score-badge score-low">61.78</span></td></tr>
                        <tr><td>11</td><td>Holmatro V-Strut (Auto-locking)</td><td>Stabilization</td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-elite">87.28</span></td></tr>
                        <tr><td>12</td><td>Holmatro OmniShore (Pneumatic)</td><td>Stabilization</td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-high">85.87</span></td></tr>
                        <tr><td>13</td><td>Paratech StrutDriver (Mechanical)</td><td>Stabilization</td><td><span class="brand-pill">Paratech</span></td><td class="right"><span class="score-badge score-mid">76.13</span></td></tr>
                        <tr><td>14</td><td>Holmatro T1 Forcible Entry Tool</td><td>Hydraulic Tool</td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-high">82.23</span></td></tr>
                        <tr><td>15</td><td>Hurst M40 (40-inch Spreader)</td><td>Hydraulic Tool</td><td><span class="brand-pill">Hurst</span></td><td class="right"><span class="score-badge score-mid">78.80</span></td></tr>
                    </tbody>
                </table>
            </section>
        </div>

        <!-- Footer -->
        <div class="report-footer">
            <span>Miami Beach Fire Department · Workgroup Evaluation Division</span>
            <span>Report Generated: March 18, 2026 · Data: Product_Data_Master.csv</span>
        </div>
    </div>

    <script>
        // Chart.js global defaults
        Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.95)';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 6;

        const NAVY = '#0f172a';
        const RED = '#dc2626';
        const BLUE = '#2563eb';
        const GREEN = '#059669';
        const AMBER = '#d97706';
        const SLATE = '#64748b';

        // 1. Brand Composite Scores
        new Chart(document.getElementById('brandChart'), {
            type: 'bar',
            data: {
                labels: ['DeWalt', 'Holmatro', 'Hurst', 'Paratech', 'Makita', 'Husqvarna'],
                datasets: [{
                    label: 'Average Composite Score',
                    data: [91.25, 87.93, 84.60, 76.13, 64.83, 61.78],
                    backgroundColor: [GREEN, BLUE, '#0ea5e9', AMBER, '#f87171', '#fca5a5'],
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 50,
                        max: 100,
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { font: { size: 12 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 12, weight: '600' } }
                    }
                }
            }
        });

        // 2. Four-Dimension Comparison
        new Chart(document.getElementById('dimensionChart'), {
            type: 'bar',
            data: {
                labels: ['Capability', 'Usability', 'Maintainability', 'Deployability'],
                datasets: [
                    {
                        label: 'Holmatro Pentheon',
                        data: [89.41, 93.99, 89.82, 89.69],
                        backgroundColor: BLUE,
                        borderRadius: 4
                    },
                    {
                        label: 'Hurst E3',
                        data: [87.56, 85.76, 82.58, 82.74],
                        backgroundColor: '#60a5fa',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 12 }, color: NAVY }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 75,
                        max: 100,
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { font: { size: 12 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 12 } }
                    }
                }
            }
        });

        // 3. Frontline Tool Scores
        new Chart(document.getElementById('frontlineChart'), {
            type: 'bar',
            data: {
                labels: ['Spreader\n(Holmatro)', 'Spreader\n(Hurst)', 'Cutter\n(Holmatro)', 'Cutter\n(Hurst)', 'Ram\n(Holmatro)', 'Ram\n(Hurst)'],
                datasets: [{
                    label: 'Composite Score',
                    data: [92.02, 90.99, 88.86, 82.57, 91.29, 86.02],
                    backgroundColor: [BLUE, '#93c5fd', BLUE, '#93c5fd', BLUE, '#93c5fd'],
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 78,
                        max: 95,
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { font: { size: 12 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 }, maxRotation: 0, minRotation: 0 }
                    }
                }
            }
        });
    </script>
</body>
</html>