<x-filament-panels::page>
    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
        {{-- Workgroup Analysis Report Card --}}
        <a href="{{ $this->getAnalysisReportUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           style="
               display: block;
               background-color: #ffffff;
               border: 1px solid rgba(229, 229, 229, 0.6);
               border-left: 4px solid #dc2626;
               border-radius: 0.75rem;
               overflow: hidden;
               padding: 1.5rem 1.75rem;
               text-decoration: none;
               color: inherit;
               transition: all 200ms cubic-bezier(0.25, 0.46, 0.45, 0.94);
               box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
           "
           onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05)'; this.style.borderColor='rgba(212,212,212,1)';"
           onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='0 1px 2px 0 rgba(0,0,0,0.05)'; this.style.borderColor='rgba(229,229,229,0.6)';"
           onfocus="this.style.outline='none'; this.style.boxShadow='0 0 0 2px #dc2626';"
           onblur="this.style.boxShadow='0 1px 2px 0 rgba(0,0,0,0.05)';"
        >
            <div style="display: flex; align-items: flex-start; gap: 1rem;">
                <div style="
                    flex-shrink: 0;
                    width: 2.75rem;
                    height: 2.75rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background-color: #fef2f2;
                    border-radius: 0.625rem;
                    color: #dc2626;
                ">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.25rem; height: 1.25rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.148 2.148A12.061 12.061 0 0 1 16.5 7.605" />
                    </svg>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <h3 style="
                        font-size: 1.0625rem;
                        font-weight: 600;
                        color: #171717;
                        margin: 0 0 0.25rem 0;
                        line-height: 1.4;
                        font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
                    ">Workgroup Analysis Report</h3>
                    <p style="
                        font-size: 0.875rem;
                        color: #737373;
                        margin: 0;
                        line-height: 1.5;
                    ">2026 Technical Product Comparison Analysis — Extrication equipment performance data with interactive Chart.js visualizations and manufacturer rankings.</p>
                </div>
                <div style="flex-shrink: 0; color: #a3a3a3; align-self: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.25rem; height: 1.25rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>

        {{-- Workgroup Data Dashboard Card --}}
        <a href="{{ $this->getDataDashboardUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           style="
               display: block;
               background-color: #ffffff;
               border: 1px solid rgba(229, 229, 229, 0.6);
               border-left: 4px solid #2563eb;
               border-radius: 0.75rem;
               overflow: hidden;
               padding: 1.5rem 1.75rem;
               text-decoration: none;
               color: inherit;
               transition: all 200ms cubic-bezier(0.25, 0.46, 0.45, 0.94);
               box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
           "
           onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05)'; this.style.borderColor='rgba(212,212,212,1)';"
           onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='0 1px 2px 0 rgba(0,0,0,0.05)'; this.style.borderColor='rgba(229,229,229,0.6)';"
           onfocus="this.style.outline='none'; this.style.boxShadow='0 0 0 2px #2563eb';"
           onblur="this.style.boxShadow='0 1px 2px 0 rgba(0,0,0,0.05)';"
        >
            <div style="display: flex; align-items: flex-start; gap: 1rem;">
                <div style="
                    flex-shrink: 0;
                    width: 2.75rem;
                    height: 2.75rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background-color: #eff6ff;
                    border-radius: 0.625rem;
                    color: #2563eb;
                ">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.25rem; height: 1.25rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M12 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M3.375 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M19.125 12h1.5m0 0c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m1.125-2.625c.621 0 1.125.504 1.125 1.125v1.5" />
                    </svg>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <h3 style="
                        font-size: 1.0625rem;
                        font-weight: 600;
                        color: #171717;
                        margin: 0 0 0.25rem 0;
                        line-height: 1.4;
                        font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
                    ">Workgroup Data Dashboard</h3>
                    <p style="
                        font-size: 0.875rem;
                        color: #737373;
                        margin: 0;
                        line-height: 1.5;
                    ">Interactive React-based evaluation dashboard with tabbed navigation across brand rankings, extrication tools, saws, stabilization, and specialty assets.</p>
                </div>
                <div style="flex-shrink: 0; color: #a3a3a3; align-self: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.25rem; height: 1.25rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>

        {{-- Mid-Mount L1 Proposed Inventory Card --}}
        <a href="{{ $this->getL1InventoryUrl() }}"
           target="_blank"
           rel="noopener noreferrer"
           style="
               display: block;
               background-color: #ffffff;
               border: 1px solid rgba(229, 229, 229, 0.6);
               border-left: 4px solid #059669;
               border-radius: 0.75rem;
               overflow: hidden;
               padding: 1.5rem 1.75rem;
               text-decoration: none;
               color: inherit;
               transition: all 200ms cubic-bezier(0.25, 0.46, 0.45, 0.94);
               box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
           "
           onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05)'; this.style.borderColor='rgba(212,212,212,1)';"
           onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='0 1px 2px 0 rgba(0,0,0,0.05)'; this.style.borderColor='rgba(229,229,229,0.6)';"
           onfocus="this.style.outline='none'; this.style.boxShadow='0 0 0 2px #059669';"
           onblur="this.style.boxShadow='0 1px 2px 0 rgba(0,0,0,0.05)';"
        >
            <div style="display: flex; align-items: flex-start; gap: 1rem;">
                <div style="
                    flex-shrink: 0;
                    width: 2.75rem;
                    height: 2.75rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background-color: #ecfdf5;
                    border-radius: 0.625rem;
                    color: #059669;
                ">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.25rem; height: 1.25rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0H21M3.375 14.25h-.375a3 3 0 0 1-3-3V7.5a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3v.75M3.375 14.25H9.75m11.25-3h.375A2.625 2.625 0 0 1 24 13.875v.375m-24-6h12" />
                    </svg>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <h3 style="
                        font-size: 1.0625rem;
                        font-weight: 600;
                        color: #171717;
                        margin: 0 0 0.25rem 0;
                        line-height: 1.4;
                        font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
                    ">Mid-Mount L1 Proposed Inventory</h3>
                    <p style="
                        font-size: 0.875rem;
                        color: #737373;
                        margin: 0;
                        line-height: 1.5;
                    ">Ladder 1 apparatus modernization dashboard with full compartment inventory, KPI widgets, compliance flags, and native Excel export with embedded pricing formulas.</p>
                </div>
                <div style="flex-shrink: 0; color: #a3a3a3; align-self: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.25rem; height: 1.25rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </div>
            </div>
        </a>
    </div>

    @push('styles')
    <style>
        @media (min-width: 768px) {
            .fi-page-content > div > div:first-child {
                grid-template-columns: 1fr 1fr !important;
            }
        }
    </style>
    @endpush
</x-filament-panels::page>