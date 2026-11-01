{{--
    Layout for the Admin -> EMS screens. Kept here (not in the shared theme CSS) so these pages
    look right whatever utility classes the built theme happens to contain. Layout only: sizes,
    grids, wrapping. Colours come from Filament components and its text utilities.
--}}
<style>
    .ems-cols { display: grid; gap: 1rem 1.5rem; grid-template-columns: minmax(0, 1fr); }
    .ems-label { display: block; font-size: .75rem; line-height: 1rem; opacity: .75; }
    .ems-tablewrap { max-width: 100%; }
    .ems-wide { min-width: 56rem; }
    .ems-medium { min-width: 44rem; }
    .ems-narrow { min-width: 36rem; }
    .ems-colhead, .ems-count-head { display: none; }
    .ems-line, .ems-count { display: grid; gap: .5rem .75rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .ems-line > .ems-item, .ems-count > .ems-item, .ems-line > .ems-state, .ems-count > .ems-saved { grid-column: 1 / -1; }
    .ems-state { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
    .ems-mobile-label { display: block; font-size: .75rem; opacity: .75; }
    .ems-bar { height: .75rem; border-radius: .25rem; background: rgb(var(--primary-600)); }

    @media (min-width: 640px) {
        .ems-cols-2, .ems-cols-3, .ems-cols-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ems-colhead { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: .75rem; }
        .ems-line { grid-template-columns: repeat(12, minmax(0, 1fr)); align-items: center; }
        .ems-line > .ems-item { grid-column: span 5; }
        .ems-line > .ems-qty { grid-column: span 2; }
        .ems-line > .ems-qty-small { grid-column: span 1; }
        .ems-line > .ems-state { grid-column: span 2; justify-content: flex-end; }
        .ems-mobile-label { display: none; }
        .ems-head-item { grid-column: span 5; }
        .ems-head-qty { grid-column: span 2; }
        .ems-head-qty-small { grid-column: span 1; }
        .ems-head-state { grid-column: span 2; text-align: right; }
    }
    @media (min-width: 768px) {
        .ems-count { grid-template-columns: repeat(12, minmax(0, 1fr)); align-items: center; }
        .ems-count > .ems-item { grid-column: span 4; }
        .ems-count > .ems-field { grid-column: span 2; }
        .ems-count > .ems-saved { grid-column: span 2; }
        .ems-count-head { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: .75rem; }
        .ems-count-head > .ems-head-item { grid-column: span 4; }
        .ems-count-head > .ems-head-field { grid-column: span 2; }
        .ems-field .ems-label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
    }
    @media (min-width: 1024px) {
        .ems-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .ems-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .ems-split { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
