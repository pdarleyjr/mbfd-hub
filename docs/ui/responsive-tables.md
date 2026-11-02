# Responsive tables

Operational tables in the Hub never scroll sideways. This page is the rule, how it is built, how to add a
table, how it is tested, and an evidence-based inventory of every table surface with its status.

## The rule

- The document never scrolls horizontally, and no table sits in a swipeable wrapper.
- A table adapts to the width it is given. Nothing disappears: a column that leaves the row is shown in that
  row's details panel, and every sort, filter, search, bulk action, in-row action, export and permission
  keeps working.
- Wide: the table is unchanged. Tablet and laptop with the sidebar open: the lowest priority columns move into
  the row's details panel. Phone: each row is a card with a short summary and the same details panel.
- Long URLs, emails and IDs wrap (`overflow-wrap: anywhere`) instead of widening the table.
- Controls the module adds (details button, sort menu) are at least 44px (2.75rem) on touch devices. Row checkboxes keep
  their 24px box, but on a coarse pointer the 44px square around the box selects the row too.
- Print, PDF and Excel keep their own page layout and are out of scope.

## How it works

| Part | File | Role |
|---|---|---|
| Planner | `resources/js/hub-tables/plan.js` | Pure rules: given the width, the column priorities and minimum widths, returns `table`, `priority` or `cards`, and which columns are visible or moved. Unit tested in Node. |
| Adapter | `resources/js/hub-tables.js` | Measures each table's box (a `ResizeObserver` follows the sidebar and window), applies the plan with attributes and a CSS grid template, injects the details button, and re-applies after Livewire updates. Hidden cells stay in their row, so inline inputs, `wire:` bindings and actions keep working. Re-rendering is cheap: the plan is kept per table and reused while the box width, screen width and column set are unchanged (`canReusePlan`), so a poll, filter-as-you-type or morph only rewrites the rows that lost their attributes (`data-hub-rt-row` marks the settled ones); a plan that had to be squeezed is planned again. A morph of a component with no table is ignored, and each run numbers the tables once instead of once per row. `window.__hubTablesStats` counts re-plans, restores and rows written so a test can bound the work. |
| Styles | `resources/css/hub-tables.css` | The grid, details panel, cards, sort menu and wrapping rules. Tokens only, no colour literals. Imported by `resources/css/filament/admin/theme.css`, which every Filament panel uses. |
| Loader | `App\Providers\ResponsiveTablesServiceProvider` | Registers the script on every Filament panel (`BODY_END`). The Master Personnel Directory and Master Vehicle Inventory also get tighter cell spacing from `responsive-tables-styles.blade.php`; the old sticky sideways-scroll columns are retired. |

Plans: the table is left alone when every column fits at its readable minimum (7rem for text, 9rem for
inputs, or `data-hub-min`, in rem). Otherwise the least important non-essential columns move until the rest
fit (`priority`). Under 560px of box width, or when the essential column cannot fit, rows become `cards` with
a four-field summary. A column Filament itself hides with `visibleFrom()` is moved into the details panel
instead of vanishing. Sorting for moved columns is one **Sort by another field** menu above the table;
on cards it is one **Sort by** menu. The sort menu clicks the real header button, so Filament's own sort state
and URL behaviour are unchanged.

## Adding a table

- **Filament table or relation manager:** nothing to do. Optionally rank columns with
  `->extraHeaderAttributes(['data-hub-priority' => 3])` (lower stays in the row longest; the first data column
  is always kept) and set a readable width with `'data-hub-min' => '12'` for columns such as emails. Prefer a
  description under a main column (`->description()`) for companion values.
- **Blade or React table:** add `data-hub-table` to the `<table>`, with a real `<thead>` row of `<th>` labels, and
  do not wrap it in `overflow-x-auto`. Do not use `whitespace-nowrap` or fixed `min-width` on columns.
- **Filament layouts** (`Split`, `Stack`, `contentGrid`) already stack on their own; `hub-tables.css` lets their
  children shrink and break long strings. Filament shows its own sort selector for layouts (checked in
  `vendor/filament/tables` 3.3.54: column-header sorting is replaced by a Sort by select when a layout is used).
- Why not `Panel::make()->collapsible()` per table: the dense tables have user-chosen, toggleable and inline-editable
  columns, and a layout column replaces the column headers and the table alignment on desktop. The adapter keeps
  the real table (with its column chooser and header sorting) wherever it fits.

## Tests

- `node --test tests/Node/hub-tables-plan.test.mjs` plans and priorities.
- `node --test tests/Node/hub-no-sideways-scroll-contract.test.mjs` fails on any new `overflow-x: auto`, `overflow-x-auto`
  or `overflow-x-scroll` in `resources/` or `app/`. `tests/Node/fixtures/sideways-scroll-allowlist.json` is a ratchet: it
  lists the few non-table strips and unmigrated surfaces with counts and reasons, and a fixed surface must be removed from it.
- `npx playwright test --config=playwright.responsive-tables.config.ts` (or `npm run test:responsive-tables-e2e`) starts a
  disposable synthetic-data server (`tests/e2e/support/responsive-tables-server.mjs`: 60 members, 40 vehicles, 12 generated
  rows for each resource table with real enum values, live rather than archived, and long unbroken strings) and, for every
  route in `tests/e2e/support/responsive-tables-routes.json` (19 routes: Admin, Workgroups, Training and Employee panels),
  asserts at 320, 390, 768, 1024, 1280 and 1440px that `document.documentElement.scrollWidth <= clientWidth` (1px
  tolerance), that no visible element is a sideways scroller (the shell bar and its menus are measured like everything
  else; only the content of a closed menu is skipped), that every table lies in the viewport and that its content fits,
  with the sidebar open and closed, filters open, a row's details open and a dialog open, and that added controls are 44px
  on touch widths. Routes flagged `menus` also open each shell menu (module switcher, account, More sheet) and measure
  the menu and the document. Further tests: a re-render at the same width does not re-plan or rewrite settled rows and
  keeps open details and focus; the row checkbox keeps its box and has a 44px target.
- `tests/e2e/captain5-ems.spec.ts` (Daily app) checks the unit statistics tables at 320, 390, 768 and 1280px;
  `tests/e2e/operational-forms.spec.ts` checks the operational forms record and entry tables at eight widths from 320 to 1440px.
- CI: the reusable release gate `.github/workflows/hub-release-gates.yml` runs the responsive tables and Home duty
  browser configs in the `ui-browser-suites` job. It is path-aware (`scripts/ci/ui-browser-scope.mjs`): a candidate
  whose diff is only documentation, the vacation app, Cloudflare workers or operations notes skips them, and any doubt runs them.
- `tests/Feature/Personnel/ResponsiveTablesTest.php` keeps every directory and vehicle column, sort, search and filter.

## Inventory

Status vocabulary: **fixed** = measured in a browser at the listed widths with no document overflow and no sideways
scroller; **adapted, not yet verified** = covered by the shared module or migrated to `data-hub-table`, but not
measured in a browser; **exception** = deliberately left, with the reason. Measurements use the synthetic fixture
above, so a page that needs data the fixture does not create shows as having no table.

Verification record. Disposable synthetic-data server, Chromium, PHP 8.4 on Windows, final code of this release:
`playwright.responsive-tables.config.ts` ran 138 tests (19 routes at six widths plus the personnel, same-width morph and
checkbox tests): 137 passed in the full run, and the one failure (`/admin/hub-support-tickets` at 390px, the page did not
render within 30s on a cold server) passed in an isolated re-run (2 of 2). `tests/e2e/shell-alignment.spec.ts`: 128 passed,
40 skipped by design, 0 failed. A crawl of the four routes that had no rows measured each at six widths with 12 rows and no
overflow. The inventory rows below that are not in the contract routes were measured by the earlier crawl of 51 routes
(306 page measurements, before the re-render change) and are not re-measured here.

### Filament list pages measured in a browser

| Route | Panel | Mode by width (px) | Rows | Status |
|---|---|---|---|---|
| `/admin/apparatus-changeouts` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/apparatus-inspection-exceptions` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/apparatus-operational-alerts` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/apparatuses` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/apparatus-service-tickets` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 12 | fixed, measured at 320/390/768/1024/1280/1440; needs the fixture to give every unit a designation, see exception 6 |
| `/admin/capital-projects` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/department-updates` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/hub-support-tickets` | admin | 320:cards 390:cards 768:table 1024:table 1280:table 1440:table | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/defects` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 6 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/employee-equipment-requests` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 6 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/employees` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 50 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/equipment-items` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/inventory-items` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/inventory-locations` | admin | 320:cards 390:cards 768:table 1024:table 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/master-personnel-directory` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 50 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/medical-statistics` | admin | 320:cards 390:cards 768:table 1024:table 1280:table 1440:table | 6 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/my-profile` | admin | 320:cards 390:cards 768:table 1024:table 1280:table 1440:table | 7 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/personnel-uniforms-equipment/assignments` | admin | 320:cards 390:cards 768:cards 1024:cards 1280:priority 1440:priority | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/personnel-uniforms-equipment/employee-records` | admin | 320:cards 390:cards 768:cards 1024:cards 1280:priority 1440:priority | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/personnel-uniforms-equipment/uniform-inventory` | admin | 320:cards 390:cards 768:cards 1024:cards 1280:priority 1440:priority | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/resource-documents` | admin | 320:cards 390:cards 768:- 1024:priority 1280:priority 1440:table | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/room-assets` | admin | 320:cards 390:cards 768:- 1024:priority 1280:priority 1440:priority | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/roster-imports` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/shield/roles` | admin | 320:cards 390:cards 768:table 1024:table 1280:table 1440:table | 1 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/shop-works` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/single-gas-meters` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/stations` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/take-home-vehicle-inspections` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 12 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/training-courses` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/training-rosters` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 15 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/uniforms` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/unit-master-vehicles` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:- | 40 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/users` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 50 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/workgroup/candidate-products` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/workgroup/evaluation-categories` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/workgroup/evaluation-criteria` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/workgroup/evaluation-templates` | admin | 320:cards 390:cards 768:table 1024:table 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/workgroup/surveys` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/workgroup/workgroup-files` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/workgroup/workgroup-sessions` | admin | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/admin/workgroup/workgroups` | admin | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/employee/forms` | employee | 320:- 390:- 768:- 1024:- 1280:- 1440:- | 1 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/employee/training-history` | employee | 320:cards 390:cards 768:table 1024:table 1280:table 1440:table | 1 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/training/training-courses` | training | 320:cards 390:cards 768:priority 1024:priority 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/training/training-rosters` | training | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:priority | 15 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/analysis-report` | workgroups | 320:cards 390:cards 768:table 1024:table+cards 1280:table+cards 1440:table+cards | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/data-dashboard` | workgroups | 320:cards 390:cards 768:cards 1024:priority 1280:priority 1440:priority | 4 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/evaluation-report` | workgroups | 320:table+cards 390:table+cards 768:table+priority 1024:table 1280:table 1440:table | 77 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/final-recommendations` | workgroups | 320:cards+table 390:cards+table 768:table 1024:table 1280:table 1440:table | 80 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/l1-inventory` | workgroups | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 126 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/workgroup-summary` | workgroups | 320:cards 390:cards 768:table+priority 1024:table+priority 1280:table+priority 1440:table+priority | 62 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/workgroup/candidate-products` | workgroups | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/workgroup/evaluation-categories` | workgroups | 320:cards 390:cards 768:priority 1024:priority 1280:priority 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |
| `/workgroups/workgroup/surveys` | workgroups | 320:cards 390:cards 768:priority 1024:table 1280:table 1440:table | 10 | fixed, measured at 320/390/768/1024/1280/1440 |


### Filament pages measured with no table on screen

| Route | Panel | Result |
|---|---|---|
| `/admin` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/bid-access-pin` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/captain5-operations` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/command-board-pin` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/command-communications` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/communications-usage` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/compose-email` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/crew-planner` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/custom-personnel-tables` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/dashboards/item` | admin | see notes: w390: HTTP 404 |
| `/admin/dashboards/station-operations` | admin | see notes: w390: div.mbfd-dash-chips +748 |
| `/admin/dashboards/vehicle-checkout` | admin | see notes: w390: div.mbfd-dash-chips +944 |
| `/admin/email` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/employee-messages` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/employees/accounts` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/equipment-intake` | admin | see notes: w390: div.ei-tabs +103 |
| `/admin/fire-equipment-requests` | admin | see notes: w390: HTTP 403 |
| `/admin/health-check-results` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/inbound-emails` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/inspections` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/inventory-corrections` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/knowledge-base` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/login` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/logistics-board` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/media-control/media` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/medical-supply-room` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/notification-settings` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/operational-forms` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/outbound-emails` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/personnel-reconciliation` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/personnel-uniforms-equipment` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/personnel-uniforms-equipment/overview` | admin | see notes: w390: HTTP 500 |
| `/admin/personnel-uniforms-equipment/personnel-requests` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/promotional-lists` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/pulse` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/push-delivery-health` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/recommendations` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/rescue-inventory` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/service-worker.js` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/set-password` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/settings` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/station-inspections` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/station-requests` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/todos` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/training-management` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/trt-trailer-inventory` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/under-25k` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/unit-master-vehicles/import-review` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/video-conferencing/health` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/workgroup-administration` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/workgroup/evaluation-submissions` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/admin/workgroup/workgroup-members` | admin | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/apparatus-service-request` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/dashboard` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/login` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/messages` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/my-equipment-page` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/my-requests` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/my-vehicle-inspections` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/notification-settings` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/personnel-equipment-request` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/personnel-roster/search` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/request-equipment` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/resources` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/set-password` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/taskbooks` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/video-conferencing` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/employee/video-conferencing/command` | employee | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/training` | training | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/training/crew-planner` | training | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/training/login` | training | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/training/notification-settings` | training | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/training/set-password` | training | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/training/settings` | training | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/training/training-todos` | training | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroup/saver-report` | workgroup | see notes: w390: HTTP 404 |
| `/workgroups` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/evaluation-form-page` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/evaluations` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/files` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/final-presentation` | workgroups | no table; a contract route: the page, the bar and each open shell menu fit at 320/390/768/1024/1280/1440 (the 11px overflow at 390 is fixed) |
| `/workgroups/links` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/login` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/notes` | workgroups | see notes: w390: HTTP 404 |
| `/workgroups/notification-settings` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/profile` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/session-results` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/set-password` | workgroups | no table rendered with the synthetic data (form, dashboard, empty state or custom page); page itself does not scroll sideways |
| `/workgroups/shared-uploads` | workgroups | see notes: w390: HTTP 404 |
| `/workgroups/survey-form-page` | workgroups | see notes: w390: HTTP 404 |
| `/workgroups/survey-results-page` | workgroups | see notes: w390: HTTP 404 |
| `/workgroups/surveys` | workgroups | see notes: w390: HTTP 403 |


### Other Filament table definitions (relation managers, widgets, nested pages)

These are covered automatically by the shared module and have not been measured in a browser, because they need a
parent record or a particular state. Status: **adapted, not yet verified.**

112 table definitions in code.

**Admin / Other** (2): `Concerns/EnterpriseTable.php`, `Concerns/HasExportActions.php`

**Admin / Page** (6): `Admin/Pages/Concerns/SupplyRoomStockTable.php`, `Pages/CustomPersonnelTables.php`, `Pages/MasterPersonnelDirectory.php`, `Pages/PersonnelTableEditor.php`, `Pages/PushDeliveryHealth.php`, `Resources/UnitMasterVehicleResource/Pages/ReviewVehicleDocuments.php`

**Admin / Relation manager** (25): `Resources/ApparatusResource/RelationManagers/ChangeoutsRelationManager.php`, `Resources/ApparatusResource/RelationManagers/DefectsRelationManager.php`, `Resources/ApparatusResource/RelationManagers/IdentificationQuestionsRelationManager.php`, `Resources/ApparatusResource/RelationManagers/InspectionsRelationManager.php`, `Resources/ApparatusResource/RelationManagers/InventoryCorrectionsRelationManager.php`, `Resources/ApparatusResource/RelationManagers/OperationalAlertsRelationManager.php`, `Resources/ApparatusResource/RelationManagers/ServiceTicketsRelationManager.php`, `Resources/CapitalProjectResource/RelationManagers/MilestonesRelationManager.php`, `Resources/CapitalProjectResource/RelationManagers/UpdatesRelationManager.php`, `Resources/PromotionalListResource/RelationManagers/EntriesRelationManager.php`, `Resources/StationResource/RelationManagers/ApparatusServiceTicketsRelationManager.php`, `Resources/StationResource/RelationManagers/ApparatusesRelationManager.php`, `Resources/StationResource/RelationManagers/CapitalProjectsRelationManager.php`, `Resources/StationResource/RelationManagers/DailyCheckoutsRelationManager.php`, `Resources/StationResource/RelationManagers/EquipmentIssuesRelationManager.php`, `Resources/StationResource/RelationManagers/InventorySubmissionsRelationManager.php`, `Resources/StationResource/RelationManagers/RoomsRelationManager.php`, `Resources/StationResource/RelationManagers/SingleGasMetersRelationManager.php`, `Resources/StationResource/RelationManagers/StationInspectionsRelationManager.php`, `Resources/StationResource/RelationManagers/StationInventoryItemsRelationManager.php`, `Resources/StationResource/RelationManagers/StationRequestsRelationManager.php`, `Resources/StationResource/RelationManagers/StationSupplyRequestsRelationManager.php`, `Resources/StationResource/RelationManagers/Under25kProjectsRelationManager.php`, `Resources/Under25kProjectResource/RelationManagers/UpdatesRelationManager.php`, `Resources/UnitMasterVehicleResource/RelationManagers/DocumentsRelationManager.php`

**Admin / Resource list** (41): `Clusters/PersonnelUniformsEquipment/Resources/PersonnelAssignmentResource.php`, `Clusters/PersonnelUniformsEquipment/Resources/PersonnelEmployeeResource.php`, `Clusters/PersonnelUniformsEquipment/Resources/PersonnelRequestResource.php`, `Resources/AccountProfileResource.php`, `Resources/ApparatusChangeoutResource.php`, `Resources/ApparatusInspectionExceptionResource.php`, `Resources/ApparatusOperationalAlertResource.php`, `Resources/ApparatusResource.php`, `Resources/ApparatusServiceTicketResource.php`, `Resources/CapitalProjectResource.php`, `Resources/DefectResource.php`, `Resources/DepartmentUpdateResource.php`, `Resources/EmployeeEquipmentRequestResource.php`, `Resources/EmployeeResource.php`, `Resources/EquipmentItemResource.php`, `Resources/FireEquipmentRequestResource.php`, `Resources/HubSupportTicketResource.php`, `Resources/InboundEmailResource.php`, `Resources/InspectionResource.php`, `Resources/InventoryCorrectionResource.php`, `Resources/InventoryItemResource.php`, `Resources/InventoryLocationResource.php`, `Resources/InventoryRequestResource.php`, `Resources/OperationalFormRecordResource.php`, `Resources/OutboundEmailResource.php`, `Resources/PromotionalListResource.php`, `Resources/RecommendationResource.php`, `Resources/ResourceDocumentResource.php`, `Resources/RoleResource.php`, `Resources/RoomAssetResource.php`, `Resources/RosterImportResource.php`, `Resources/ShopWorkResource.php`, `Resources/SingleGasMeterResource.php`, `Resources/StationInspectionResource.php`, `Resources/StationRequestResource.php`, `Resources/StationResource.php`, `Resources/TakeHomeVehicleInspectionResource.php`, `Resources/TodoResource.php`, `Resources/Under25kProjectResource.php`, `Resources/UniformResource.php`, `Resources/UnitMasterVehicleResource.php`

**Admin / Widget** (5): `Widgets/PendingRecommendationsWidget.php`, `Widgets/RecentAllocationsWidget.php`, `Widgets/RecentProjectUpdatesWidget.php`, `Widgets/TodoOverviewWidget.php`, `Widgets/UpcomingMilestonesWidget.php`

**Employee / Page** (2): `Employee/Pages/MyVehicleInspections.php`, `Employee/Pages/TrainingHistoryPage.php`

**Training / Page** (3): `Training/Resources/TrainingCourseResource/Pages/ManageTrainingModules.php`, `Training/Resources/TrainingCourseResource/Pages/ReviewTrainingPaper.php`, `Training/Resources/TrainingSessionResource/Pages/ViewTrainingSession.php`

**Training / Resource list** (3): `Training/Resources/TrainingCourseResource.php`, `Training/Resources/TrainingSessionResource.php`, `Training/Resources/TrainingTodoResource.php`

**Training / Widget** (1): `Training/Widgets/TrainingTodoWidget.php`

**Workgroups / Other** (10): `Resources/Workgroup/CandidateProductResource.php`, `Resources/Workgroup/EvaluationCategoryResource.php`, `Resources/Workgroup/EvaluationCriterionResource.php`, `Resources/Workgroup/EvaluationSubmissionResource.php`, `Resources/Workgroup/EvaluationTemplateResource.php`, `Resources/Workgroup/SurveyResource.php`, `Resources/Workgroup/WorkgroupFileResource.php`, `Resources/Workgroup/WorkgroupMemberResource.php`, `Resources/Workgroup/WorkgroupResource.php`, `Resources/Workgroup/WorkgroupSessionResource.php`

**Workgroups / Page** (5): `Workgroup/Pages/Evaluations.php`, `Workgroup/Pages/Files.php`, `Workgroup/Pages/Notes.php`, `Workgroup/Pages/SharedUploads.php`, `Workgroup/Pages/Surveys.php`

**Workgroups / Relation manager** (6): `Resources/Workgroup/RelationManagers/AttendanceRelationManager.php`, `Resources/Workgroup/RelationManagers/CriteriaRelationManager.php`, `Resources/Workgroup/RelationManagers/FilesRelationManager.php`, `Resources/Workgroup/RelationManagers/MembersRelationManager.php`, `Resources/Workgroup/RelationManagers/SessionsRelationManager.php`, `Resources/Workgroup/RelationManagers/SharedUploadsRelationManager.php`

**Workgroups / Widget** (3): `Workgroup/Widgets/CategoryRankingsWidget.php`, `Workgroup/Widgets/FinalistsWidget.php`, `Workgroup/Widgets/NonRankableFeedbackWidget.php`




### Blade tables

Status key: **fixed** = measured in a browser; **adapted, not yet verified** = migrated to `data-hub-table` (wrapper scroller removed) but not measured because the fixture does not reach it; **exception** = see below.

| View | Surface | Status |
|---|---|---|
| `workgroup/analysis-report`, `evaluation-report`, `final-recommendations`, `workgroup-summary` | Workgroups report decks (3, 16, 15, 11 tables) | fixed at 390 (`/workgroups/*` routes above); the 11px page overflow on those routes was the shell bar and is fixed, see exceptions |
| `filament/employee/pages/training-history` | Employee training history | fixed (`/employee/training-history`) |
| `filament/admin/pages/knowledge-base`, `media-control-media`, `medical-data-statistics`, `medical-supply-room` (5 tables), `trt-trailer-inventory` | Admin library, media, EMS statistics and supply room, TRT inventory | adapted, not yet verified |
| `filament/admin/modals/employee-record`, `filament/employees/application-access`, `workgroups-history`, `filament/pages/partials/personnel-table-history`, `filament/pages/personnel-reconciliation` | Employee record modal and personnel histories | adapted, not yet verified |
| `filament/fleet/identification-history`, `vehicle-change-history`, `vehicle-inspection-history`, `vehicle-proposed-corrections` | Vehicle record relation views | adapted, not yet verified |
| `filament/employee/pages/my-equipment`, `my-vehicle-inspection-view`, `filament/resources/inspection-resource/pages/view-inspection`, `inventory-correction-resource/pages/view-inventory-correction`, `components/inspection-checklist-v2-evidence` | Member equipment and inspection result tables | adapted, not yet verified |
| `filament/training/pages/course-matrix` | Training attendance matrix (many session columns) | adapted, not yet verified: each person becomes a card, sessions are the card's labelled fields |
| `filament/workgroup/pages/session-results`, `partials/granular-tool-table`, `filament-workgroup/pages/survey-results` | Workgroup ranking and survey tables | adapted, not yet verified |
| `security-standards` | Public standards matrix | adapted, not yet verified |
| `filament/resources/operational-form-record/view` | Document history | adapted, not yet verified: opts in to `data-hub-table` and the wrapper scroller is gone, but the fixture cannot create operational form records (`operational_form_records` is skipped) |
| `daily-roster/_section` | Daily roster (member page) | fixed, no change needed: measured by `playwright.daily-roster.config.ts` ("fits the viewport") at 320, 360, 390, 768, 1024, 1280 and 1440px with no document overflow and no sideways scroller (a fixed-layout table with its own card layout on phones; there is no `overflow-x` in the roster views or `daily-roster.css`); 1280px was added to that config |
| `daily-roster/pdf` | Daily roster PDF | out of scope (paper layout) |
| `pdf/*`, `exports/*`, `filament-workgroup/pages/survey-results-pdf`, `training/roster-pdf`, `training/crew-planner-proposal` | PDF and print documents | out of scope (paper layout) |

### React tables

| Surface | Status |
|---|---|
| `workgroup-data-dashboard` (rankings table) | fixed at 390 (`/workgroups/data-dashboard`) |
| `workgroup-l1-inventory` (126-row inventory) | fixed at 390 (`/workgroups/l1-inventory`) |
| Daily app `captain5/ems/master/MasterTable.tsx` | fits by construction: fixed layout, and on phones the shelf column folds under the item name. Measured by the Captain 5 master inventory suite (`captain5-master-inventory.spec.ts`), not by this contract |
| `operational-forms` record and entry tables (`of-table-wrap`) | fixed: the wrapper is a size container and the tables become cards when their room is short (record table under 640px, entry table under 900px), with the older viewport rules kept as a fallback. Measured at 320, 390, 768, 820, 900, 1024, 1280 and 1440px with no document overflow, no wrapper scroll and no table past its wrapper. The bundle's context navigation, evidence spine and preview toolbar are strips of controls, not tables (allowlisted) |
| `training-roster/main.tsx` kiosk table (4 columns) | not yet verified; fits by construction at 320px (no minimum widths) but not measured |
| `daily-roster.js` | covered with the roster view above |

### Exceptions and known gaps

1. **Shell bar overflow: fixed.** On the Workgroups panel at 390px the bar (brand, module switcher, two controls, account) was 23px wider than its room, so the document scrolled 11px sideways. The module switcher is now icon-only up to 419px (it already was up to 374px) in `hub-shell.css`; `--hub-shell-bar-height` is untouched and shell-alignment passes. The contract no longer excludes the shell: the document, every scroller and each open shell menu are measured on the Workgroups, Training and Employee panels.
2. **Strips that scroll by design, not tables** (allowlisted as `not-a-table`): dashboard filter chips (`.mbfd-dash-chips`), equipment intake tab strip, wizard progress navs, Daily tab list and inspection carousels, video filmstrip, rich text toolbar. Filament page tabs (`.fi-tabs`) now wrap instead.
3. **Checkbox size.** The visible row checkbox stays at the Hub's size (1.5rem from `hub-admin-v2.css`). On a coarse pointer its selection cell is at least 44px and a tap in the 44px square around the box toggles it (`onSelectionCell`, Shift/Ctrl/Meta kept for range selection); with a mouse only the box itself toggles.
4. **Pending migrations:** none. The remaining allowlisted `overflow-x` strips are not tables (see 2 and the operational forms strips above).
5. **Data the synthetic fixture cannot create.** Capital projects, department updates, hub support tickets and apparatus service tickets now render rows (valid enum values, live not archived). Still without rows: `operational_form_records`, `promotional_lists`, `evaluation_submissions` and `workgroup_members` (constraint failures in the generic filler), and pages that need a parent record or state. They use the shared module but are not browser-measured.
6. **`/admin/apparatus-service-tickets` returned HTTP 500 in the fixture: a real latent product bug, not fixed here.** The exception is `Filament\Forms\Components\Select::isOptionDisabled(): Argument #2 ($label) must be of type string, null given`. `ApparatusServiceTicketResource.php` lines 89-93 build the Unit filter with `SelectFilter::make('apparatus_id')->relationship('apparatus', 'designation')->preload()`. `apparatuses.designation` is nullable (migration `2026_01_28_141500_make_all_apparatus_columns_nullable`) and the Apparatus form (`ApparatusResource.php` lines 76-78) does not require it, so one apparatus saved without a designation makes the whole list page fail in production. Verified: with `designation` set on all 12 fixture units the page returns 200. The fixture now gives every unit a designation so the page can be measured. Other 4xx/5xx statuses in the tables above are generated rows that violate domain rules, not layout problems.
7. **Focusable wrappers kept.** A few migrated Blade wrappers keep `tabindex="0"` and `role="region"` (an existing feature test asserts one of them); they are harmless tab stops now that the wrapper does not scroll.
