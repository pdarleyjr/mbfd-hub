{{-- Layout and chart colours for Medical Supply Data. Filament palette variables only, no colour literals. --}}
<style>
    .supply-stats { display: grid; gap: .75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; }
    .supply-stat { padding: .75rem; border-radius: .5rem; border: 1px solid rgb(var(--gray-200)); min-width: 0; }
    .dark .supply-stat { border-color: rgb(var(--gray-700)); }
    .supply-stat dd { margin: 0; }
    .supply-stat-value { font-size: 1.5rem; line-height: 2rem; font-weight: 600; font-variant-numeric: tabular-nums; }
    .supply-stat-hot { border-color: rgb(var(--danger-500)); }
    .supply-stat-warm { border-color: rgb(var(--warning-500)); }
    .supply-filters { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); }
    .supply-chart { display: block; width: 100%; max-width: 100%; height: auto; }
    .supply-chart text { fill: currentColor; font-size: 13px; }
    .supply-bar-events { fill: rgb(var(--primary-600)); }
    .dark .supply-bar-events { fill: rgb(var(--primary-400)); }
    .supply-bar-requests { fill: rgb(var(--gray-400)); }
    .dark .supply-bar-requests { fill: rgb(var(--gray-500)); }
    .supply-axis { stroke: rgb(var(--gray-300)); stroke-width: 1; }
    .dark .supply-axis { stroke: rgb(var(--gray-600)); }
    .supply-key { display: inline-block; width: .75rem; height: .75rem; border-radius: .125rem; margin-right: .375rem; vertical-align: -1px; }
    .supply-key-events { background: rgb(var(--primary-600)); }
    .dark .supply-key-events { background: rgb(var(--primary-400)); }
    .supply-key-requests { background: rgb(var(--gray-400)); }
    .supply-num { text-align: right; font-variant-numeric: tabular-nums; }
    .supply-defs dt { font-weight: 600; }
    .supply-defs dd { margin: 0 0 .75rem 0; }
    .supply-detail-grid { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 640px) {
        .supply-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .supply-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (min-width: 1024px) {
        .supply-stats-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .supply-filters { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
</style>
