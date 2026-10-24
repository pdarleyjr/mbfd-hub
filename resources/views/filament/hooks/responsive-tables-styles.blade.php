{{--
    Compact table layout for the Master Personnel Directory and Master Vehicle Inventory.
    Loaded only on those pages by App\Providers\ResponsiveTablesServiceProvider.
    - Tighter cell padding, wrapping column headings and narrower inline inputs.
    - Sticky selection checkbox, identity column and actions column while the table scrolls sideways.
    No negative margins, no motion, light and dark surfaces through variables.
--}}
<style>
    .fi-ta-content {
        --mbfd-rt-surface: #ffffff;
        --mbfd-rt-stripe: #f3f5f8;
        --mbfd-rt-head: #f3f5f8;
        --mbfd-rt-edge: rgb(15 23 42 / 0.12);
    }
    .dark .fi-ta-content {
        --mbfd-rt-surface: #111827;
        --mbfd-rt-stripe: #1a2232;
        --mbfd-rt-head: #1a2232;
        --mbfd-rt-edge: rgb(255 255 255 / 0.14);
    }

    /* These pages are wide tables: keep the page gutter at the phone size (16px) on desktop too. */
    .fi-main { padding-inline: 1rem; }

    /* Compact cells. */
    .fi-ta-table th.fi-ta-header-cell { padding-inline: 0.5rem; padding-block: 0.625rem; }
    .fi-ta-table th.fi-ta-header-cell [role="button"],
    .fi-ta-table th.fi-ta-header-cell > span { white-space: normal; }
    .fi-ta-table .fi-ta-text,
    .fi-ta-table .fi-ta-text-input,
    .fi-ta-table .fi-ta-select,
    .fi-ta-table .fi-ta-icon,
    .fi-ta-table .fi-ta-checkbox,
    .fi-ta-table .fi-ta-toggle { padding-inline: 0.5rem; padding-block: 0.5rem; }
    .fi-ta-table .fi-ta-actions-cell > div { padding-inline: 0.5rem; padding-block: 0.5rem; }
    .fi-ta-table .fi-ta-text-input { min-width: 6.5rem; }
    .fi-ta-table td.fi-table-cell-name .fi-ta-text-input { min-width: 10rem; }
    .fi-ta-table .fi-ta-select { min-width: 7rem; }
    .fi-ta-table .fi-ta-text-item-label,
    .fi-ta-table .fi-ta-text p { overflow-wrap: break-word; }
    .fi-ta-table .fi-ta-selection-cell { min-width: 3.25rem; width: 3.25rem; }
    /* Long identifiers may break only when the table is short of room, never below 10rem. */
    .fi-ta-table td.fi-table-cell-city-email .fi-ta-text { min-width: 10rem; }
    .fi-ta-table td.fi-table-cell-city-email .fi-ta-text-item-label { overflow-wrap: anywhere; }

    /* The rank under Employee ID is for phones, where the Rank column is hidden. Desktop shows the Rank column. */
    @media (min-width: 768px) {
        .fi-ta-table td.fi-table-cell-employee-id .fi-ta-text > p { display: none; }
    }

    /* Sticky columns apply once the table can scroll sideways beside the page sidebar. */
    @media (min-width: 768px) {
        .fi-ta-table th.fi-ta-selection-cell,
        .fi-ta-table td.fi-ta-selection-cell,
        .fi-ta-table th.fi-ta-actions-header-cell,
        .fi-ta-table th:last-child,
        .fi-ta-table td.fi-ta-actions-cell,
        .fi-ta-table:has(th.fi-ta-selection-cell) th:nth-child(2),
        .fi-ta-table:has(th.fi-ta-selection-cell) td:nth-child(2),
        .fi-ta-table:not(:has(th.fi-ta-selection-cell)) th:first-child,
        .fi-ta-table:not(:has(th.fi-ta-selection-cell)) td:first-child {
            position: sticky;
            z-index: 1;
            background-color: var(--mbfd-rt-surface);
        }
        .fi-ta-table thead th.fi-ta-selection-cell,
        .fi-ta-table thead th:last-child,
        .fi-ta-table:has(th.fi-ta-selection-cell) thead th:nth-child(2),
        .fi-ta-table:not(:has(th.fi-ta-selection-cell)) thead th:first-child {
            background-color: var(--mbfd-rt-head);
            z-index: 2;
        }
        .fi-ta-table tbody tr.bg-gray-50 > td.fi-ta-selection-cell,
        .fi-ta-table tbody tr.bg-gray-50 > td:last-child,
        .fi-ta-table:has(th.fi-ta-selection-cell) tbody tr.bg-gray-50 > td:nth-child(2),
        .fi-ta-table:not(:has(th.fi-ta-selection-cell)) tbody tr.bg-gray-50 > td:first-child {
            background-color: var(--mbfd-rt-stripe);
        }
        .fi-ta-table th.fi-ta-selection-cell,
        .fi-ta-table td.fi-ta-selection-cell { left: 0; }
        .fi-ta-table:has(th.fi-ta-selection-cell) th:nth-child(2),
        .fi-ta-table:has(th.fi-ta-selection-cell) td:nth-child(2) { left: 3.25rem; }
        .fi-ta-table:not(:has(th.fi-ta-selection-cell)) th:first-child,
        .fi-ta-table:not(:has(th.fi-ta-selection-cell)) td:first-child { left: 0; }
        .fi-ta-table:has(th.fi-ta-selection-cell) th:nth-child(2),
        .fi-ta-table:has(th.fi-ta-selection-cell) td:nth-child(2),
        .fi-ta-table:not(:has(th.fi-ta-selection-cell)) th:first-child,
        .fi-ta-table:not(:has(th.fi-ta-selection-cell)) td:first-child { box-shadow: 1px 0 0 var(--mbfd-rt-edge); }
        .fi-ta-table th:last-child,
        .fi-ta-table td.fi-ta-actions-cell { right: 0; box-shadow: -1px 0 0 var(--mbfd-rt-edge); }
    }

    @media (prefers-reduced-motion: reduce) {
        .fi-ta-content { scroll-behavior: auto; }
    }
</style>
