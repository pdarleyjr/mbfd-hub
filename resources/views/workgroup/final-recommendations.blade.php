<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MBFD Workgroup Final Recommendations — Final Equipment Selection &amp; Implementation Report</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --navy: #0f172a;
            --navy-mid: #1e293b;
            --navy-light: #334155;
            --slate-600: #475569;
            --slate-500: #64748b;
            --slate-400: #94a3b8;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50: #f8fafc;
            --red: #dc2626;
            --red-light: #fee2e2;
            --emerald: #059669;
            --emerald-light: #d1fae5;
            --blue: #2563eb;
            --blue-light: #dbeafe;
            --amber: #d97706;
            --amber-light: #fef3c7;
            --gold: #b45309;
            --gold-bg: #fffbeb;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--slate-100);
            color: #1a202c;
            font-size: 15px;
            line-height: 1.65;
        }

        /* ── PRINT BAR ── */
        .print-bar {
            position: sticky; top: 0; z-index: 100;
            background: var(--navy);
            padding: 0.625rem 2rem;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .print-bar-title { color: rgba(255,255,255,0.7); font-size: 0.8125rem; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase; }
        .print-btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: var(--red); color: white; border: none;
            padding: 0.5rem 1.25rem; border-radius: 0.5rem;
            font-family: inherit; font-size: 0.875rem; font-weight: 600;
            cursor: pointer; transition: background 150ms;
        }
        .print-btn:hover { background: #b91c1c; }
        .print-btn svg { width: 1rem; height: 1rem; }

        /* ── WRAPPER ── */
        .report-wrapper {
            max-width: 900px; margin: 2rem auto;
            background: white; border-radius: 0.75rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08); overflow: hidden;
        }

        /* ── COVER ── */
        .report-cover {
            background: linear-gradient(135deg, var(--navy) 0%, #1a3a5c 100%);
            padding: 3rem 3.5rem 2.5rem; position: relative; overflow: hidden;
        }
        .report-cover::before {
            content: ''; position: absolute; top: -40px; right: -40px;
            width: 280px; height: 280px; background: rgba(220,38,38,0.1); border-radius: 50%;
        }
        .final-stamp {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: var(--red); color: white;
            padding: 0.375rem 1rem; border-radius: 0.375rem;
            font-size: 0.75rem; font-weight: 800; letter-spacing: 0.12em;
            text-transform: uppercase; margin-bottom: 1.25rem;
            position: relative; z-index: 1;
        }
        .cover-agency {
            display: flex; align-items: center; gap: 1rem;
            margin-bottom: 1.5rem; position: relative; z-index: 1;
        }
        .cover-badge {
            width: 48px; height: 48px; background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 0.625rem; display: flex; align-items: center; justify-content: center;
        }
        .cover-agency-text { color: rgba(255,255,255,0.9); font-size: 0.875rem; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; }
        .cover-agency-sub { color: rgba(255,255,255,0.5); font-size: 0.75rem; }
        .cover-title { color: white; font-size: 2.125rem; font-weight: 800; line-height: 1.2; margin-bottom: 0.625rem; position: relative; z-index: 1; }
        .cover-subtitle { color: rgba(255,255,255,0.65); font-size: 0.9375rem; margin-bottom: 1.75rem; position: relative; z-index: 1; }
        .cover-recipients {
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12);
            border-radius: 0.5rem; padding: 1rem 1.25rem;
            margin-bottom: 1.75rem; position: relative; z-index: 1;
        }
        .cover-recipients-label { color: rgba(255,255,255,0.4); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem; }
        .cover-recipients ul { list-style: none; }
        .cover-recipients li { color: rgba(255,255,255,0.85); font-size: 0.875rem; padding: 0.125rem 0; }
        .cover-meta { display: flex; gap: 2rem; flex-wrap: wrap; position: relative; z-index: 1; }
        .cover-meta-item { display: flex; flex-direction: column; gap: 0.25rem; }
        .cover-meta-label { color: rgba(255,255,255,0.45); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.1em; }
        .cover-meta-value { color: rgba(255,255,255,0.9); font-size: 0.875rem; font-weight: 600; }

        /* ── BODY ── */
        .report-body { padding: 2.5rem 3.5rem; }

        /* ── SELECTIONS SUMMARY ── */
        .selections-summary {
            background: linear-gradient(135deg, #fffbeb, #fef9c3);
            border: 2px solid #fcd34d; border-radius: 0.75rem;
            padding: 1.5rem; margin-bottom: 2.5rem;
        }
        .selections-summary-title {
            font-size: 0.75rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.1em; color: var(--gold); margin-bottom: 1rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .selections-summary-title::before { content: '✓'; font-size: 1rem; }

        /* ── TOC ── */
        .toc {
            background: var(--slate-50); border: 1px solid var(--slate-200);
            border-radius: 0.5rem; padding: 1.5rem; margin-bottom: 2.5rem;
        }
        .toc-title { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--slate-500); margin-bottom: 1rem; }
        .toc-list { list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: 0.375rem; }
        .toc-list li a { color: var(--blue); text-decoration: none; font-size: 0.875rem; font-weight: 500; display: flex; gap: 0.5rem; }
        .toc-list li a:hover { text-decoration: underline; }
        .toc-num { color: var(--slate-400); min-width: 1.5rem; }

        /* ── SECTIONS ── */
        .section { margin-bottom: 2.5rem; }
        .section + .section { border-top: 1px solid var(--slate-200); padding-top: 2.5rem; }
        h1.section-title {
            font-size: 1.5rem; font-weight: 800; color: var(--navy);
            display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;
        }
        h1.section-title .section-num {
            background: var(--red); color: white; width: 2rem; height: 2rem;
            border-radius: 0.375rem; display: flex; align-items: center; justify-content: center;
            font-size: 0.875rem; font-weight: 700; flex-shrink: 0;
        }
        h2.subsection-title {
            font-size: 1.0625rem; font-weight: 700; color: var(--navy-mid);
            margin: 1.5rem 0 0.75rem; padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--slate-200);
        }
        h3.sub-subsection { font-size: 0.9375rem; font-weight: 700; color: var(--navy-light); margin: 1.25rem 0 0.5rem; }
        p { color: #374151; margin-bottom: 0.875rem; }
        p:last-child { margin-bottom: 0; }
        strong { color: var(--navy); font-weight: 600; }

        /* ── SELECTION CARD ── */
        .selection-card {
            border: 1px solid var(--slate-200); border-radius: 0.625rem;
            overflow: hidden; margin: 1.25rem 0;
        }
        .selection-card-header {
            display: flex; align-items: center; justify-content: space-between;
            background: var(--navy); color: white; padding: 0.875rem 1.25rem;
        }
        .selection-card-label {
            font-size: 0.6875rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.1em; color: rgba(255,255,255,0.5); margin-bottom: 0.125rem;
        }
        .selection-card-name { font-size: 1.0625rem; font-weight: 700; }
        .selection-status {
            display: inline-flex; align-items: center; gap: 0.375rem;
            padding: 0.25rem 0.75rem; border-radius: 9999px;
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
            flex-shrink: 0;
        }
        .status-selected { background: var(--emerald-light); color: #065f46; }
        .status-trial { background: var(--amber-light); color: #92400e; }
        .status-pending { background: var(--blue-light); color: #1e40af; }
        .selection-card-body { padding: 1.125rem 1.25rem; background: white; }
        .selection-card-image { width: 100%; max-height: 260px; object-fit: contain; background: var(--slate-50); display: block; }

        /* ── DEPLOYMENT TIER ── */
        .deployment-tier {
            border: 1px solid var(--slate-200); border-radius: 0.625rem;
            overflow: hidden; margin: 1rem 0;
        }
        .tier-header {
            padding: 0.875rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .tier-header.frontline { background: linear-gradient(135deg, #1e3a5f, #1e40af); }
        .tier-header.command { background: linear-gradient(135deg, var(--navy), #374151); }
        .tier-header.heavy { background: linear-gradient(135deg, #1c1917, #292524); }
        .tier-title { color: white; font-weight: 700; font-size: 1rem; }
        .tier-label { color: rgba(255,255,255,0.5); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; }
        .tier-badge {
            background: rgba(255,255,255,0.15); color: white;
            padding: 0.25rem 0.75rem; border-radius: 9999px;
            font-size: 0.75rem; font-weight: 600;
        }
        .tier-body { padding: 1.125rem; background: white; }

        /* ── TRAINING PHASES ── */
        .phases { display: flex; flex-direction: column; gap: 0; margin: 1rem 0; }
        .phase {
            display: flex; gap: 1rem; padding: 1rem 1.25rem;
            border: 1px solid var(--slate-200); border-radius: 0;
            background: white;
        }
        .phase:first-child { border-radius: 0.5rem 0.5rem 0 0; }
        .phase:last-child { border-radius: 0 0 0.5rem 0.5rem; }
        .phase + .phase { border-top: none; }
        .phase-num {
            width: 2rem; height: 2rem; border-radius: 50%;
            background: var(--navy); color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.875rem; font-weight: 700; flex-shrink: 0;
        }
        .phase-title { font-weight: 700; color: var(--navy); margin-bottom: 0.25rem; font-size: 0.9375rem; }
        .phase-desc { font-size: 0.875rem; color: #4b5563; }
        .phase-list { list-style: none; margin-top: 0.375rem; }
        .phase-list li { font-size: 0.875rem; color: #4b5563; padding: 0.125rem 0; display: flex; gap: 0.375rem; }
        .phase-list li::before { content: '—'; color: var(--slate-400); flex-shrink: 0; }

        /* ── WORKGROUP TABLE ── */
        .data-table {
            width: 100%; border-collapse: collapse; font-size: 0.875rem;
            border-radius: 0.5rem; overflow: hidden; border: 1px solid var(--slate-200);
            margin: 1rem 0;
        }
        .data-table thead th {
            background: var(--navy); color: white; padding: 0.75rem 1rem;
            text-align: left; font-size: 0.75rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.05em;
        }
        .data-table thead th.right { text-align: right; }
        .data-table tbody td {
            padding: 0.75rem 1rem; border-bottom: 1px solid var(--slate-100);
            color: #374151; vertical-align: middle;
        }
        .data-table tbody td.right { text-align: right; font-weight: 600; }
        .data-table tbody tr:hover { background: var(--slate-50); }
        .data-table tfoot td {
            padding: 0.75rem 1rem; background: var(--slate-100);
            font-weight: 700; color: var(--navy); border-top: 2px solid var(--slate-300);
        }
        .score-badge { display: inline-block; padding: 0.25rem 0.625rem; border-radius: 9999px; font-weight: 700; font-size: 0.875rem; min-width: 4rem; text-align: center; }
        .score-elite { background: #d1fae5; color: #065f46; }
        .score-high { background: var(--blue-light); color: #1e40af; }
        .score-mid { background: var(--amber-light); color: #92400e; }
        .score-low { background: var(--red-light); color: #991b1b; }
        .brand-pill { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; background: var(--slate-100); color: var(--navy-light); }

        /* ── SPEC TABLE ── */
        .spec-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; margin: 0.75rem 0; border: 1px solid var(--slate-200); border-radius: 0.375rem; overflow: hidden; }
        .spec-table tr:nth-child(even) { background: var(--slate-50); }
        .spec-table td { padding: 0.625rem 0.875rem; border-bottom: 1px solid var(--slate-100); color: #374151; }
        .spec-table td:first-child { font-weight: 600; color: var(--navy); width: 40%; }
        .spec-table tr:last-child td { border-bottom: none; }

        /* ── CALLOUTS ── */
        .callout { border-radius: 0.5rem; padding: 1.125rem 1.25rem; margin: 1.25rem 0; border-left: 4px solid; font-size: 0.9rem; }
        .callout.success { background: #f0fdf4; border-color: var(--emerald); color: #14532d; }
        .callout.warning { background: var(--amber-light); border-color: var(--amber); color: #713f12; }
        .callout.info { background: var(--blue-light); border-color: var(--blue); color: #1e3a5f; }
        .callout.note { background: var(--slate-50); border-color: var(--slate-400); color: var(--navy-light); }
        .callout-label { font-size: 0.6875rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.375rem; opacity: 0.7; }

        /* ── CHART ── */
        .chart-section { background: var(--slate-50); border: 1px solid var(--slate-200); border-radius: 0.625rem; padding: 1.5rem; margin: 1.25rem 0; }
        .chart-title { font-size: 0.8125rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--slate-500); margin-bottom: 1rem; }
        .chart-container { position: relative; height: 240px; }
        .chart-container.tall { height: 300px; }

        /* ── IMAGES ── */
        .report-image { width: 100%; border-radius: 0.5rem; border: 1px solid var(--slate-200); margin: 1.25rem 0; display: block; max-height: 320px; object-fit: contain; background: var(--slate-50); }
        .image-caption { font-size: 0.75rem; color: var(--slate-500); text-align: center; font-style: italic; margin-top: -0.75rem; margin-bottom: 1.25rem; }
        .image-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1.25rem 0; }
        .image-grid .report-image { margin: 0; }

        /* ── HR ── */
        hr.section-divider { border: none; border-top: 1px solid var(--slate-200); margin: 2rem 0; }

        /* ── FOOTER ── */
        .report-footer { background: var(--slate-50); border-top: 1px solid var(--slate-200); padding: 1.25rem 3.5rem; display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: var(--slate-500); }

        /* ── PRINT ── */
        @@media print {
            body { background: white; font-size: 13px; }
            .print-bar { display: none; }
            .report-wrapper { max-width: 100%; margin: 0; border-radius: 0; box-shadow: none; }
            .report-body { padding: 1.5rem 2rem; }
            .report-cover { padding: 2rem; }
            .section + .section { page-break-inside: avoid; }
            .selection-card { page-break-inside: avoid; }
            .data-table { page-break-inside: avoid; }
            h1.section-title, h2.subsection-title { page-break-after: avoid; }
            .report-image { max-height: 260px; }
            @@page { margin: 0.75in; size: letter; }
        }
        @@media (max-width: 640px) {
            .report-body { padding: 1.5rem; }
            .report-cover { padding: 2rem 1.5rem; }
            .cover-title { font-size: 1.75rem; }
            .toc-list { grid-template-columns: 1fr; }
            .image-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Print Bar -->
    <div class="print-bar">
        <span class="print-bar-title">MBFD Workgroup Final Recommendations — Q1 2026</span>
        <button class="print-btn" onclick="window.print()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
            Save as PDF / Print
        </button>
    </div>

    <div class="report-wrapper">

        <!-- Cover -->
        <div class="report-cover">
            <div class="final-stamp">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                Final Workgroup Determination — Q1 2026
            </div>
            <div class="cover-agency">
                <div class="cover-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" width="26" height="26"><path d="M11.47 3.841a.75.75 0 0 1 1.06 0l8.69 8.69a.75.75 0 1 0 1.06-1.061l-8.689-8.69a2.25 2.25 0 0 0-3.182 0l-8.69 8.69a.75.75 0 1 0 1.061 1.06l8.69-8.689Z"/><path d="m12 5.432 8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 0 1-.75-.75v-4.5a.75.75 0 0 0-.75-.75h-3a.75.75 0 0 0-.75.75V21a.75.75 0 0 1-.75.75H5.625a1.875 1.875 0 0 1-1.875-1.875v-6.198a2.29 2.29 0 0 0 .091-.086L12 5.432Z"/></svg>
                </div>
                <div>
                    <div class="cover-agency-text">Miami Beach Fire Department</div>
                    <div class="cover-agency-sub">MID-MOUNT LADDER WORKGROUP</div>
                </div>
            </div>
            <h1 class="cover-title">Final Equipment Selection<br>&amp; Implementation Report</h1>
            <p class="cover-subtitle">Workgroup Determinations — Hydraulic Rescue Tools, Saws, Stabilization &amp; Training Plan</p>
            <div class="cover-recipients">
                <div class="cover-recipients-label">Submitted To</div>
                <ul>
                    <li>Fire Chief Digna Abello</li>
                    <li>Health &amp; Safety Committee</li>
                </ul>
            </div>
            <div class="cover-meta">
                <div class="cover-meta-item"><span class="cover-meta-label">Report Date</span><span class="cover-meta-value">March 18, 2026</span></div>
                <div class="cover-meta-item"><span class="cover-meta-label">Products Selected</span><span class="cover-meta-value">9 Systems</span></div>
                <div class="cover-meta-item"><span class="cover-meta-label">Status</span><span class="cover-meta-value" style="color:#4ade80">Final Determination</span></div>
            </div>
        </div>

        <!-- Body -->
        <div class="report-body">

            <!-- Final Selections Summary -->
            <div class="selections-summary">
                <div class="selections-summary-title">Final Equipment Selections at a Glance</div>
                <table class="data-table" style="margin:0; border:none;">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Selected Equipment</th>
                            <th>Platform</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Cut-Off Saw</td><td><strong>DeWalt 12" Battery Cut-Off Saw</strong></td><td>Frontline</td><td><span class="selection-status status-selected">Selected</span></td></tr>
                        <tr><td>Chainsaw</td><td><strong>DeWalt 18" Chainsaw</strong> (bullet chain + depth markings)</td><td>Frontline</td><td><span class="selection-status status-selected">Selected</span></td></tr>
                        <tr><td>Stabilization</td><td><strong>4 × Holmatro V-Struts</strong></td><td>Frontline</td><td><span class="selection-status status-selected">Selected</span></td></tr>
                        <tr><td>Spreader</td><td><strong>Hurst SP 777 E3 Connect</strong></td><td>CAPTIUM Platform</td><td><span class="selection-status status-selected">Selected</span></td></tr>
                        <tr><td>Cutter</td><td><strong>Hurst S 789 E3 Connect</strong></td><td>CAPTIUM Platform</td><td><span class="selection-status status-selected">Selected</span></td></tr>
                        <tr><td>Ram</td><td><strong>Hurst CR 522 E3 Connect</strong></td><td>CAPTIUM Platform</td><td><span class="selection-status status-selected">Selected</span></td></tr>
                        <tr><td>Specialty Tool</td><td><strong>Holmatro T1</strong></td><td>Rabbit Tool Trial</td><td><span class="selection-status status-trial">Trial</span></td></tr>
                        <tr><td>Heavy Extrication</td><td><strong>Hurst M40 (40")</strong> + 2 batteries + charging station</td><td>300 (Bat. Chief)</td><td><span class="selection-status status-selected">Selected</span></td></tr>
                        <tr><td>Future Addition</td><td><strong>Lifting Struts</strong> (pending evaluation)</td><td>300 / Captain 5</td><td><span class="selection-status status-pending">Pending</span></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- TOC -->
            <div class="toc">
                <div class="toc-title">Table of Contents</div>
                <ul class="toc-list">
                    <li><a href="#sec1"><span class="toc-num">1.</span> Executive Summary</a></li>
                    <li><a href="#sec-selections"><span class="toc-num">2.</span> Final Equipment Selections</a></li>
                    <li><a href="#sec-deployment"><span class="toc-num">3.</span> Apparatus Deployment Strategy</a></li>
                    <li><a href="#sec-training"><span class="toc-num">4.</span> Training &amp; Implementation Plan</a></li>
                    <li><a href="#sec3"><span class="toc-num">5.</span> Justification for Selection</a></li>
                    <li><a href="#sec4"><span class="toc-num">6.</span> Workgroup Determinations</a></li>
                    <li><a href="#sec5"><span class="toc-num">7.</span> Implementation Notes</a></li>
                    <li><a href="#appendix"><span class="toc-num">A.</span> Data Source Verification</a></li>
                </ul>
            </div>

            <!-- ══ SEC 1: Executive Summary ══ -->
            <section class="section" id="sec1">
                <h1 class="section-title"><span class="section-num">1</span> Executive Summary</h1>
                <p>This report documents the <strong>final outcome</strong> of the Miami Beach Fire Department Mid-Mount Ladder Workgroup evaluation process (Q1 2026). Following structured multi-day evaluations of hydraulic rescue tools, cut-off saws, vehicle stabilization systems, and specialty tools, the workgroup has reached consensus on final equipment selections.</p>

                <div class="callout success">
                    <div class="callout-label">Workgroup Consensus Reached</div>
                    The workgroup has reached full consensus on equipment selections for the Mid-Mount Ladder apparatus. A follow-on session is scheduled to evaluate <strong>lifting struts</strong> for assignment to the 300 / Captain 5 vehicle.
                </div>

                <p>This report documents <strong>9 equipment systems</strong> selected for MBFD apparatus integration, encompassing frontline extrication tools, battery-powered saws, vehicle stabilization, specialty forcible entry tools, and command-level heavy extrication capability.</p>
            </section>

            <!-- ══ SEC 2: Final Selections ══ -->
            <section class="section" id="sec-selections">
                <h1 class="section-title"><span class="section-num">2</span> Final Equipment Selections</h1>

                <!-- Cut-Off Saw -->
                <div class="selection-card">
                    <div class="selection-card-header">
                        <div>
                            <div class="selection-card-label">Cut-Off Saw — Frontline</div>
                            <div class="selection-card-name">DeWalt 12" Battery Cut-Off Saw (DCPS612AG2)</div>
                        </div>
                        <span class="selection-status status-selected">✓ Selected</span>
                    </div>
                    <div class="selection-card-body">
                        <img class="selection-card-image" src="/workgroup-report/images/DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine-1_p0_i0.png"
                             alt="DeWalt 12 inch Battery Cut-Off Saw"
                             onerror="this.style.display='none'">
                        <p>Battery operation eliminates fuel management requirements and supports rapid deployment from compartment. Gear-driven power delivery and 3-second electric brake provide superior tactical performance. Composite evaluation score: <strong>91.25</strong> — 26+ points ahead of 14-inch competitors.</p>
                    </div>
                </div>

                <!-- Chainsaw -->
                <div class="selection-card">
                    <div class="selection-card-header">
                        <div>
                            <div class="selection-card-label">Chainsaw — Frontline</div>
                            <div class="selection-card-name">DeWalt 18" Chainsaw</div>
                        </div>
                        <span class="selection-status status-selected">✓ Selected</span>
                    </div>
                    <div class="selection-card-body">
                        <ul style="list-style:none; margin-bottom:0.75rem;">
                            <li style="padding:0.25rem 0; display:flex; gap:0.5rem; color:#374151;"><span style="color:var(--emerald); font-weight:700;">→</span> Bullet chain configuration</li>
                            <li style="padding:0.25rem 0; display:flex; gap:0.5rem; color:#374151;"><span style="color:var(--emerald); font-weight:700;">→</span> Depth markings for ventilation operations</li>
                            <li style="padding:0.25rem 0; display:flex; gap:0.5rem; color:#374151;"><span style="color:var(--emerald); font-weight:700;">→</span> Battery-powered — compatible with DeWalt CAPTIUM platform</li>
                        </ul>
                    </div>
                </div>

                <!-- V-Struts -->
                <div class="selection-card">
                    <div class="selection-card-header">
                        <div>
                            <div class="selection-card-label">Vehicle Stabilization — Frontline</div>
                            <div class="selection-card-name">4 × Holmatro V-Struts (Auto-locking)</div>
                        </div>
                        <span class="selection-status status-selected">✓ Selected</span>
                    </div>
                    <div class="selection-card-body">
                        <div class="image-grid">
                            <img class="report-image" src="/workgroup-report/images/Holmatro omnishore-1_p1_i55.png"
                                 alt="Holmatro Stabilization System" onerror="this.style.display='none'">
                            <img class="report-image" src="/workgroup-report/images/Holmatro omnishore-1_p4_i82.png"
                                 alt="Holmatro V-Strut Detail" onerror="this.style.display='none'">
                        </div>
                        <p>Four V-Struts selected for vehicle stabilization. Auto-lock mechanical system deploys in 15 seconds. At 15.87 lbs each, the V-Strut is the lightest stabilization option evaluated. Composite score: <strong>87.28</strong> — category leader.</p>
                    </div>
                </div>

                <!-- Hurst E3 CAPTIUM Platform -->
                <div class="selection-card">
                    <div class="selection-card-header">
                        <div>
                            <div class="selection-card-label">Extrication Platform — CAPTIUM Battery System</div>
                            <div class="selection-card-name">Hurst E3 CAPTIUM — SP 777 / S 789 / CR 522</div>
                        </div>
                        <span class="selection-status status-selected">✓ Selected</span>
                    </div>
                    <div class="selection-card-body">
                        <img class="report-image" src="/workgroup-report/images/Holmatro Pentheon Series USA-1_p10_i1.png"
                             alt="Extrication Tool System" onerror="this.style.display='none'">
                        <table class="data-table" style="margin-top:0.75rem;">
                            <thead><tr><th>Tool</th><th>Model</th><th>Role</th><th class="right">Score</th></tr></thead>
                            <tbody>
                                <tr><td>Spreader</td><td><strong>SP 777 E3 Connect</strong></td><td>Primary spreading</td><td class="right"><span class="score-badge score-elite">90.99</span></td></tr>
                                <tr><td>Cutter</td><td><strong>S 789 E3 Connect</strong></td><td>Primary cutting</td><td class="right"><span class="score-badge score-high">82.57</span></td></tr>
                                <tr><td>Ram</td><td><strong>CR 522 E3 Connect</strong></td><td>Extension / pushing</td><td class="right"><span class="score-badge score-elite">86.02</span></td></tr>
                                <tr><td>Platform</td><td><strong>CAPTIUM</strong></td><td>Shared battery system</td><td class="right">—</td></tr>
                            </tbody>
                        </table>
                        <p style="margin-top:0.75rem;">IP58 watertight design rated for saltwater operations — critical for MBFD's coastal deployment environment. E3 Connect Wi-Fi integration with Captium cloud provides fleet management capabilities.</p>
                    </div>
                </div>

                <!-- T1 Trial -->
                <div class="selection-card">
                    <div class="selection-card-header">
                        <div>
                            <div class="selection-card-label">Specialty Tool — Trial Authorization</div>
                            <div class="selection-card-name">Holmatro T1 Forcible Entry Tool</div>
                        </div>
                        <span class="selection-status status-trial">⚠ Trial</span>
                    </div>
                    <div class="selection-card-body">
                        <div class="callout warning" style="margin-top:0;">
                            <div class="callout-label">Trial Status</div>
                            Authorized for trial deployment as a <strong>potential replacement candidate for the Rabbit tool</strong>. Final determination pending operational trial results.
                        </div>
                        <table class="spec-table" style="margin-top:0.75rem;">
                            <tbody>
                                <tr><td>Composite Score</td><td>82.23</td></tr>
                                <tr><td>Weight</td><td>17.0 lbs (7.7 kg)</td></tr>
                                <tr><td>Functions</td><td>Cut, Wedge, Ram, Spread, Hammer, Lift (6-in-1)</td></tr>
                                <tr><td>Power Source</td><td>Manual hydraulic — no batteries, no external pump</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- M40 -->
                <div class="selection-card">
                    <div class="selection-card-header">
                        <div>
                            <div class="selection-card-label">Heavy Extrication — 300 (Battalion Chief)</div>
                            <div class="selection-card-name">Hurst M40 (40" Spreader) + Package</div>
                        </div>
                        <span class="selection-status status-selected">✓ Selected</span>
                    </div>
                    <div class="selection-card-body">
                        <ul style="list-style:none; margin-bottom:0.75rem;">
                            <li style="padding:0.25rem 0; display:flex; gap:0.5rem; color:#374151;"><span style="color:var(--blue); font-weight:700;">→</span> Hurst M40 40" spreader</li>
                            <li style="padding:0.25rem 0; display:flex; gap:0.5rem; color:#374151;"><span style="color:var(--blue); font-weight:700;">→</span> 2 × CAPTIUM batteries</li>
                            <li style="padding:0.25rem 0; display:flex; gap:0.5rem; color:#374151;"><span style="color:var(--blue); font-weight:700;">→</span> Charging station</li>
                            <li style="padding:0.25rem 0; display:flex; gap:0.5rem; color:#374151;"><span style="color:var(--blue); font-weight:700;">→</span> Assigned apparatus: <strong>300 (Battalion Chief)</strong></li>
                        </ul>
                        <p>Heavy rescue capability at command level. Composite score: <strong>78.80</strong> (Capability: 83.39). Not suitable for standard frontline apparatus due to physical footprint — dedicated command-level deployment only.</p>
                    </div>
                </div>

                <!-- Lifting Struts -->
                <div class="selection-card">
                    <div class="selection-card-header">
                        <div>
                            <div class="selection-card-label">Future Addition — Pending Evaluation</div>
                            <div class="selection-card-name">Lifting Struts (300 / Captain 5)</div>
                        </div>
                        <span class="selection-status status-pending">⏳ Pending</span>
                    </div>
                    <div class="selection-card-body">
                        <p>An additional workgroup session has been scheduled to evaluate lifting struts for potential assignment to the 300 / Captain 5 vehicle. This evaluation will extend the department's heavy rescue capability at the command level.</p>
                    </div>
                </div>
            </section>

            <!-- ══ SEC 3: Deployment Strategy ══ -->
            <section class="section" id="sec-deployment">
                <h1 class="section-title"><span class="section-num">3</span> Apparatus Deployment Strategy</h1>

                <p>The workgroup has established a tiered deployment model to ensure full operational independence across all rescue scenarios.</p>

                <div class="callout info">
                    <div class="callout-label">Strategic Goal</div>
                    Full operational independence across all rescue levels — ensuring MBFD units can handle any vehicle extrication scenario without dependency on mutual aid.
                </div>

                <!-- Frontline -->
                <div class="deployment-tier">
                    <div class="tier-header frontline">
                        <div>
                            <div class="tier-label">Tier 1</div>
                            <div class="tier-title">Frontline Apparatus — L1 / L3</div>
                        </div>
                        <div class="tier-badge">Primary Response</div>
                    </div>
                    <div class="tier-body">
                        <table class="data-table" style="margin:0;">
                            <thead><tr><th>Equipment</th><th>Deployment Role</th></tr></thead>
                            <tbody>
                                <tr><td><strong>Hurst SP 777 E3 Connect Spreader</strong></td><td>Primary extrication spreading</td></tr>
                                <tr><td><strong>Hurst S 789 E3 Connect Cutter</strong></td><td>Primary extrication cutting</td></tr>
                                <tr><td><strong>Hurst CR 522 E3 Connect Ram</strong></td><td>Extension / displacement</td></tr>
                                <tr><td><strong>4 × Holmatro V-Struts</strong></td><td>Rapid vehicle stabilization</td></tr>
                                <tr><td><strong>DeWalt 12" Battery Cut-Off Saw</strong></td><td>Rapid entry / cutting</td></tr>
                                <tr><td><strong>DeWalt 18" Chainsaw</strong></td><td>Ventilation operations</td></tr>
                                <tr><td><strong>Holmatro T1 (trial)</strong></td><td>Multi-function access tool</td></tr>
                            </tbody>
                        </table>
                        <p style="margin-top:0.75rem; font-size:0.875rem; color:var(--slate-500);"><strong>Operational Priority:</strong> Rapid deployment capability, compartment-optimized, battery-independent operation.</p>
                    </div>
                </div>

                <!-- Command -->
                <div class="deployment-tier">
                    <div class="tier-header command">
                        <div>
                            <div class="tier-label">Tier 2</div>
                            <div class="tier-title">Command Level — 300 / Captain 5</div>
                        </div>
                        <div class="tier-badge">Command Augmentation</div>
                    </div>
                    <div class="tier-body">
                        <table class="data-table" style="margin:0;">
                            <thead><tr><th>Equipment</th><th>Deployment Role</th></tr></thead>
                            <tbody>
                                <tr><td><strong>Hurst M40 40" Spreader</strong></td><td>Heavy extrication capability</td></tr>
                                <tr><td><strong>2 × CAPTIUM Batteries</strong></td><td>Extended power supply</td></tr>
                                <tr><td><strong>Charging Station</strong></td><td>Field recharging</td></tr>
                                <tr><td><strong>Lifting Struts (future)</strong></td><td>Vehicle lifting operations</td></tr>
                            </tbody>
                        </table>
                        <p style="margin-top:0.75rem; font-size:0.875rem; color:var(--slate-500);"><strong>Operational Priority:</strong> Heavy rescue augmentation, command-level specialty capability.</p>
                    </div>
                </div>

                <!-- Heavy Rescue -->
                <div class="deployment-tier">
                    <div class="tier-header heavy">
                        <div>
                            <div class="tier-label">Tier 3</div>
                            <div class="tier-title">Heavy Rescue — E2 / Box Truck</div>
                        </div>
                        <div class="tier-badge">Specialist Operations</div>
                    </div>
                    <div class="tier-body">
                        <p style="color:#374151;">High-capacity rescue systems for extreme lifting and technical rescue scenarios.</p>
                        <p style="margin-top:0.375rem; font-size:0.875rem; color:var(--slate-500);"><strong>Operational Priority:</strong> Maximum force, specialist operations, structural rescue scenarios.</p>
                    </div>
                </div>
            </section>

            <!-- ══ SEC 4: Training & Implementation ══ -->
            <section class="section" id="sec-training">
                <h1 class="section-title"><span class="section-num">4</span> Training &amp; In-Service Implementation Plan</h1>

                <h2 class="subsection-title">Train-the-Trainer Model</h2>
                <p>Workgroup members who participated in the evaluation process will serve as <strong>subject matter experts (SMEs)</strong> and lead departmental training.</p>

                <div class="phases">
                    <div class="phase">
                        <div class="phase-num">1</div>
                        <div>
                            <div class="phase-title">Phase 1 — Vendor Training</div>
                            <ul class="phase-list">
                                <li>Workgroup members receive comprehensive vendor-led training on all selected equipment</li>
                                <li>Members achieve proficiency qualification on each system</li>
                            </ul>
                        </div>
                    </div>
                    <div class="phase">
                        <div class="phase-num">2</div>
                        <div>
                            <div class="phase-title">Phase 2 — Department-Wide Rollout</div>
                            <ul class="phase-list">
                                <li>Department personnel divided across shift groups</li>
                                <li>Each workgroup SME trains their assigned shift</li>
                                <li><strong>No overtime required</strong> — training integrated into existing shift schedules</li>
                            </ul>
                        </div>
                    </div>
                    <div class="phase">
                        <div class="phase-num">3</div>
                        <div>
                            <div class="phase-title">Phase 3 — Deep Familiarization</div>
                            <ul class="phase-list">
                                <li>Tool operation and safety protocols</li>
                                <li>Tactical application in vehicle extrication scenarios</li>
                                <li>Battery management and field charging procedures</li>
                                <li>Stabilization deployment procedures (V-Strut 15-second auto-lock)</li>
                                <li>Integration of T1 trial tool into existing protocols</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <h2 class="subsection-title">Ladder Truck In-Service Training Requirements</h2>

                <div class="callout warning">
                    <div class="callout-label">Mandatory Requirement</div>
                    All training components must be completed <strong>before</strong> the apparatus is placed into service.
                </div>

                <table class="data-table">
                    <thead><tr><th>Training Component</th><th>Requirement</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Driving Operations</strong></td><td>Full certification on mid-mount ladder configuration</td></tr>
                        <tr><td><strong>Pumping Operations</strong></td><td>Operational proficiency on all pump functions</td></tr>
                        <tr><td><strong>Tactical Deployment</strong></td><td>Extrication tool deployment from mid-mount compartments</td></tr>
                        <tr><td><strong>Equipment Integration</strong></td><td>Familiarization with all selected equipment as installed</td></tr>
                    </tbody>
                </table>

                <h2 class="subsection-title">Ongoing Workgroup Involvement</h2>

                <p>The Mid-Mount Ladder Workgroup remains <strong>active through full implementation</strong> of the selected equipment.</p>

                <table class="data-table">
                    <thead><tr><th>Function</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Procurement Tracking</strong></td><td>Monitor status of all equipment orders</td></tr>
                        <tr><td><strong>Delivery Coordination</strong></td><td>Coordinate receipt and initial inspection</td></tr>
                        <tr><td><strong>Installation Oversight</strong></td><td>Ensure proper apparatus integration</td></tr>
                        <tr><td><strong>Dashboard Monitoring</strong></td><td>Track all status updates via Workgroup Dashboard</td></tr>
                        <tr><td><strong>Future Evaluations</strong></td><td>Lead additional evaluation sessions (lifting struts)</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- ══ SEC 5: Justification ══ -->
            <section class="section" id="sec3">
                <h1 class="section-title"><span class="section-num">5</span> Justification for Selection</h1>

                <h2 class="subsection-title">Evaluation Framework</h2>
                <p>The workgroup evaluation employed a standardized scoring model across all 14 products. Four scoring dimensions were applied consistently:</p>

                <table class="data-table">
                    <thead><tr><th>Dimension</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Capability</strong></td><td>Raw performance metrics — force output, cutting capacity, spreading distance</td></tr>
                        <tr><td><strong>Usability</strong></td><td>Ergonomic design, control interface, operator comfort under sustained use</td></tr>
                        <tr><td><strong>Maintainability</strong></td><td>Service requirements, durability, component accessibility</td></tr>
                        <tr><td><strong>Deployability</strong></td><td>Storage footprint, activation speed, weight, cordless readiness</td></tr>
                    </tbody>
                </table>

                <h2 class="subsection-title">Brand Performance Context</h2>

                <div class="chart-section">
                    <div class="chart-title">Brand Average Composite Scores — Final Selection Context</div>
                    <div class="chart-container">
                        <canvas id="brandChart"></canvas>
                    </div>
                </div>

                <h2 class="subsection-title">Frontline Tool Dimension Comparison</h2>

                <div class="chart-section">
                    <div class="chart-title">Four-Dimension Breakdown — Holmatro Pentheon vs. Hurst E3</div>
                    <div class="chart-container">
                        <canvas id="dimensionChart"></canvas>
                    </div>
                </div>

                <table class="data-table">
                    <thead><tr><th>Dimension</th><th class="right">Holmatro</th><th class="right">Hurst</th><th class="right">Δ Delta</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Capability</strong></td><td class="right">89.41</td><td class="right">87.56</td><td class="right" style="color:var(--emerald)">+1.85</td></tr>
                        <tr><td><strong>Usability</strong></td><td class="right">93.99</td><td class="right">85.76</td><td class="right" style="color:var(--emerald)">+8.23</td></tr>
                        <tr><td><strong>Maintainability</strong></td><td class="right">89.82</td><td class="right">82.58</td><td class="right" style="color:var(--emerald)">+7.24</td></tr>
                        <tr><td><strong>Deployability</strong></td><td class="right">89.69</td><td class="right">82.74</td><td class="right" style="color:var(--emerald)">+6.95</td></tr>
                    </tbody>
                </table>

                <h2 class="subsection-title">Frontline Tool Scores — Final Selection Context</h2>

                <div class="chart-section">
                    <div class="chart-title">Composite Scores — All Frontline Tools</div>
                    <div class="chart-container tall">
                        <canvas id="frontlineChart"></canvas>
                    </div>
                </div>

                <div class="image-grid">
                    <div>
                        <img class="report-image" src="/workgroup-report/images/Holmatro Pentheon Series USA-1_p11_i2.png"
                             alt="Holmatro Pentheon Spreader" onerror="this.style.display='none'">
                        <div class="image-caption">Holmatro Pentheon PSP40 Spreader</div>
                    </div>
                    <div>
                        <img class="report-image" src="/workgroup-report/images/Holmatro Pentheon Series USA-1_p13_i14.png"
                             alt="Holmatro Pentheon Cutter" onerror="this.style.display='none'">
                        <div class="image-caption">Holmatro Pentheon PCU30CL Cutter</div>
                    </div>
                </div>
            </section>

            <!-- ══ SEC 6: Workgroup Determinations ══ -->
            <section class="section" id="sec4">
                <h1 class="section-title"><span class="section-num">6</span> Workgroup Determinations</h1>

                <h2 class="subsection-title">Final Selected Equipment — Per Category</h2>

                <table class="data-table">
                    <thead>
                        <tr><th>Category</th><th>Selected Tool</th><th>Manufacturer</th><th class="right">Score</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Spreaders</td><td><strong>Holmatro PSP40</strong></td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-elite">92.02</span></td></tr>
                        <tr><td>Cutters</td><td><strong>Holmatro PCU30CL</strong></td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-elite">88.86</span></td></tr>
                        <tr><td>Rams</td><td><strong>Holmatro PRA40</strong></td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-elite">91.29</span></td></tr>
                        <tr><td>Saws</td><td><strong>DeWalt DCPS612AG2</strong></td><td><span class="brand-pill">DeWalt</span></td><td class="right"><span class="score-badge score-elite">91.25</span></td></tr>
                        <tr><td>Stabilization</td><td><strong>Holmatro V-Strut</strong></td><td><span class="brand-pill">Holmatro</span></td><td class="right"><span class="score-badge score-elite">87.28</span></td></tr>
                    </tbody>
                </table>

                <img class="report-image" src="/workgroup-report/images/final_workgroup_results_p1_i6.png"
                     alt="Workgroup Final Selection Performance Data"
                     onerror="this.style.display='none'">

                <h2 class="subsection-title">Cross-Category Insights</h2>

                <table class="data-table">
                    <thead><tr><th>Category</th><th>Key Differentiator</th><th class="right">Advantage</th></tr></thead>
                    <tbody>
                        <tr><td>Saws</td><td>Gear-driven instant-start + 3-second electric brake (DeWalt)</td><td class="right" style="color:var(--emerald)">+26.42 to +29.47 pts</td></tr>
                        <tr><td>Frontline Tools</td><td>On-Tool Charging + cordless auto start/stop (Holmatro)</td><td class="right" style="color:var(--emerald)">+6.95 pts Deployability</td></tr>
                        <tr><td>Stabilization</td><td>15-second auto-lock (V-Strut vs. competitors)</td><td class="right" style="color:var(--emerald)">+1.41 to +11.15 pts</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- ══ SEC 7: Implementation Notes ══ -->
            <section class="section" id="sec5">
                <h1 class="section-title"><span class="section-num">7</span> Implementation Notes — Specialty Tools</h1>

                <div class="callout note">
                    <div class="callout-label">Scope Note</div>
                    This section contains <strong>descriptive justification only</strong> — no speculation, no procurement language beyond approved selections.
                </div>

                <h2 class="subsection-title">T1 Tool — Rabbit Tool Trial</h2>
                <p>The Holmatro T1 (score: <strong>82.23</strong>) is authorized on trial as a potential replacement for the traditional rabbit tool. Key comparisons:</p>

                <table class="spec-table">
                    <tbody>
                        <tr><td>Door Forcing</td><td>T1 hydraulic wedging (3.4 ton) vs. manual prying — single operator vs. 2-person team</td></tr>
                        <tr><td>Cutting Added</td><td>T1 adds hydraulic cutting (14.2 tons) — rabbit tool provides none</td></tr>
                        <tr><td>Tool Consolidation</td><td>Replaces halligan bar + flathead axe — 6 functions in 17 lbs</td></tr>
                        <tr><td>Limitations</td><td>Manual hydraulic; cutting opening limited to 1.1 inches; documented deal-breaker concerns on file</td></tr>
                    </tbody>
                </table>

                <h2 class="subsection-title">M40 — 300's Truck Assignment</h2>
                <p>The Hurst M40 (score: <strong>78.80</strong>) is assigned to <strong>300's new truck</strong> (currently at Fleet for emergency lighting installation). The M40's 40-inch spread exceeds standard 32-inch parameters for heavy rescue scenarios.</p>

                <div class="callout warning">
                    <div class="callout-label">Deployment Restriction</div>
                    The M40 is <strong>not compatible with standard frontline apparatus compartments</strong>. Its Usability score of 79.29 — the lowest in the dataset — reflects immense physical footprint. Dedicated to 300's truck only.
                </div>
            </section>

            <!-- ══ APPENDIX ══ -->
            <section class="section" id="appendix">
                <h1 class="section-title"><span class="section-num">A</span> Appendix: Data Source Verification</h1>

                <div class="callout note">
                    <div class="callout-label">Data Integrity</div>
                    All numerical values sourced directly from <strong>Product_Data_Master.csv</strong> (14 rows, 31 columns). No values interpolated, estimated, or sourced from external references.
                </div>

                <table class="data-table">
                    <thead><tr><th>Row</th><th>Product</th><th>Category</th><th>Manufacturer</th><th class="right">Score</th></tr></thead>
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
            <span>Miami Beach Fire Department · Mid-Mount Ladder Workgroup</span>
            <span>Final Determination: March 18, 2026 · Q1 2026 Evaluation Cycle</span>
        </div>
    </div>

    <script>
        Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.95)';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 6;

        const NAVY = '#0f172a', RED = '#dc2626', BLUE = '#2563eb';
        const GREEN = '#059669', AMBER = '#d97706';

        new Chart(document.getElementById('brandChart'), {
            type: 'bar',
            data: {
                labels: ['DeWalt', 'Holmatro', 'Hurst', 'Paratech', 'Makita', 'Husqvarna'],
                datasets: [{ label: 'Avg Composite Score', data: [91.25, 87.93, 84.60, 76.13, 64.83, 61.78], backgroundColor: [GREEN, BLUE, '#0ea5e9', AMBER, '#f87171', '#fca5a5'], borderRadius: 6, borderSkipped: false }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: false, min: 50, max: 100, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 12 } } }, x: { grid: { display: false }, ticks: { font: { size: 12, weight: '600' } } } } }
        });

        new Chart(document.getElementById('dimensionChart'), {
            type: 'bar',
            data: {
                labels: ['Capability', 'Usability', 'Maintainability', 'Deployability'],
                datasets: [
                    { label: 'Holmatro Pentheon', data: [89.41, 93.99, 89.82, 89.69], backgroundColor: BLUE, borderRadius: 4 },
                    { label: 'Hurst E3', data: [87.56, 85.76, 82.58, 82.74], backgroundColor: '#60a5fa', borderRadius: 4 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { font: { size: 12 }, color: NAVY } } }, scales: { y: { beginAtZero: false, min: 75, max: 100, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 12 } } }, x: { grid: { display: false }, ticks: { font: { size: 12 } } } } }
        });

        new Chart(document.getElementById('frontlineChart'), {
            type: 'bar',
            data: {
                labels: ['Spreader\n(Holmatro)', 'Spreader\n(Hurst)', 'Cutter\n(Holmatro)', 'Cutter\n(Hurst)', 'Ram\n(Holmatro)', 'Ram\n(Hurst)'],
                datasets: [{ label: 'Composite Score', data: [92.02, 90.99, 88.86, 82.57, 91.29, 86.02], backgroundColor: [BLUE, '#93c5fd', BLUE, '#93c5fd', BLUE, '#93c5fd'], borderRadius: 6, borderSkipped: false }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: false, min: 78, max: 95, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 12 } } }, x: { grid: { display: false }, ticks: { font: { size: 11 }, maxRotation: 0, minRotation: 0 } } } }
        });
    </script>
</body>
</html>