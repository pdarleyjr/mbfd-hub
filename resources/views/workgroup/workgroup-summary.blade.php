<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MBFD Workgroup Summary — Mid-Mount Ladder Equipment Evaluation Report</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,300;8..60,400;8..60,500;8..60,600;8..60,700&display=swap" rel="stylesheet">
    <style>
        /* ── CSS Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --color-primary: #1e293b;
            --color-accent: #dc2626;
            --color-accent-light: #fef2f2;
            --color-blue: #2563eb;
            --color-blue-light: #eff6ff;
            --color-green: #059669;
            --color-green-light: #ecfdf5;
            --color-amber: #b45309;
            --color-amber-light: #fffbeb;
            --color-purple: #7c3aed;
            --color-purple-light: #f5f3ff;
            --color-text: #1e293b;
            --color-text-secondary: #475569;
            --color-text-muted: #94a3b8;
            --color-border: #e2e8f0;
            --color-bg: #ffffff;
            --color-bg-alt: #f8fafc;
            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-serif: 'Source Serif 4', Georgia, 'Times New Roman', serif;
        }

        html { font-size: 16px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        body {
            font-family: var(--font-serif);
            color: var(--color-text);
            background: var(--color-bg-alt);
            line-height: 1.75;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Print / PDF Styles ── */
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .report-container { box-shadow: none; max-width: 100%; margin: 0; }
            .cover-page { page-break-after: always; }
            .toc-section { page-break-after: always; }
            .report-section { page-break-before: always; }
            .page-break { page-break-before: always; }
            table { page-break-inside: avoid; }
            .equipment-card { page-break-inside: avoid; }
            img { max-width: 100% !important; }
            @page {
                size: letter;
                margin: 0.75in 0.85in;
            }
        }

        /* ── Report Container ── */
        .report-container {
            max-width: 52rem;
            margin: 0 auto;
            background: var(--color-bg);
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 8px 32px rgba(0,0,0,0.06);
        }

        /* ── Print FAB ── */
        .print-fab {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1000;
            display: flex;
            gap: 0.5rem;
        }
        .print-fab button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: 0.5rem;
            font-family: var(--font-sans);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 150ms ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .print-fab .btn-pdf {
            background: var(--color-accent);
            color: white;
        }
        .print-fab .btn-pdf:hover { background: #b91c1c; transform: translateY(-1px); }
        .print-fab .btn-pdf svg { width: 1.125rem; height: 1.125rem; }

        /* ══════════════════════════════════════════
           COVER PAGE
           ══════════════════════════════════════════ */
        .cover-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 3rem 2.5rem;
            background: linear-gradient(160deg, #0f172a 0%, #1e293b 40%, #334155 100%);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .cover-page::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 80%;
            height: 200%;
            background: radial-gradient(ellipse, rgba(220,38,38,0.08) 0%, transparent 70%);
            pointer-events: none;
        }
        .cover-page::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--color-accent);
        }
        .cover-logo {
            width: 120px;
            height: auto;
            margin-bottom: 1.5rem;
            border-radius: 0.5rem;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.3));
        }
        .cover-hero-img {
            width: 100%;
            max-width: 36rem;
            height: auto;
            border-radius: 0.75rem;
            margin-bottom: 2.5rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            object-fit: cover;
            max-height: 22rem;
        }
        .cover-title {
            font-family: var(--font-sans);
            font-size: 2.25rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
            margin-bottom: 0.5rem;
        }
        .cover-subtitle {
            font-family: var(--font-serif);
            font-size: 1.125rem;
            font-weight: 400;
            color: #cbd5e1;
            margin-bottom: 2rem;
            max-width: 32rem;
            line-height: 1.6;
        }
        .cover-divider {
            width: 4rem;
            height: 3px;
            background: var(--color-accent);
            border-radius: 2px;
            margin: 0 auto 2rem;
        }
        .cover-meta {
            font-family: var(--font-sans);
            font-size: 0.8125rem;
            color: #94a3b8;
            line-height: 1.8;
        }
        .cover-meta strong { color: #e2e8f0; font-weight: 600; }
        .cover-badge {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 0.375rem 1rem;
            background: rgba(220,38,38,0.15);
            border: 1px solid rgba(220,38,38,0.3);
            border-radius: 0.25rem;
            font-family: var(--font-sans);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #fca5a5;
        }

        /* ══════════════════════════════════════════
           TABLE OF CONTENTS
           ══════════════════════════════════════════ */
        .toc-section {
            padding: 3rem 3.5rem;
        }
        .toc-title {
            font-family: var(--font-sans);
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-primary);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--color-accent);
            display: inline-block;
        }
        .toc-list { list-style: none; }
        .toc-list li {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            padding: 0.5rem 0;
            border-bottom: 1px dotted var(--color-border);
        }
        .toc-list .toc-num {
            font-family: var(--font-sans);
            font-weight: 700;
            color: var(--color-accent);
            min-width: 1.75rem;
        }
        .toc-list a {
            color: var(--color-text);
            text-decoration: none;
            font-size: 1rem;
        }
        .toc-list a:hover { color: var(--color-accent); }

        /* ══════════════════════════════════════════
           REPORT CONTENT
           ══════════════════════════════════════════ */
        .report-body { padding: 0 3.5rem 3rem; }

        .report-section { padding-top: 2.5rem; }

        /* Section headings */
        h2.section-heading {
            font-family: var(--font-sans);
            font-size: 1.625rem;
            font-weight: 700;
            color: var(--color-primary);
            margin-bottom: 1.25rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--color-accent);
            line-height: 1.3;
        }
        h2.section-heading .section-num {
            color: var(--color-accent);
            margin-right: 0.25rem;
        }

        h3.subsection-heading {
            font-family: var(--font-sans);
            font-size: 1.1875rem;
            font-weight: 600;
            color: var(--color-text);
            margin: 2rem 0 0.75rem;
            line-height: 1.4;
        }

        h4.sub-subsection-heading {
            font-family: var(--font-sans);
            font-size: 1.0625rem;
            font-weight: 600;
            color: var(--color-text-secondary);
            margin: 1.5rem 0 0.5rem;
        }

        p { margin-bottom: 1rem; }

        strong { font-weight: 600; }

        /* ── Callout / Info Box ── */
        .callout-box {
            background: var(--color-bg-alt);
            border-left: 4px solid var(--color-accent);
            border-radius: 0 0.5rem 0.5rem 0;
            padding: 1.25rem 1.5rem;
            margin: 1.5rem 0;
            font-size: 0.9375rem;
        }
        .callout-box p { margin-bottom: 0.25rem; }

        /* ── Key Findings List ── */
        .key-findings ul {
            list-style: none;
            padding: 0;
            margin: 1rem 0;
        }
        .key-findings li {
            position: relative;
            padding: 0.75rem 0 0.75rem 1.75rem;
            border-bottom: 1px solid var(--color-border);
        }
        .key-findings li::before {
            content: '';
            position: absolute;
            left: 0;
            top: 1.1rem;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--color-accent);
        }
        .key-findings li:last-child { border-bottom: none; }

        /* ── Tables ── */
        .report-table-wrap { overflow-x: auto; margin: 1.5rem 0; }
        table.report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            font-family: var(--font-sans);
        }
        table.report-table thead th {
            background: var(--color-primary);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1rem;
            text-align: left;
            white-space: nowrap;
            font-size: 0.8125rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        table.report-table thead th:first-child { border-radius: 0.375rem 0 0 0; }
        table.report-table thead th:last-child { border-radius: 0 0.375rem 0 0; }
        table.report-table tbody td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--color-border);
            vertical-align: top;
        }
        table.report-table tbody tr:nth-child(even) { background: var(--color-bg-alt); }
        table.report-table tbody tr:hover { background: #f1f5f9; }
        .score-elite { color: #059669; font-weight: 700; }
        .score-capable { color: #2563eb; font-weight: 700; }
        .score-acceptable { color: #b45309; font-weight: 600; }
        .score-deficient { color: #dc2626; font-weight: 600; }
        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 50%;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .rank-1 { background: #fef3c7; color: #92400e; }
        .rank-2 { background: #e2e8f0; color: #334155; }
        .rank-3 { background: #fed7aa; color: #9a3412; }
        .rank-4 { background: #f1f5f9; color: #64748b; }

        /* ── Equipment Cards ── */
        .equipment-card {
            display: flex;
            gap: 1.5rem;
            background: var(--color-bg-alt);
            border: 1px solid var(--color-border);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin: 1.5rem 0;
            align-items: center;
        }
        .equipment-card img {
            width: 180px;
            height: 140px;
            object-fit: contain;
            border-radius: 0.5rem;
            background: white;
            padding: 0.5rem;
            flex-shrink: 0;
        }
        .equipment-card-body { flex: 1; }
        .equipment-card-body h4 {
            font-family: var(--font-sans);
            font-size: 1.0625rem;
            font-weight: 700;
            color: var(--color-primary);
            margin-bottom: 0.25rem;
        }
        .equipment-card-body .eq-subtitle {
            font-family: var(--font-sans);
            font-size: 0.8125rem;
            color: var(--color-text-muted);
            margin-bottom: 0.5rem;
        }
        .equipment-card-body p {
            font-size: 0.9375rem;
            margin-bottom: 0.5rem;
        }
        .score-badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 0.25rem;
            font-family: var(--font-sans);
            font-size: 0.8125rem;
            font-weight: 700;
        }
        .score-badge.elite { background: #ecfdf5; color: #059669; }
        .score-badge.capable { background: #eff6ff; color: #2563eb; }
        .score-badge.acceptable { background: #fffbeb; color: #b45309; }

        /* ── Formula Block ── */
        .formula-block {
            background: var(--color-bg-alt);
            border: 1px solid var(--color-border);
            border-radius: 0.5rem;
            padding: 1rem 1.5rem;
            margin: 1.25rem 0;
            font-family: 'Cambria Math', 'Times New Roman', serif;
            font-size: 1rem;
            text-align: center;
            font-style: italic;
        }

        /* ── Scoring Tier Legend ── */
        .tier-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin: 1.25rem 0;
        }
        .tier-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 0.75rem;
            border-radius: 0.375rem;
            font-family: var(--font-sans);
            font-size: 0.8125rem;
            font-weight: 600;
        }
        .tier-item .tier-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* ── Image Grid ── */
        .image-pair {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin: 1.5rem 0;
        }
        .image-pair .img-card {
            text-align: center;
        }
        .image-pair .img-card img {
            width: 100%;
            max-height: 200px;
            object-fit: contain;
            border-radius: 0.5rem;
            background: white;
            border: 1px solid var(--color-border);
            padding: 0.75rem;
        }
        .image-pair .img-card .img-caption {
            font-family: var(--font-sans);
            font-size: 0.75rem;
            color: var(--color-text-muted);
            margin-top: 0.5rem;
        }

        /* ── Highlight Box ── */
        .highlight-box {
            background: linear-gradient(135deg, #fef2f2 0%, #fff7ed 100%);
            border: 1px solid #fecaca;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }
        .highlight-box h4 {
            font-family: var(--font-sans);
            font-size: 1rem;
            font-weight: 700;
            color: var(--color-accent);
            margin-bottom: 0.5rem;
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .report-body, .toc-section { padding-left: 1.5rem; padding-right: 1.5rem; }
            .cover-page { padding: 2rem 1.5rem; }
            .cover-title { font-size: 1.625rem; }
            .equipment-card { flex-direction: column; }
            .equipment-card img { width: 100%; max-width: 240px; height: auto; }
            .tier-grid { grid-template-columns: 1fr 1fr; }
            .image-pair { grid-template-columns: 1fr; }
            table.report-table { font-size: 0.75rem; }
        }
    </style>
</head>
<body>

<!-- ══ Print / Save as PDF Button ══ -->
<div class="print-fab no-print">
    <button class="btn-pdf" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
        Save as PDF
    </button>
</div>

<div class="report-container">

    <!-- ══════════════════════════════════════════
         COVER PAGE
         ══════════════════════════════════════════ -->
    <div class="cover-page">
        <img src="/images/workgroup-summary/mbfd_logo.png" alt="MBFD Logo" class="cover-logo">
        <img src="/images/workgroup-summary/workgroup_image.png" alt="MBFD Workgroup" class="cover-hero-img">
        <h1 class="cover-title">Mid-Mount Ladder Workgroup</h1>
        <p class="cover-subtitle">Equipment Evaluation, Procurement Analysis, and Final Selection Report — Q1 2026</p>
        <div class="cover-divider"></div>
        <div class="cover-meta">
            <strong>Prepared By:</strong> MBFD Support Services<br>
            <strong>Prepared For:</strong> Fire Chief Digna Abello · Health &amp; Safety Committee<br>
            <strong>Reviewing Authority:</strong> Miami Beach Fire Department Command Staff<br>
            <strong>Report Designation:</strong> MBFD-WG-EVAL-2026-001<br>
            <strong>Date:</strong> March 2026
        </div>
        <div class="cover-badge">Approved for Internal Department Release</div>
    </div>

    <!-- ══════════════════════════════════════════
         TABLE OF CONTENTS
         ══════════════════════════════════════════ -->
    <div class="toc-section">
        <h2 class="toc-title">Table of Contents</h2>
        <ol class="toc-list">
            <li><span class="toc-num">1</span><a href="#sec-executive-summary">Executive Summary</a></li>
            <li><span class="toc-num">2</span><a href="#sec-background">Workgroup Background and Purpose</a></li>
            <li><span class="toc-num">3</span><a href="#sec-operational-context">Operational Context</a></li>
            <li><span class="toc-num">4</span><a href="#sec-methodology">Evaluation Framework and Methodology</a></li>
            <li><span class="toc-num">5</span><a href="#sec-results">Finalist Evaluation Results</a></li>
            <li><span class="toc-num">6</span><a href="#sec-specialty">Specialty Tool Considerations</a></li>
            <li><span class="toc-num">7</span><a href="#sec-determinations">Final Workgroup Determinations and Purchasing Recommendations</a></li>
            <li><span class="toc-num">8</span><a href="#sec-deployment">Apparatus Deployment and Implementation Strategy</a></li>
            <li><span class="toc-num">9</span><a href="#sec-training">Training and In-Service Plan</a></li>
            <li><span class="toc-num">10</span><a href="#sec-conclusion">Strategic Conclusion</a></li>
            <li><span class="toc-num">A</span><a href="#sec-appendix-a">Appendix A — Product Evaluation Data Summary</a></li>
            <li><span class="toc-num">B</span><a href="#sec-appendix-b">Appendix B — Data Source Notes</a></li>
            <li><span class="toc-num">C</span><a href="#sec-appendix-c">Appendix C — Scoring Key</a></li>
        </ol>
    </div>

    <!-- ══════════════════════════════════════════
         REPORT BODY
         ══════════════════════════════════════════ -->
    <div class="report-body">

        <!-- §1 Executive Summary -->
        <div class="report-section" id="sec-executive-summary">
            <h2 class="section-heading"><span class="section-num">1.</span> Executive Summary</h2>
            <p>In February 2026, the Miami Beach Fire Department convened a specialized workgroup to evaluate, rank, and select loose equipment for integration into a new Pierce Velocity 100-foot Ascendant mid-mount aerial tower. The workgroup conducted structured, multi-day hands-on evaluations of battery-operated extrication tools, rotary cut-off saws, vehicle stabilization systems, and specialty breaching tools from six manufacturers across fourteen product lines.</p>
            <p>The evaluation dataset was forensically reconstructed to remove cost-based scoring influences. The original five-pillar model—which included an "Affordability" dimension—was corrected to a four-pillar framework reflecting pure operational performance: <strong>Capability, Usability, Maintainability, and Deployability</strong>. All scores reported in this document reflect the corrected methodology.</p>

            <div class="key-findings">
                <h3 class="subsection-heading">Key Findings</h3>
                <ul>
                    <li><strong>Holmatro (Pentheon Series)</strong> achieved the highest corrected brand average among extrication manufacturers at <strong class="score-elite">90.72</strong>, leading all four scoring dimensions. The Holmatro PSP40 spreader earned the single highest corrected tool score in the evaluation at <strong class="score-elite">92.02</strong>.</li>
                    <li><strong>Hurst (eDRAULIC E3 Series)</strong> ranked second with a corrected brand average of <strong class="score-capable">86.53</strong>, demonstrating competitive Capability scores (87.56) and superior saltwater protection (IP58).</li>
                    <li>The <strong>DeWalt 12-inch POWERSHIFT cut-off saw</strong> dominated the rotary saw category with a corrected score of <strong class="score-elite">91.25</strong>, outperforming 14-inch competitors by more than 26 points.</li>
                    <li><strong>Holmatro V-Struts</strong> led vehicle stabilization at <strong class="score-capable">87.28</strong> with 15-second auto-lock deployment.</li>
                </ul>
            </div>

            <div class="highlight-box">
                <h4>Final Workgroup Determination</h4>
                <p>Despite Holmatro's evaluation leadership, the workgroup selected the <strong>Hurst E3 CAPTIUM platform</strong> (SP 777 E3 spreader, S 789 E3 cutter, CR 522 E3 ram) as the primary frontline extrication system. This determination reflects operational factors beyond pure score ranking—including IP58 saltwater certification critical for Miami Beach's coastal environment, ecosystem compatibility with the Hurst M40 heavy spreader assigned to the battalion chief's vehicle, and Captium cloud-based fleet management integration.</p>
            </div>

            <p>The complete recommended equipment package includes the Hurst E3 extrication suite, DeWalt 12-inch cut-off saw, DeWalt 18-inch chainsaw, four Holmatro V-Struts, the Holmatro T1 on trial basis as a rabbit tool replacement, and the Hurst M40 as a supplemental heavy extrication asset on the 300 vehicle.</p>
        </div>

        <!-- §2 Background -->
        <div class="report-section page-break" id="sec-background">
            <h2 class="section-heading"><span class="section-num">2.</span> Workgroup Background and Purpose</h2>

            <h3 class="subsection-heading">2.1 Origin and Command Directive</h3>
            <p>The Mid-Mount Ladder Workgroup was established by MBFD command to address a fundamental operational challenge: the department's acquisition of a new Pierce Velocity 100-foot Ascendant aerial tower with a mid-mount configuration required a complete reassessment of loose equipment stowage, tool selection, and deployment doctrine.</p>
            <p>Unlike rear-mount aerial platforms, a mid-mount configuration introduces asymmetrical compartment geometry that precludes direct transfer of existing equipment layouts. The workgroup was tasked with evaluating the commercial market, conducting structured hands-on testing, and delivering a consensus-driven "Top 3" finalist list for procurement authorization.</p>

            <h3 class="subsection-heading">2.2 Command Structure</h3>
            <ul style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li><strong>Consensus-driven model:</strong> All configuration decisions were driven by workgroup vote</li>
                <li><strong>12 active workgroup members</strong> serving as tactical decision-makers</li>
                <li><strong>Support Services</strong> provided logistics, intelligence, and data analysis</li>
                <li><strong>Health &amp; Safety Committee</strong> held final procurement authorization</li>
                <li><strong>Fire Chief Digna Abello</strong> served as reviewing authority</li>
            </ul>

            <h3 class="subsection-heading">2.3 Day 1 Objectives</h3>
            <ol style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li><strong>Evaluate, rank, and select</strong> loose equipment across all frontline operational categories</li>
                <li><strong>Deliver a finalized procurement recommendation</strong> to the Health &amp; Safety Committee</li>
                <li><strong>Assess platform ecosystem strategy</strong>—specifically whether to commit exclusively to the DeWalt battery platform to match the truck's existing charging infrastructure</li>
                <li><strong>Optimize for narrow-profile tools</strong> compatible with asymmetrical compartment constraints</li>
                <li><strong>Address the saltwater/coastal exposure factor</strong> in tool selection criteria</li>
            </ol>
            <p>The workgroup identified four primary evaluation criteria from the outset: <strong>Cost, Interoperability, Serviceability, and Lifecycle</strong>—which were later refined into the structured four-dimension scoring framework.</p>
        </div>

        <!-- §3 Operational Context -->
        <div class="report-section page-break" id="sec-operational-context">
            <h2 class="section-heading"><span class="section-num">3.</span> Operational Context</h2>

            <h3 class="subsection-heading">3.1 The Mid-Mount Platform</h3>
            <p>The subject apparatus—Pierce Velocity 100-foot Ascendant aerial tower, Job #41559AD—presents specific engineering constraints that shaped the workgroup's evaluation priorities:</p>
            <ul style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li><strong>TAK-4 independent suspension</strong> with 24,880 lb rating and low center of gravity</li>
                <li><strong>2,000 GPM water output pump</strong> supporting high-flow aerial and handline operations</li>
                <li><strong>Overall length of 528.50 inches</strong> (44 feet, 0.5 inches)</li>
                <li><strong>Mid-mount turntable placement</strong> displacing traditional compartment volumes</li>
            </ul>

            <h3 class="subsection-heading">3.2 The Compartment Challenge: Asymmetrical Storage</h3>
            <p>The most consequential platform constraint identified on Day 1 was the asymmetrical compartment architecture inherent to the mid-mount design:</p>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Side</th><th>Configuration</th><th>Implication</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Driver Side (L Zones)</strong></td><td>Full height, full depth</td><td>Primary bulk storage for fans, saws, rescue kits</td></tr>
                        <tr><td><strong>Officer Side (R Zones)</strong></td><td>Full height, <em>reduced depth</em></td><td>Torque box intrusion limits stowage depth</td></tr>
                    </tbody>
                </table>
            </div>
            <p>The workgroup characterized this as "asymmetrical warfare"—the officer-side compartments cannot accommodate the same tool profiles as the driver side. This constraint eliminated the possibility of copying equipment layouts from existing apparatus and mandated optimization for narrow-profile tools across multiple categories.</p>
            <div class="callout-box">
                <p><strong>Strategic implication:</strong> The department could not simply replicate existing configurations. Every tool selection required evaluation against the physical geometry of both compartment zones.</p>
            </div>

            <h3 class="subsection-heading">3.3 Coastal and Environmental Factors</h3>
            <p>Miami Beach's barrier-island geography imposes environmental requirements on rescue equipment that inland departments do not face:</p>
            <ul style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li><strong>Saltwater exposure:</strong> Proximity to the Atlantic Ocean and Biscayne Bay subjects equipment to persistent salt air and potential submersion scenarios</li>
                <li><strong>IP rating significance:</strong> Ingress Protection ratings became a differentiating evaluation factor, with IP58 (saltwater-rated) tools receiving operational preference over IP54/IP57 alternatives</li>
                <li><strong>Corrosion lifecycle:</strong> Equipment lifecycle projections must account for accelerated metallic degradation in marine environments</li>
            </ul>

            <h3 class="subsection-heading">3.4 Platform Ecosystem Strategy</h3>
            <p>A foundational strategic question raised on Day 1 was whether MBFD should commit exclusively to the DeWalt battery platform to match the truck's integrated charging infrastructure. The workgroup identified this as a decision point across three categories:</p>
            <ul style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li><strong>Extrication tools:</strong> Proprietary battery ecosystems (Hurst eDRAULIC, Holmatro Pentheon) vs. COTS platforms (Amkus on DeWalt 60V)</li>
                <li><strong>Forcible entry tools:</strong> Battery-powered vs. manual hydraulic options</li>
                <li><strong>Saws:</strong> Multiple competing battery architectures (DeWalt POWERSHIFT, Makita XGT, Husqvarna 94V)</li>
            </ul>
            <p>The workgroup ultimately adopted a <strong>hybrid ecosystem strategy</strong>—selecting brand-specific proprietary batteries for primary extrication tools (where performance requirements dictate the power platform) while adopting COTS DeWalt batteries for secondary tools including saws and chainsaws.</p>
        </div>

        <!-- §4 Methodology -->
        <div class="report-section page-break" id="sec-methodology">
            <h2 class="section-heading"><span class="section-num">4.</span> Evaluation Framework and Methodology</h2>

            <h3 class="subsection-heading">4.1 Original Scoring Model</h3>
            <p>The workgroup's initial evaluation framework employed a five-dimension scoring model applied across all candidate products during hands-on testing sessions. Each dimension was scored on a 0–100 scale:</p>
            <ol style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li><strong>Capability</strong> — Raw performance: force output, cutting capacity, spreading distance, operational power</li>
                <li><strong>Usability</strong> — Ergonomic design, control interface, operator comfort, learning curve</li>
                <li><strong>Maintainability</strong> — Service requirements, durability, component accessibility, field-repairability</li>
                <li><strong>Deployability</strong> — Storage footprint, activation speed, weight, cordless readiness, compartment fit</li>
                <li><strong>Affordability</strong> — Cost-based considerations</li>
            </ol>

            <h3 class="subsection-heading">4.2 Analytical Correction: Removal of Affordability</h3>
            <p>During post-evaluation data analysis, the Affordability dimension was identified as analytically problematic. Cost-based scoring introduced variability that did not reflect the pure operational performance of the tools under evaluation.</p>
            <div class="formula-block">
                Corrected Overall Score = <em>f</em>(Capability, Usability, Maintainability, Deployability)
            </div>
            <p>All scores presented in this report—brand averages, individual tool scores, and category rankings—reflect this corrected methodology with Affordability permanently removed.</p>

            <h3 class="subsection-heading">4.3 Extrication Brand Scoring Standardization</h3>
            <p>Brand-level averages for extrication manufacturers were computed <strong>exclusively from the performance of the mandatory frontline tactical triad</strong>: the 32-inch spreader, cutter, and ram platforms. This standardization ensured that:</p>
            <ul style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li><strong>The Hurst M40</strong> (40-inch heavy spreader) was excluded from the Hurst baseline brand average</li>
                <li><strong>The Holmatro T1</strong> (forcible entry tool) was excluded from the Holmatro baseline brand average</li>
                <li>Both tools were isolated into <strong>standalone specialty analysis</strong></li>
            </ul>

            <h3 class="subsection-heading">4.4 Scoring Classification</h3>
            <div class="tier-grid">
                <div class="tier-item" style="background: #ecfdf5;">
                    <span class="tier-dot" style="background: #059669;"></span>
                    <span>90+ Elite</span>
                </div>
                <div class="tier-item" style="background: #eff6ff;">
                    <span class="tier-dot" style="background: #2563eb;"></span>
                    <span>80–89 Highly Capable</span>
                </div>
                <div class="tier-item" style="background: #fffbeb;">
                    <span class="tier-dot" style="background: #b45309;"></span>
                    <span>70–79 Acceptable</span>
                </div>
                <div class="tier-item" style="background: #fef2f2;">
                    <span class="tier-dot" style="background: #dc2626;"></span>
                    <span>&lt; 70 Deficient</span>
                </div>
            </div>
        </div>

        <!-- §5 Results -->
        <div class="report-section page-break" id="sec-results">
            <h2 class="section-heading"><span class="section-num">5.</span> Finalist Evaluation Results</h2>

            <h3 class="subsection-heading">5.1 Extrication Brand Overall Performance (Corrected)</h3>
            <p>Four extrication manufacturers advanced to the final evaluation. Brand averages are derived strictly from the frontline triad (32-inch spreader, cutter, ram):</p>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Rank</th><th>Brand</th><th>Corrected Avg</th><th>Capability</th><th>Usability</th><th>Maintainability</th><th>Deployability</th></tr></thead>
                    <tbody>
                        <tr><td><span class="rank-badge rank-1">#1</span></td><td><strong>Holmatro</strong></td><td class="score-elite">90.72</td><td>89.41</td><td>93.99</td><td>89.82</td><td>89.69</td></tr>
                        <tr><td><span class="rank-badge rank-2">#2</span></td><td><strong>Hurst</strong></td><td class="score-capable">86.53</td><td>87.56</td><td>85.76</td><td>82.58</td><td>82.74</td></tr>
                        <tr><td><span class="rank-badge rank-3">#3</span></td><td>TNT</td><td class="score-acceptable">75.12</td><td>74.67</td><td>78.38</td><td>65.78</td><td>81.67</td></tr>
                        <tr><td><span class="rank-badge rank-4">#4</span></td><td>Amkus</td><td class="score-acceptable">73.56</td><td>84.21</td><td>66.98</td><td>69.14</td><td>73.89</td></tr>
                    </tbody>
                </table>
            </div>
            <p>Holmatro achieved Elite classification (90+) while Hurst achieved Highly Capable (80–89). The most significant dimension gap between the top two brands occurred in <strong>Usability</strong> (+8.23 points favoring Holmatro). The narrowest gap appeared in <strong>Capability</strong> (+1.85), confirming that both platforms deliver competitive raw performance.</p>

            <h3 class="subsection-heading">5.2 Frontline Extrication Tools: Individual Corrected Scores</h3>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Tool Category</th><th>Product Model</th><th>Manufacturer</th><th>Corrected Score</th></tr></thead>
                    <tbody>
                        <tr><td>Spreader</td><td>PSP40 (32-inch)</td><td>Holmatro</td><td class="score-elite">92.02</td></tr>
                        <tr><td>Ram</td><td>PRA40</td><td>Holmatro</td><td class="score-elite">91.29</td></tr>
                        <tr><td>Spreader</td><td>SP 777 E3 (32-inch)</td><td>Hurst</td><td class="score-elite">90.99</td></tr>
                        <tr><td>Cutter</td><td>PCU30CL</td><td>Holmatro</td><td class="score-capable">88.86</td></tr>
                        <tr><td>Ram</td><td>CR 522 E3</td><td>Hurst</td><td class="score-capable">86.02</td></tr>
                        <tr><td>Cutter</td><td>S 789 E3</td><td>Hurst</td><td class="score-capable">82.57</td></tr>
                    </tbody>
                </table>
            </div>

            <h4 class="sub-subsection-heading">Spreader Comparison</h4>
            <div class="image-pair">
                <div class="img-card">
                    <img src="/images/workgroup-summary/holmatro-psp40-spreader.png" alt="Holmatro PSP40 Spreader" loading="lazy">
                    <p class="img-caption">Holmatro PSP40 — 92.02 (Elite)</p>
                </div>
                <div class="img-card">
                    <img src="/images/workgroup-summary/hurst-spreader.png" alt="Hurst SP 777 E3 Spreader" loading="lazy">
                    <p class="img-caption">Hurst SP 777 E3 — 90.99 (Elite)</p>
                </div>
            </div>
            <p>The Holmatro PSP40 achieved the <strong>highest individual score in the entire evaluation</strong> at 92.02, leading the Hurst SP 777 E3 (90.99) by 1.03 points. Both earned Elite classification. The PSP40's advantage derived primarily from its 360-degree inline control handle and Extreme Grip Spreader Tips, while the Hurst SP 777 E3 countered with substantially higher maximum spreading force (600 kN vs. 280 kN), wider 32-inch spreading distance (vs. 28.5 inches), and IP58 watertight design rated for saltwater operations.</p>

            <h4 class="sub-subsection-heading">Cutter Comparison</h4>
            <div class="equipment-card">
                <img src="/images/workgroup-summary/holmatro-pcu30cl-cutter.png" alt="Holmatro PCU30CL Cutter" loading="lazy">
                <div class="equipment-card-body">
                    <h4>Holmatro PCU30CL vs. Hurst S 789 E3</h4>
                    <p class="eq-subtitle">Cutter Category — Largest performance gap among frontline tools</p>
                    <p>The Holmatro PCU30CL led by <strong>6.29 points</strong> (88.86 vs. 82.57). The PCU30CL's 30-degree inclined jaw design maximizes working space between the tool and the vehicle, enabling safer and faster cutting without repositioning. The i-Bolt construction eliminates retightening the central bolt after each use.</p>
                    <span class="score-badge capable">Δ 6.29 pts</span>
                </div>
            </div>

            <h4 class="sub-subsection-heading">Ram Comparison</h4>
            <div class="equipment-card">
                <img src="/images/workgroup-summary/holmatro-pra40-ram.png" alt="Holmatro PRA40 Ram" loading="lazy">
                <div class="equipment-card-body">
                    <h4>Holmatro PRA40 vs. Hurst CR 522 E3</h4>
                    <p class="eq-subtitle">Ram Category — 91.29 vs. 86.02</p>
                    <p>The PRA40 dominated ram evaluations with <strong>91.29</strong> versus <strong>86.02</strong> (Δ = 5.27). Its integrated laser pointer provides first-time-right positioning, and at 31.1 lbs with a retracted length of only 15.2 inches, it is particularly suited to mid-mount compartment constraints.</p>
                    <span class="score-badge elite">91.29 Elite</span>
                </div>
            </div>

            <h3 class="subsection-heading">5.3 Rotary Cut-Off Saws</h3>
            <div class="equipment-card">
                <img src="/images/workgroup-summary/dewalt-cutoff-saw.png" alt="DeWalt 12-inch Cut-Off Saw" loading="lazy">
                <div class="equipment-card-body">
                    <h4>DeWalt 12" POWERSHIFT (DCPS612AG2)</h4>
                    <p class="eq-subtitle">Rotary Saw Category Winner — Categorical Dominance</p>
                    <p>Established categorical dominance with a <strong class="score-elite">91.25</strong> corrected score—outperforming the Makita by 26.42 points and the Husqvarna by 29.47 points. Gear-driven power delivery (not belt-driven), 3-second electric brake, and superior ergonomics rendered the 14-inch competitors operationally inferior.</p>
                    <span class="score-badge elite">91.25 Elite</span>
                </div>
            </div>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Rank</th><th>Product</th><th>Manufacturer</th><th>Score</th></tr></thead>
                    <tbody>
                        <tr><td><span class="rank-badge rank-1">#1</span></td><td>12-inch (DCPS612AG2)</td><td>DeWalt</td><td class="score-elite">91.25</td></tr>
                        <tr><td><span class="rank-badge rank-2">#2</span></td><td>14-inch (GEC01PL4)</td><td>Makita</td><td class="score-deficient">64.83</td></tr>
                        <tr><td><span class="rank-badge rank-3">#3</span></td><td>14-inch (K1 Pace)</td><td>Husqvarna</td><td class="score-deficient">61.78</td></tr>
                    </tbody>
                </table>
            </div>

            <h3 class="subsection-heading">5.4 Vehicle Stabilization</h3>
            <div class="image-pair">
                <div class="img-card">
                    <img src="/images/workgroup-summary/v-strut.png" alt="Holmatro V-Strut" loading="lazy">
                    <p class="img-caption">Holmatro V-Strut — 87.28</p>
                </div>
                <div class="img-card">
                    <img src="/images/workgroup-summary/paratech-strut-driver.png" alt="Paratech StrutDriver" loading="lazy">
                    <p class="img-caption">Paratech StrutDriver — 76.13</p>
                </div>
            </div>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Rank</th><th>System</th><th>Manufacturer</th><th>Score</th></tr></thead>
                    <tbody>
                        <tr><td><span class="rank-badge rank-1">#1</span></td><td>V-Strut (Auto-locking)</td><td>Holmatro</td><td class="score-capable">87.28</td></tr>
                        <tr><td><span class="rank-badge rank-2">#2</span></td><td>OmniShore (Pneumatic)</td><td>Holmatro</td><td class="score-capable">85.87</td></tr>
                        <tr><td><span class="rank-badge rank-3">#3</span></td><td>Strut Driver (Mechanical)</td><td>Paratech</td><td class="score-acceptable">76.13</td></tr>
                    </tbody>
                </table>
            </div>
            <p>The Holmatro V-Strut's auto-lock mechanism enables deployment in approximately <strong>15 seconds</strong>—a single pull-and-lock movement with no separate locking operation required. At 15.87 lbs (7.2 kg), it is the lightest system evaluated.</p>
        </div>

        <!-- §6 Specialty -->
        <div class="report-section page-break" id="sec-specialty">
            <h2 class="section-heading"><span class="section-num">6.</span> Specialty Tool Considerations</h2>
            <p>The workgroup isolated two tools for standalone analysis. Due to their unique engineering, dimensions, and tactical deployment profiles, including them in standard frontline brand averages would produce mathematically inaccurate capability assessments.</p>

            <h3 class="subsection-heading">6.1 Holmatro T1 — Multi-Purpose Forcible Entry Tool</h3>
            <div class="equipment-card">
                <img src="/images/workgroup-summary/holmatro-t1.png" alt="Holmatro T1" loading="lazy">
                <div class="equipment-card-body">
                    <h4>Holmatro T1</h4>
                    <p class="eq-subtitle">Six-function-in-one tool — Cut, Wedge, Ram, Spread, Hammer, Lift</p>
                    <span class="score-badge capable">82.23 Highly Capable</span>
                </div>
            </div>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Specification</th><th>Value</th></tr></thead>
                    <tbody>
                        <tr><td>Weight</td><td>17.0 lbs (7.7 kg)</td></tr>
                        <tr><td>Spreading Force</td><td>33.0 kN (7,419 lbf)</td></tr>
                        <tr><td>Cutting Force</td><td>139.0 kN (31,248 lbf)</td></tr>
                        <tr><td>Max Spreading Distance</td><td>5.0 in (128 mm)</td></tr>
                        <tr><td>Cutting Opening</td><td>1.1 in (29 mm)</td></tr>
                        <tr><td>Power Source</td><td>Manual hydraulic (2-stage hand pump)</td></tr>
                    </tbody>
                </table>
            </div>
            <p>The T1 operates as a fully self-contained device—no batteries, no external pump, no hoses. Application of 30 kg (66 lbs) of manual force on the pump rod generates up to 14.2 tons of hydraulic cutting force and 3.4 tons of spreading force. The workgroup evaluated the T1 specifically as a <strong>potential replacement for the traditional rabbit tool</strong>. The T1's detachable wedge enables single-operator door breaching.</p>
            <div class="callout-box">
                <p><strong>Workgroup consensus</strong> strongly recommends the T1 for rapid entry teams. The tool has been authorized for <strong>trial deployment</strong> rather than immediate full adoption.</p>
            </div>

            <h3 class="subsection-heading">6.2 Hurst M40 — 40-Inch Heavy Spreader</h3>
            <div class="equipment-card">
                <img src="/images/workgroup-summary/hurst-m40.png" alt="Hurst M40 Heavy Spreader" loading="lazy">
                <div class="equipment-card-body">
                    <h4>Hurst M40</h4>
                    <p class="eq-subtitle">40-inch heavy rescue spreader — Supplemental command-level asset</p>
                    <p>Provides 40-inch spreading distance for heavy rescue operations. Capability score of 83.39 demonstrates substantial raw power, but its Usability score of <strong>79.29—the lowest in the entire dataset</strong>—reflects the immense physical footprint.</p>
                    <span class="score-badge acceptable">78.80 Acceptable</span>
                </div>
            </div>
            <p>The M40 is <strong>intended for heavy rescue apparatus and should not be deployed as a primary tool on standard frontline engines</strong>. Within MBFD's fleet strategy, the M40 is selected as a supplemental asset for the 300 (battalion chief's vehicle).</p>
        </div>

        <!-- §7 Determinations -->
        <div class="report-section page-break" id="sec-determinations">
            <h2 class="section-heading"><span class="section-num">7.</span> Final Workgroup Determinations and Purchasing Recommendations</h2>

            <h3 class="subsection-heading">7.1 The Selection–Score Divergence</h3>
            <p>The most significant analytical finding in this report is the divergence between evaluation scoring leadership and the final workgroup selection for the primary extrication platform.</p>
            <p><strong>Holmatro (Pentheon Series)</strong> led every frontline scoring dimension and achieved the highest brand average (90.72), the highest individual tool score (PSP40 at 92.02), and Elite classification across the board.</p>
            <p><strong>The workgroup selected Hurst (eDRAULIC E3 CAPTIUM)</strong> as the primary extrication platform. This determination was not an error. The workgroup exercised its consensus-driven authority to weigh operational factors:</p>
            <ol style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li><strong>Saltwater certification (IP58):</strong> The Hurst E3 platform carries IP58 watertight certification rated for saltwater operations. For a barrier-island department, this represents a measurable operational advantage.</li>
                <li><strong>Ecosystem interoperability with the M40:</strong> Selecting Hurst for both the frontline triad and the supplemental heavy spreader consolidates battery platform, maintenance pipeline, vendor relationship, and training requirements.</li>
                <li><strong>Captium cloud integration:</strong> The E3 Connect platform with Wi-Fi data transfer provides fleet-management capabilities—tool usage tracking, battery health monitoring, and maintenance scheduling.</li>
                <li><strong>Capability parity:</strong> The Capability dimension gap between Holmatro (89.41) and Hurst (87.56) is only 1.85 points—effectively at parity.</li>
            </ol>

            <h3 class="subsection-heading">7.2 Final Selected Equipment Package</h3>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Category</th><th>Selected Equipment</th><th>Platform Assignment</th></tr></thead>
                    <tbody>
                        <tr><td>Cut-Off Saw</td><td>DeWalt 12" Battery Cut-Off Saw (DCPS612AG2)</td><td>Frontline (L1/L3)</td></tr>
                        <tr><td>Chainsaw</td><td>DeWalt 18" Chainsaw (bullet chain + depth markings)</td><td>Frontline (L1/L3)</td></tr>
                        <tr><td>Stabilization</td><td>4 × Holmatro V-Struts</td><td>Frontline (L1/L3)</td></tr>
                        <tr><td>Spreader</td><td>Hurst SP 777 E3 Connect</td><td>Frontline — CAPTIUM Platform</td></tr>
                        <tr><td>Cutter</td><td>Hurst S 789 E3 Connect</td><td>Frontline — CAPTIUM Platform</td></tr>
                        <tr><td>Ram</td><td>Hurst CR 522 E3 Connect</td><td>Frontline — CAPTIUM Platform</td></tr>
                        <tr><td>Specialty Tool</td><td>Holmatro T1 (Trial Authorization)</td><td>Rabbit Tool Replacement Candidate</td></tr>
                        <tr><td>Heavy Extrication</td><td>Hurst M40 + 2 batteries + charging station</td><td>300 (Battalion Chief Vehicle)</td></tr>
                        <tr><td>Future Addition</td><td>Lifting Struts (pending evaluation)</td><td>300 / Captain 5</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="image-pair">
                <div class="img-card">
                    <img src="/images/workgroup-summary/dewalt-chainsaw.png" alt="DeWalt 18-inch Chainsaw" loading="lazy">
                    <p class="img-caption">DeWalt 18" Chainsaw — Selected for Ventilation Ops</p>
                </div>
                <div class="img-card">
                    <img src="/images/workgroup-summary/dewalt-cutoff-saw.png" alt="DeWalt 12-inch Cut-Off Saw" loading="lazy">
                    <p class="img-caption">DeWalt 12" POWERSHIFT — 91.25 Elite</p>
                </div>
            </div>
        </div>

        <!-- §8 Deployment -->
        <div class="report-section page-break" id="sec-deployment">
            <h2 class="section-heading"><span class="section-num">8.</span> Apparatus Deployment and Implementation Strategy</h2>

            <h3 class="subsection-heading">8.1 Tiered Deployment Model</h3>
            <p>The workgroup established a tiered deployment architecture ensuring full operational independence across all rescue scenarios without dependency on mutual aid.</p>

            <h4 class="sub-subsection-heading">Frontline Apparatus (L1 / L3)</h4>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Equipment</th><th>Deployment Role</th></tr></thead>
                    <tbody>
                        <tr><td>Hurst SP 777 E3 Connect Spreader</td><td>Primary extrication spreading</td></tr>
                        <tr><td>Hurst S 789 E3 Connect Cutter</td><td>Primary extrication cutting</td></tr>
                        <tr><td>Hurst CR 522 E3 Connect Ram</td><td>Extension / displacement operations</td></tr>
                        <tr><td>4 × Holmatro V-Struts</td><td>Rapid vehicle stabilization</td></tr>
                        <tr><td>DeWalt 12" Battery Cut-Off Saw</td><td>Rapid entry / concrete-steel cutting</td></tr>
                        <tr><td>DeWalt 18" Chainsaw</td><td>Ventilation operations</td></tr>
                        <tr><td>Holmatro T1 (trial)</td><td>Multi-function access / forcible entry</td></tr>
                    </tbody>
                </table>
            </div>

            <h4 class="sub-subsection-heading">Command Level (300 / Captain 5)</h4>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Equipment</th><th>Deployment Role</th></tr></thead>
                    <tbody>
                        <tr><td>Hurst M40 40" Spreader</td><td>Heavy extrication beyond standard parameters</td></tr>
                        <tr><td>2 × CAPTIUM Batteries</td><td>Extended power supply</td></tr>
                        <tr><td>Charging Station</td><td>Field recharging capability</td></tr>
                        <tr><td>Lifting Struts (future)</td><td>Vehicle lifting operations</td></tr>
                    </tbody>
                </table>
            </div>

            <h4 class="sub-subsection-heading">Heavy Rescue (E2 / Box Truck)</h4>
            <p>High-capacity rescue systems for extreme lifting and technical rescue scenarios. <strong>Strategic goal:</strong> Full operational independence across all rescue levels—ensuring MBFD units can handle any vehicle extrication scenario without dependency on mutual aid resources.</p>
        </div>

        <!-- §9 Training -->
        <div class="report-section page-break" id="sec-training">
            <h2 class="section-heading"><span class="section-num">9.</span> Training and In-Service Plan</h2>

            <h3 class="subsection-heading">9.1 Train-the-Trainer Model</h3>
            <p>Workgroup members who participated in the evaluation process will serve as <strong>subject matter experts (SMEs)</strong> and lead departmental training through a phased implementation.</p>

            <h4 class="sub-subsection-heading">Phase 1 — Vendor Training</h4>
            <p>Workgroup members receive comprehensive manufacturer-led training on all selected equipment systems. Members achieve proficiency qualification on each platform before conducting internal training.</p>

            <h4 class="sub-subsection-heading">Phase 2 — Department-Wide Rollout</h4>
            <p>Department personnel are divided across shift groups. Each workgroup SME trains their assigned shift. <strong>No overtime is required</strong>—training is integrated into existing shift schedules, minimizing budget impact while ensuring complete departmental coverage.</p>

            <h4 class="sub-subsection-heading">Phase 3 — Deep Familiarization</h4>
            <p>All personnel complete training covering:</p>
            <ul style="padding-left: 1.5rem; margin-bottom: 1rem;">
                <li>Tool operation and safety protocols for each selected system</li>
                <li>Tactical application in vehicle extrication scenarios specific to the mid-mount platform</li>
                <li>Battery management and field charging procedures across both Hurst CAPTIUM and DeWalt ecosystems</li>
                <li>V-Strut stabilization deployment procedures</li>
                <li>Integration of the T1 trial tool into existing forcible entry protocols</li>
                <li>M40 heavy spreader operation for personnel assigned to the 300</li>
            </ul>

            <h3 class="subsection-heading">9.2 Ladder Truck In-Service Requirements</h3>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Training Component</th><th>Requirement</th></tr></thead>
                    <tbody>
                        <tr><td>Driving Operations</td><td>Full certification on mid-mount ladder configuration</td></tr>
                        <tr><td>Pumping Operations</td><td>Operational proficiency on all pump functions</td></tr>
                        <tr><td>Tactical Deployment</td><td>Extrication tool deployment from mid-mount compartments</td></tr>
                        <tr><td>Equipment Integration</td><td>Familiarization with all selected equipment as installed</td></tr>
                    </tbody>
                </table>
            </div>
            <p>All training components must be completed <strong>before</strong> the apparatus enters service.</p>
        </div>

        <!-- §10 Conclusion -->
        <div class="report-section page-break" id="sec-conclusion">
            <h2 class="section-heading"><span class="section-num">10.</span> Strategic Conclusion</h2>
            <p>The MBFD Mid-Mount Ladder Workgroup has completed a rigorous, data-driven evaluation of rescue equipment spanning six manufacturers, fourteen products, and four operational categories. The corrected four-pillar scoring framework—with Affordability permanently removed—provides command staff with an unambiguous performance baseline for current and future procurement decisions.</p>
            <p>The final equipment package reflects a deliberate balance of quantitative evaluation data and operational judgment. Where the scoring data pointed clearly to a single solution (DeWalt for saws, Holmatro for stabilization), the workgroup followed the data directly. Where the top two extrication platforms demonstrated competitive performance with differentiated operational advantages, the workgroup exercised its consensus authority to select the platform best aligned with MBFD's coastal operating environment, fleet integration strategy, and heavy-rescue interoperability requirements.</p>
            <p>The workgroup remains active through full implementation of the selected equipment. Ongoing responsibilities include procurement tracking, delivery coordination, installation oversight, dashboard monitoring, and leadership of the scheduled follow-on evaluation session for lifting struts.</p>
        </div>

        <!-- Appendices -->
        <div class="report-section page-break" id="sec-appendix-a">
            <h2 class="section-heading"><span class="section-num">A.</span> Appendix A — Product Evaluation Data Summary</h2>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead><tr><th>Product</th><th>Category</th><th>Manufacturer</th><th>Corrected Score</th></tr></thead>
                    <tbody>
                        <tr><td>Holmatro PSP40 (32" Spreader)</td><td>Hydraulic Tool</td><td>Holmatro</td><td class="score-elite">92.02</td></tr>
                        <tr><td>Holmatro PRA40 (Ram)</td><td>Hydraulic Tool</td><td>Holmatro</td><td class="score-elite">91.29</td></tr>
                        <tr><td>DeWalt DCPS612AG2 (12" Saw)</td><td>Rescue Saw</td><td>DeWalt</td><td class="score-elite">91.25</td></tr>
                        <tr><td>Hurst SP 777 E3 (32" Spreader)</td><td>Hydraulic Tool</td><td>Hurst</td><td class="score-elite">90.99</td></tr>
                        <tr><td>Holmatro PCU30CL (Cutter)</td><td>Hydraulic Tool</td><td>Holmatro</td><td class="score-capable">88.86</td></tr>
                        <tr><td>Holmatro V-Strut</td><td>Stabilization</td><td>Holmatro</td><td class="score-capable">87.28</td></tr>
                        <tr><td>Hurst CR 522 E3 (Ram)</td><td>Hydraulic Tool</td><td>Hurst</td><td class="score-capable">86.02</td></tr>
                        <tr><td>Holmatro OmniShore</td><td>Stabilization</td><td>Holmatro</td><td class="score-capable">85.87</td></tr>
                        <tr><td>Hurst S 789 E3 (Cutter)</td><td>Hydraulic Tool</td><td>Hurst</td><td class="score-capable">82.57</td></tr>
                        <tr><td>Holmatro T1</td><td>Specialty</td><td>Holmatro</td><td class="score-capable">82.23</td></tr>
                        <tr><td>Hurst M40 (40" Spreader)</td><td>Specialty</td><td>Hurst</td><td class="score-acceptable">78.80</td></tr>
                        <tr><td>Paratech StrutDriver</td><td>Stabilization</td><td>Paratech</td><td class="score-acceptable">76.13</td></tr>
                        <tr><td>Makita GEC01PL4 (14" Saw)</td><td>Rescue Saw</td><td>Makita</td><td class="score-deficient">64.83</td></tr>
                        <tr><td>Husqvarna K1 Pace (14" Saw)</td><td>Rescue Saw</td><td>Husqvarna</td><td class="score-deficient">61.78</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="report-section" id="sec-appendix-b">
            <h2 class="section-heading"><span class="section-num">B.</span> Appendix B — Data Source Notes</h2>
            <p>All numerical evaluation scores are sourced from the forensically reconstructed Executive Procurement Dashboard. Dashboard scores are designated TIER 1 authority—where any conflict exists between dashboard values and PDF-extracted text, the dashboard values govern.</p>
            <p>Product specifications are sourced from manufacturer catalogs processed through the Docling document intelligence pipeline, cross-referenced against original vendor PDFs.</p>
            <p>The corrected scoring methodology removes the Affordability dimension from all composite calculations. Original uncorrected scores are retained in archival records but are not presented in this report.</p>
        </div>

        <div class="report-section" id="sec-appendix-c">
            <h2 class="section-heading"><span class="section-num">C.</span> Appendix C — Scoring Key</h2>
            <div class="tier-grid">
                <div class="tier-item" style="background: #ecfdf5;">
                    <span class="tier-dot" style="background: #059669;"></span>
                    <span><strong>90+</strong> — Elite — Exceeds all operational requirements with distinction</span>
                </div>
                <div class="tier-item" style="background: #eff6ff;">
                    <span class="tier-dot" style="background: #2563eb;"></span>
                    <span><strong>80–89</strong> — Highly Capable — Meets or exceeds operational requirements</span>
                </div>
                <div class="tier-item" style="background: #fffbeb;">
                    <span class="tier-dot" style="background: #b45309;"></span>
                    <span><strong>70–79</strong> — Acceptable — Meets minimum operational requirements</span>
                </div>
                <div class="tier-item" style="background: #fef2f2;">
                    <span class="tier-dot" style="background: #dc2626;"></span>
                    <span><strong>&lt; 70</strong> — Deficient — Falls below operational requirements</span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="margin-top: 3rem; padding-top: 1.5rem; border-top: 2px solid var(--color-border); text-align: center;">
            <p style="font-family: var(--font-sans); font-size: 0.75rem; color: var(--color-text-muted);">
                MBFD-WG-EVAL-2026-001 · Miami Beach Fire Department · Support Services Division<br>
                Approved for Internal Department Release · March 2026
            </p>
        </div>

    </div><!-- /.report-body -->
</div><!-- /.report-container -->

</body>
</html>