/*
 * Shared helper for the standalone Workgroup report charts.
 * Chart.js itself is the pinned local copy in public/vendor/chartjs-4.4.0 (no CDN). Report pages run
 * their chart code in an inline <script type="module"> placed after @vite, so it executes after this
 * module has defined the helper.
 *
 * Report chart colours come from the mbfd-theme.css tokens (space-separated RGB channels):
 * hubColor('--hub-chart-1', .5) -> 'rgb(1, 39, 102, 0.5)'.
 */
import './hub-tables.js';
window.hubColor = (token, alpha = 1) => {
    const channels = getComputedStyle(document.documentElement).getPropertyValue(token).trim().split(/\s+/).join(', ');
    return `rgb(${channels}, ${alpha})`;
};
