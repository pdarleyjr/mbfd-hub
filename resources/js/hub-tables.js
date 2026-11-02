/**
 * Hub responsive tables for Filament (and Blade or React tables that add data-hub-table).
 *
 * An operational table never scrolls sideways. For every `table.fi-ta-table` this module measures the
 * width the table may use and applies the plan from ./hub-tables/plan.js:
 *
 *   table    unchanged.
 *   priority the lowest priority columns move into the row's details panel (a chevron button opens it).
 *   cards    each row becomes a stacked card; sorting moves into one Sort by menu.
 *
 * Nothing is removed from the page: hidden cells stay in their row (so inline inputs, Livewire
 * bindings, actions and sorting keep working) and are shown by CSS in the details panel.
 *
 * Priority comes from `->extraHeaderAttributes(['data-hub-priority' => 1])` on a column (lower is
 * more important; the first data column is always kept), and `data-hub-min` sets a readable minimum
 * width in rem. Columns with no attribute rank by their order. All styling lives in
 * resources/css/hub-tables.css.
 */
import { canReusePlan, planColumns, priorityOf } from './hub-tables/plan.js';

/** Filament tables, plus any Blade or React table that opts in with data-hub-table. */
const TABLE = 'table.fi-ta-table, table[data-hub-table]';
const BOX = '.fi-ta-content, [data-hub-table-box]';
const SELECTION_PX = 48;
const ACTIONS_PX = 120;
const TOUCH_PX = 44;
const CHEVRON =
  '<svg viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

const openRows = new Set();
/** Row key of the details button that had focus, so focus returns to it when Livewire re-renders the row. */
let focusedToggle = null;
const widths = new WeakMap();
/** Per table: the width, screen and column set a plan was made for, the plan, and what it measured. */
const planned = new WeakMap();
/** Document order of every table, built once per run so a row key never rescans the document. */
let tableIndexes = null;
let scheduled = false;
let observer = null;
let resizeObserver = null;
/** Counters so a test can bound the work: full re-plans (and how many ended squeezed, so are never reused), cheap restores, rows written, morphs ignored. */
const stats = { full: 0, squeezed: 0, restored: 0, rows: 0, ignoredMorphs: 0 };
if (typeof window !== 'undefined') window.__hubTablesStats = stats;

function labelOf(th) {
  const text = (th.textContent || '').replace(/\s+/g, ' ').trim();
  return text || th.getAttribute('aria-label') || '';
}

function kindOf(th) {
  if (th.matches('.fi-ta-selection-cell')) return 'select';
  if (th.matches('.fi-ta-actions-header-cell')) return 'actions';
  if (!/fi-table-header-cell-/.test(th.className) && !labelOf(th)) return 'fixed';
  return 'data';
}

function rowsOf(table) {
  return [...table.querySelectorAll(':scope > tbody > tr')];
}

function cellsOf(row) {
  return [...row.children].filter(c => c.tagName === 'TD' || c.tagName === 'TH');
}

function tableIndex(table) {
  return (tableIndexes ?? new Map([...document.querySelectorAll(TABLE)].map((t, i) => [t, i]))).get(table) ?? -1;
}

function rowKey(table, row, position) {
  return `${tableIndex(table)}:${row.getAttribute('wire:key') || `row-${position}`}`;
}

/** Everything that decides a plan besides the width: headings, their priorities, minimum widths and keep flags. */
function headSignature(table) {
  const headRow = table.querySelector(':scope > thead > tr:last-child');
  if (!headRow) return '';
  const heads = cellsOf(headRow);
  // The first row decides which columns hold inputs and how wide the actions are, so a plan made before real rows
  // existed (or with a group header first) is not reused once they arrive.
  const first = rowsOf(table)[0];
  const sampled = first && cellsOf(first).length === heads.length ? 'rows' : 'no-rows';
  return heads
    .map(th => [th.className, labelOf(th), th.getAttribute('data-hub-priority'), th.getAttribute('data-hub-min'), th.hasAttribute('data-hub-keep')].join('~'))
    .concat(sampled)
    .join('|');
}

function reset(table) {
  delete table.dataset.hubMode;
  table.style.removeProperty('--hub-rt-cols');
  table.querySelectorAll('[data-hub-hidden],[data-hub-label],[data-hub-lead],[data-hub-kind],[data-hub-span],[data-hub-rt-row]').forEach(el => {
    el.removeAttribute('data-hub-rt-row');
    el.removeAttribute('data-hub-span');
    el.removeAttribute('data-hub-hidden');
    el.removeAttribute('data-hub-label');
    el.removeAttribute('data-hub-lead');
    el.removeAttribute('data-hub-kind');
  });
  table.querySelectorAll('[data-hub-role]').forEach(el => {
    el.removeAttribute('role');
    el.removeAttribute('data-hub-role');
  });
}

function setRole(el, role) {
  if (el.hasAttribute('role')) return;
  el.setAttribute('role', role);
  el.setAttribute('data-hub-role', '');
}

function describe(table) {
  const headRow = table.querySelector(':scope > thead > tr:last-child');
  if (!headRow) return null;
  const ths = cellsOf(headRow);
  const columns = [];
  const native = [];
  let position = 0;
  ths.forEach((th, index) => {
    const kind = kindOf(th);
    th.setAttribute('data-hub-kind', kind);
    if (kind !== 'data') return;
    // A column Filament itself hides at this width (visibleFrom) stays out of the row and joins the details panel.
    if (getComputedStyle(th).display === 'none') {
      native.push({ index, th, label: labelOf(th) });
      return;
    }
    const rows = rowsOf(table);
    const sample = rows.length ? cellsOf(rows[0])[index] : null;
    const control = Boolean(sample?.querySelector('input:not([type=checkbox]):not([type=hidden]),select,textarea,.fi-ta-select,.fi-ta-text-input'));
    const minRem = Number.parseFloat(th.getAttribute('data-hub-min') ?? '');
    columns.push({
      index,
      th,
      label: labelOf(th),
      priority: priorityOf(th.getAttribute('data-hub-priority'), position),
      min: Number.isFinite(minRem) ? minRem * 16 : undefined,
      essential: position === 0 || th.hasAttribute('data-hub-keep'),
      control,
    });
    position += 1;
  });
  return { ths, columns, native };
}

function template(ths, columns, plan, mode) {
  const visible = new Set(plan.visible);
  const byIndex = new Map(columns.map(c => [c.index, c]));
  if (mode === 'cards') return '';
  return ths
    .map((th, index) => {
      const kind = th.getAttribute('data-hub-kind');
      if (kind === 'select') return `${SELECTION_PX / 16}rem`;
      if (kind === 'actions') return 'minmax(2.75rem, max-content)';
      if (kind === 'fixed') return '2.5rem';
      if (!byIndex.has(index) || !visible.has(index)) return null;
      const column = byIndex.get(index);
      const min = column.min ?? (column.control ? 144 : 112);
      return `minmax(${min / 16}rem, ${column.essential ? 2 : 1}fr)`;
    })
    .filter(Boolean)
    .join(' ');
}

function ensureToggle(row, lead, key) {
  let button = row.querySelector(':scope > td > .hub-rt-toggle');
  if (!button) {
    button = document.createElement('button');
    button.type = 'button';
    button.className = 'hub-rt-toggle';
    button.setAttribute('data-hub-rt-ui', '');
    button.innerHTML = CHEVRON;
    lead.append(button);
  } else if (button.parentElement !== lead || lead.lastElementChild !== button) {
    lead.append(button);
  }
  const isOpen = openRows.has(key);
  row.toggleAttribute('data-hub-open', isOpen);
  button.setAttribute('aria-expanded', String(isOpen));
  const name = (lead.querySelector(':scope > :not(.hub-rt-toggle)')?.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60);
  button.setAttribute('aria-label', name ? `Show or hide details for ${name}` : 'Show or hide details');
}

function removeToggles(table) {
  table.querySelectorAll('.hub-rt-toggle').forEach(el => el.remove());
  table.querySelectorAll('tr[data-hub-open]').forEach(el => el.removeAttribute('data-hub-open'));
}

function sortButton(th) {
  return th.querySelector('[role="button"]');
}

/** Hidden columns lose their heading, so their sort buttons move into one "Sort by" menu. */
function syncSortMenu(table, hiddenColumns, title) {
  const existing = table.parentElement.querySelector(':scope > .hub-rt-sort');
  const sortable = hiddenColumns.filter(c => sortButton(c.th));
  if (!sortable.length) {
    existing?.remove();
    return;
  }
  const menu = existing ?? document.createElement('div');
  menu.className = 'hub-rt-sort';
  menu.setAttribute('data-hub-rt-ui', '');
  const stateOf = c => ({ ascending: ' (sorted A to Z)', descending: ' (sorted Z to A)' })[c.th.getAttribute('aria-sort')] ?? '';
  const signature = title + '|' + sortable.map(c => c.index + c.label).join('|');
  if (menu.dataset.signature !== signature) {
    menu.dataset.signature = signature;
    const label = document.createElement('label');
    label.textContent = title + ' ';
    const select = document.createElement('select');
    select.className = 'hub-rt-sort__select';
    select.setAttribute('aria-label', title);
    select.add(new Option('Choose a field', ''));
    sortable.forEach(c => select.add(new Option(c.label, String(c.index))));
    select.addEventListener('change', () => {
      const index = Number.parseInt(select.value, 10);
      select.value = '';
      // Look the heading up again: Livewire may have re-rendered the table since the menu was built.
      const heading = menu.parentElement?.querySelector(':scope > table > thead > tr:last-child')?.children[index];
      if (heading) sortButton(heading)?.click();
    });
    menu.replaceChildren(label, select);
  }
  const select = menu.querySelector('select');
  sortable.forEach((c, position) => {
    if (select?.options[position + 1]) select.options[position + 1].text = c.label + stateOf(c);
  });
  const host = table.parentElement;
  if (host.lastElementChild !== menu) host.append(menu);
}

/** The element whose width the table may use: Filament's content box, a marked wrapper, or the parent. */
function boxOf(table) {
  return table.closest(BOX) ?? table.parentElement;
}

/** Natural width of the actions cell (links such as "Edit" and "Create record" do not wrap), measured before any mode is applied. */
function actionsWidth(table) {
  let widest = 0;
  rowsOf(table).slice(0, 6).forEach(row => {
    const cell = row.querySelector(':scope > td.fi-ta-actions-cell');
    if (cell) widest = Math.max(widest, Math.ceil(cell.getBoundingClientRect().width));
  });
  return widest;
}

/**
 * Put the table into the plan's mode: attributes and the grid template only, nothing moves in the DOM except the details buttons.
 * With `onlyNew` the table and heading are already set up, so only rows not yet marked `data-hub-rt-row` are written.
 */
function commit(table, info, plan, { onlyNew = false } = {}) {
  const { ths, columns, native } = info;
  const hidden = new Set([...plan.hidden, ...native.map(c => c.index)]);
  const every = [...columns, ...native].sort((a, b) => a.index - b.index);
  const hiddenColumns = every.filter(c => hidden.has(c.index));
  const firstVisible = columns.find(c => plan.visible.includes(c.index));

  if (!onlyNew) {
    table.dataset.hubMode = plan.mode;
    setRole(table, 'table');
    table.querySelectorAll(':scope > thead, :scope > tbody').forEach(el => setRole(el, 'rowgroup'));
    table.querySelectorAll(':scope > thead > tr').forEach(el => setRole(el, 'row'));
    table.querySelectorAll(':scope > thead th').forEach(th => setRole(th, 'columnheader'));
    const grid = template(ths, columns, plan, plan.mode);
    if (grid) table.style.setProperty('--hub-rt-cols', grid);
    if (plan.mode === 'priority') hiddenColumns.forEach(c => c.th.setAttribute('data-hub-hidden', ''));
    // In cards the heading row is replaced by one sort menu (and the select-all box), so no heading is shown twice.
    if (plan.mode === 'cards') every.forEach(c => c.th.setAttribute('data-hub-hidden', ''));
    if (!hiddenColumns.length) removeToggles(table);
  }

  rowsOf(table).forEach((row, position) => {
    if (row.hasAttribute('data-hub-rt-row')) return;
    stats.rows += 1;
    row.setAttribute('data-hub-rt-row', '');
    setRole(row, 'row');
    const cells = cellsOf(row);
    cells.forEach(cell => setRole(cell, 'cell'));
    if (cells.length !== ths.length) {
      // Group headers, summaries and colspan rows lay themselves out as a flowing line.
      row.setAttribute('data-hub-span', '');
      return;
    }
    const key = rowKey(table, row, position);
    cells.forEach((cell, index) => {
      const th = ths[index];
      cell.setAttribute('data-hub-kind', th.getAttribute('data-hub-kind'));
      const label = labelOf(th);
      if (label && th.getAttribute('data-hub-kind') === 'data') cell.setAttribute('data-hub-label', label);
      if (hidden.has(index)) cell.setAttribute('data-hub-hidden', '');
    });
    if (hiddenColumns.length && firstVisible) {
      const lead = cells[firstVisible.index];
      lead.setAttribute('data-hub-lead', '');
      ensureToggle(row, lead, key);
    }
  });

  if (plan.mode === 'priority') syncSortMenu(table, hiddenColumns, 'Sort by another field');
  else if (plan.mode === 'cards') syncSortMenu(table, every, 'Sort by');
}

function overflowOf(table, box) {
  return Math.max(0, table.scrollWidth - box.clientWidth);
}

/**
 * The page re-rendered the table (a Livewire morph, a poll, typing in a filter) but the width and the columns
 * are the same, so the plan still holds. Write only what the re-render dropped: nothing when the table and its
 * rows were left alone, the rows that lost their attributes otherwise. Returns false when new content made the
 * table wider than it was, so the caller plans again.
 */
function restore(table, box, state) {
  // A plan that had to be squeezed (an unbreakable value needed more room) or still overflowed is not trusted for
  // new content: short values may now fit more columns, so it is planned again as before.
  if (!state.reusable) return false;
  const { plan } = state;
  if (plan.mode === 'table') return overflowOf(table, box) <= state.overflow + 1;

  const headRow = table.querySelector(':scope > thead > tr:last-child');
  const current = headRow ? cellsOf(headRow) : [];
  const intact =
    table.dataset.hubMode === plan.mode &&
    (plan.mode === 'cards' || table.style.getPropertyValue('--hub-rt-cols') !== '') &&
    table.tBodies[0]?.hasAttribute('data-hub-role') &&
    current.length === state.info.ths.length &&
    current.every((th, i) => th === state.info.ths[i] && th.hasAttribute('data-hub-kind'));

  if (intact) {
    commit(table, state.info, plan, { onlyNew: true });
  } else {
    reset(table);
    const info = describe(table);
    if (!info) return false;
    state.info = info;
    commit(table, info, plan);
  }
  stats.restored += 1;
  return overflowOf(table, box) <= state.overflow + 1;
}

/** Plan from scratch: measure, pick a mode, and keep asking for more room while the real table is still wider than its box. */
function replan(table, box, signature) {
  stats.full += 1;
  const available = box.clientWidth;
  reset(table);
  const info = describe(table);
  if (!info || info.columns.length === 0) return;
  const { ths, columns, native } = info;
  const hasSelection = ths.some(th => th.getAttribute('data-hub-kind') === 'select');
  const fixed = (hasSelection ? SELECTION_PX : 0) + Math.max(actionsWidth(table), ths.some(th => th.getAttribute('data-hub-kind') === 'actions') ? ACTIONS_PX : 0);
  const planInput = columns.map(({ index, priority, min, essential, control }) => ({ index, priority, min, essential, control }));

  // The plan is an estimate from minimum widths. Check the real table and, while it is still wider than its box,
  // ask for more room and plan again (a cell with an unbreakable value can need more than its minimum).
  let room = available;
  let plan = { mode: 'table', visible: [], hidden: [] };
  let committed = '';
  for (let attempt = 0; attempt < 6; attempt += 1) {
    plan = planColumns({ available: room, fixed, columns: planInput });
    if (plan.mode === 'table' && native.length) plan = { mode: 'priority', visible: plan.visible, hidden: [] };
    const shape = `${plan.mode}:${plan.visible.join(',')}`;
    // Asking for more room can leave the plan unchanged: the table is already in that shape, so it is not written again.
    if (plan.mode !== 'table' && shape !== committed) {
      reset(table);
      describe(table);
      commit(table, info, plan);
      committed = shape;
    }
    if (plan.mode === 'cards') break;
    const overflow = table.scrollWidth - box.clientWidth;
    if (overflow <= 1) break;
    room -= overflow + 8;
  }

  if (plan.mode === 'table') {
    removeToggles(table);
    table.parentElement.querySelector(':scope > .hub-rt-sort')?.remove();
  }
  const overflow = overflowOf(table, box);
  const reusable = room === available && overflow <= 1;
  if (!reusable) stats.squeezed += 1;
  planned.set(table, { available, viewport: window.innerWidth, signature, plan, info, overflow, reusable });
}

function apply(table) {
  const box = boxOf(table);
  if (!box) return;
  const available = box.clientWidth;
  if (available === 0) return;

  const signature = headSignature(table);
  const state = planned.get(table);
  if (canReusePlan(state, { available, viewport: window.innerWidth, signature }) && restore(table, box, state)) return;
  replan(table, box, signature);
}

function run() {
  scheduled = false;
  observer?.disconnect();
  const tables = [...document.querySelectorAll(TABLE)];
  tableIndexes = new Map(tables.map((table, index) => [table, index]));
  try {
    tables.forEach(table => {
      try {
        apply(table);
      } catch (error) {
        console.error('Hub responsive table failed', error);
      }
    });
    restoreFocus();
  } finally {
    tableIndexes = null;
    watch();
  }
}

function restoreFocus() {
  if (!focusedToggle || (document.activeElement && document.activeElement !== document.body)) return;
  for (const button of document.querySelectorAll('.hub-rt-toggle')) {
    const row = button.closest('tr');
    const table = row?.closest('table');
    if (row && table && rowKey(table, row, rowsOf(table).indexOf(row)) === focusedToggle) {
      button.focus({ preventScroll: true });
      return;
    }
  }
}

function schedule() {
  if (scheduled) return;
  scheduled = true;
  requestAnimationFrame(run);
}

function watch() {
  observer ??= new MutationObserver(records => {
    // Ignore changes the module makes to its own controls.
    if (records.every(r => r.type === 'childList' && [...r.addedNodes, ...r.removedNodes].every(n => n.nodeType !== 1 || n.hasAttribute?.('data-hub-rt-ui')))) return;
    schedule();
  });
  new Set([...document.querySelectorAll(TABLE)].map(boxOf)).forEach(box => {
    observer.observe(box, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-hub-mode', 'data-hub-hidden', 'data-hub-label'] });
    if (!widths.has(box)) {
      widths.set(box, box.clientWidth);
      resizeObserver?.observe(box);
    }
  });
}

function onToggle(event) {
  const button = event.target.closest?.('.hub-rt-toggle');
  if (!button) return;
  event.preventDefault();
  const row = button.closest('tr');
  const table = row?.closest('table');
  if (!row || !table) return;
  const key = rowKey(table, row, rowsOf(table).indexOf(row));
  const next = !row.hasAttribute('data-hub-open');
  row.toggleAttribute('data-hub-open', next);
  button.setAttribute('aria-expanded', String(next));
  if (next) openRows.add(key);
  else openRows.delete(key);
}

/**
 * A row checkbox keeps its visible size, but the cell around it is the touch target: a tap anywhere in the
 * selection cell (outside the checkbox itself) toggles it. Pointer taps on the box are left to the browser.
 */
function onSelectionCell(event) {
  // Touch only: with a mouse the box is its own target, and a drag that ends beside it must not select the row.
  if (event.defaultPrevented || !window.matchMedia?.('(pointer: coarse)').matches) return;
  const cell = event.target.closest?.('td.fi-ta-selection-cell, th.fi-ta-selection-cell');
  if (!cell || event.target.closest('input, label, button, a')) return;
  const box = cell.querySelector('input[type="checkbox"]');
  if (!box || box.disabled) return;
  // Only the 44px square around the box counts, so the empty width of a card's checkbox row never toggles it.
  const rect = box.getBoundingClientRect();
  const near = Math.abs(event.clientX - (rect.left + rect.width / 2)) <= TOUCH_PX / 2 && Math.abs(event.clientY - (rect.top + rect.height / 2)) <= TOUCH_PX / 2;
  // A synthetic click that keeps the modifier keys, so Shift-click range selection still works.
  if (near) box.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, shiftKey: event.shiftKey, ctrlKey: event.ctrlKey, metaKey: event.metaKey, altKey: event.altKey }));
}

export function startHubTables() {
  if (window.__hubTablesStarted) return;
  window.__hubTablesStarted = true;
  resizeObserver = new ResizeObserver(entries => {
    let changed = false;
    entries.forEach(entry => {
      const width = Math.round(entry.contentRect.width);
      if (widths.get(entry.target) !== width) {
        widths.set(entry.target, width);
        changed = true;
      }
    });
    if (changed) schedule();
  });
  document.addEventListener('click', onToggle, true);
  document.addEventListener('click', onSelectionCell);
  document.addEventListener('focusin', event => {
    const button = event.target.closest?.('.hub-rt-toggle');
    const row = button?.closest('tr');
    const table = row?.closest('table');
    focusedToggle = row && table ? rowKey(table, row, rowsOf(table).indexOf(row)) : null;
  });
  document.addEventListener('livewire:navigated', schedule);
  document.addEventListener('livewire:initialized', () => {
    schedule();
    // A component with no table in it (a form, a chart, a notification bell) cannot change any table: skip the pass.
    window.Livewire?.hook?.('morphed', payload => {
      const el = payload?.el;
      if (!el?.matches || el.matches(TABLE) || el.querySelector(TABLE) || el.closest(TABLE)) schedule();
      else stats.ignoredMorphs += 1;
    });
  });
  window.addEventListener('resize', schedule);
  document.fonts?.ready.then(schedule);
  // Filament can render a table after load (tabs, modals, relation managers): watch the document for new boxes.
  new MutationObserver(records => {
    if (records.some(r => [...r.addedNodes].some(n => n.nodeType === 1 && (n.matches?.(TABLE) || n.querySelector?.(TABLE))))) schedule();
  }).observe(document.body, { childList: true, subtree: true });
  schedule();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startHubTables);
  else startHubTables();
}
