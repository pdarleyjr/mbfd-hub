<x-filament-panels::page class="wl-links" data-hub-ui="2" data-hub-portal="workgroups">
    <div class="wg-report-library">
        {{-- Workgroup Analysis Report Card --}}
        <a href="{{ $this->getAnalysisReportUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           class="workgroup-report-link"
        >
            <div class="wl-row">
                <div class="wl-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.148 2.148A12.061 12.061 0 0 1 16.5 7.605" />
                    </svg>
                </div>
                <div class="wl-body">
                    <h3 class="wl-title">Workgroup Analysis Report</h3>
                    <p class="wl-desc">2026 Technical Product Comparison Analysis — Extrication equipment performance data with interactive Chart.js visualizations and manufacturer rankings.</p>
                </div>
                <div class="wl-ext">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>

        {{-- Workgroup Data Dashboard Card --}}
        <a href="{{ $this->getDataDashboardUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           class="workgroup-report-link"
        >
            <div class="wl-row">
                <div class="wl-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M12 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M3.375 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M19.125 12h1.5m0 0c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m1.125-2.625c.621 0 1.125.504 1.125 1.125v1.5" />
                    </svg>
                </div>
                <div class="wl-body">
                    <h3 class="wl-title">Workgroup Data Dashboard</h3>
                    <p class="wl-desc">Interactive React-based evaluation dashboard with tabbed navigation across brand rankings, extrication tools, saws, stabilization, and specialty assets.</p>
                </div>
                <div class="wl-ext">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>

        {{-- Mid-Mount L1 Proposed Inventory Card --}}
        <a href="{{ $this->getL1InventoryUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           class="workgroup-report-link"
        >
            <div class="wl-row">
                <div class="wl-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0H21M3.375 14.25h-.375a3 3 0 0 1-3-3V7.5a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3v.75M3.375 14.25H9.75m11.25-3h.375A2.625 2.625 0 0 1 24 13.875v.375m-24-6h12" />
                    </svg>
                </div>
                <div class="wl-body">
                    <h3 class="wl-title">Mid-Mount L1 Proposed Inventory</h3>
                    <p class="wl-desc">Ladder 1 apparatus modernization dashboard with full compartment inventory, KPI widgets, compliance flags, and native Excel export with embedded pricing formulas.</p>
                </div>
                <div class="wl-ext">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>

        {{-- Workgroup Final Session Presentation Card --}}
        <a href="{{ $this->getFinalPresentationUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           class="workgroup-report-link"
        >
            <div class="wl-row">
                <div class="wl-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                    </svg>
                </div>
                <div class="wl-body">
                    <h3 class="wl-title">Workgroup Final Session Presentation</h3>
                    <p class="wl-desc">Reveal.js slide deck for the final review meeting — interactive charts, equipment comparisons, deliberation agenda, and consensus-building framework. Use arrow keys or click to navigate.</p>
                </div>
                <div class="wl-ext">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>

        {{-- MBFD Workgroup Evaluation Results Card --}}
        <a href="{{ $this->getEvaluationReportUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           class="workgroup-report-link"
        >
            <div class="wl-row">
                <div class="wl-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </div>
                <div class="wl-body">
                    <div class="wl-titlerow">
                        <h3 class="wl-title">MBFD Workgroup Evaluation Results</h3>
                        <span class="wl-tag">PDF Ready</span>
                    </div>
                    <p class="wl-desc">Professional technical evaluation report — 14 products, 6 categories, Charts, data tables, product images, and one-click PDF/print export. Formatted for administrators.</p>
                </div>
                <div class="wl-ext">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>

        {{-- MBFD Workgroup Summary Card --}}
        <a href="{{ $this->getWorkgroupSummaryUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           class="workgroup-report-link"
        >
            <div class="wl-row">
                <div class="wl-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </div>
                <div class="wl-body">
                    <div class="wl-titlerow">
                        <h3 class="wl-title">MBFD Workgroup Summary</h3>
                        <span class="wl-tag">PDF Ready</span>
                    </div>
                    <p class="wl-desc">Complete equipment evaluation, procurement analysis, and final selection report — 14 products, 6 manufacturers, 4 operational categories with product images, data tables, and one-click PDF/print export. Formatted for command staff distribution.</p>
                </div>
                <div class="wl-ext">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="wl-glyph">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>
    </div>
</x-filament-panels::page>