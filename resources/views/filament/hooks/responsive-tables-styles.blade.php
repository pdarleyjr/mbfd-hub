{{--
    Compact cells for the Master Personnel Directory and Master Vehicle Inventory.
    Loaded only on those pages by ResponsiveTablesServiceProvider.
    These tables never scroll sideways: resources/js/hub-tables.js moves lower-priority columns into a
    details panel (and stacks rows as cards on phones) using the shared rules in resources/css/hub-tables.css.
    This file only tightens spacing for the dense directory pages. No colours, no sticky columns.
--}}
<style>
    /* Dense pages keep the phone gutter (16px) on desktop too. */
    .fi-main { padding-inline: 1rem; }

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
    .fi-ta-table .fi-ta-selection-cell { min-width: 3rem; width: 3rem; }
    .fi-ta-table td.fi-table-cell-city-email .fi-ta-text-item-label { overflow-wrap: anywhere; }
</style>
