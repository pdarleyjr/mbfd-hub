/**
 * Pure planning rules for Hub responsive tables (no DOM, tested in tests/Node/hub-tables-plan.test.mjs).
 *
 * A table has "data" columns (the ones with headings) and "fixed" cells (the selection checkbox and the
 * actions cell). Given the width the table may use, decide how it is shown:
 *
 *   table    every column fits at its readable minimum width.
 *   priority the lowest priority columns move into the row's details panel until the rest fit.
 *   cards    even the essential columns do not fit: each row becomes a stacked card with a short
 *            summary and the remaining fields in the details panel.
 *
 * Nothing is dropped: every column is either visible in the row or listed in `hidden`, and the DOM
 * layer shows the hidden ones in the details panel.
 */

export const DEFAULTS = Object.freeze({
  /** Readable minimum for a plain text column, in px (7rem). */
  textMin: 112,
  /** Readable minimum for a column holding an input, select or toggle, in px (9rem). */
  controlMin: 144,
  /** Below this container width every table becomes cards, in px. */
  cardsBelow: 560,
  /** Fields shown on a card before "Show details", including the essential column. */
  cardSummary: 4,
});

/**
 * @typedef {{ index: number, priority: number, min?: number, essential?: boolean, control?: boolean }} Column
 */

/** Sort key: lower priority number = more important. Ties keep their document order. */
function byImportance(a, b) {
  return a.priority - b.priority || a.index - b.index;
}

function minWidth(column, options) {
  if (typeof column.min === 'number' && column.min > 0) return column.min;
  return column.control ? options.controlMin : options.textMin;
}

/**
 * @param {{ available: number, fixed?: number, columns: Column[], cardsBelow?: number, cardSummary?: number,
 *           textMin?: number, controlMin?: number }} input
 * @returns {{ mode: 'table'|'priority'|'cards', visible: number[], hidden: number[] }}
 */
export function planColumns(input) {
  const options = { ...DEFAULTS, ...input };
  const columns = [...input.columns];
  const fixed = Math.max(0, input.fixed ?? 0);
  const all = columns.map(c => c.index).sort((a, b) => a - b);

  if (columns.length === 0) return { mode: 'table', visible: [], hidden: [] };

  const essentials = columns.filter(c => c.essential);
  const demand = list => fixed + list.reduce((sum, c) => sum + minWidth(c, options), 0);

  if (options.available >= options.cardsBelow && demand(columns) <= options.available) {
    return { mode: 'table', visible: all, hidden: [] };
  }

  if (options.available >= options.cardsBelow && demand(essentials) <= options.available) {
    // Drop the least important column until the rest fit (essentials are never dropped).
    const droppable = columns.filter(c => !c.essential).sort(byImportance);
    const kept = new Set(columns.map(c => c.index));
    while (droppable.length && demand(columns.filter(c => kept.has(c.index))) > options.available) {
      kept.delete(droppable.pop().index);
    }
    const visible = all.filter(i => kept.has(i));
    return { mode: 'priority', visible, hidden: all.filter(i => !kept.has(i)) };
  }

  const summaryCount = Math.max(options.cardSummary, essentials.length);
  const summary = new Set(
    [...essentials, ...columns.filter(c => !c.essential).sort(byImportance)].slice(0, summaryCount).map(c => c.index),
  );
  return {
    mode: 'cards',
    visible: all.filter(i => summary.has(i)),
    hidden: all.filter(i => !summary.has(i)),
  };
}

/** Default priority for a heading: an explicit `data-hub-priority` wins, otherwise document order. */
export function priorityOf(explicit, position) {
  const parsed = Number.parseInt(explicit ?? '', 10);
  return Number.isFinite(parsed) ? parsed : position;
}

/**
 * Whether the plan made for a table can be used again after the page re-rendered it (a Livewire morph,
 * a poll, filter-as-you-type). It can when the width the table may use, the screen width and the column
 * set (headings, priorities, minimum widths) are all unchanged: only rows still need their attributes.
 *
 * @param {{ available: number, viewport: number, signature: string } | undefined} previous
 * @param {{ available: number, viewport: number, signature: string }} next
 */
export function canReusePlan(previous, next) {
  return Boolean(previous)
    && previous.available === next.available
    && previous.viewport === next.viewport
    && previous.signature === next.signature;
}
