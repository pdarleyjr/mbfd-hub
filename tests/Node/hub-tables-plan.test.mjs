import assert from 'node:assert/strict';
import test from 'node:test';
import { planColumns, priorityOf } from '../../resources/js/hub-tables/plan.js';

const cols = count => Array.from({ length: count }, (_, i) => ({ index: i + 1, priority: i, essential: i === 0 }));

test('a table whose columns all fit at their readable minimum is left alone', () => {
  const plan = planColumns({ available: 1200, fixed: 168, columns: cols(6) });
  assert.equal(plan.mode, 'table');
  assert.deepEqual(plan.hidden, []);
  assert.equal(plan.visible.length, 6);
});

test('fifteen columns on a laptop keep the important ones and move the rest into details', () => {
  const plan = planColumns({ available: 1000, fixed: 168, columns: cols(15) });
  assert.equal(plan.mode, 'priority');
  assert.ok(plan.visible.includes(1), 'the first (identity) column is never moved');
  assert.ok(plan.hidden.length > 0);
  assert.equal(plan.visible.length + plan.hidden.length, 15, 'every column is shown or in details, none is dropped');
  assert.ok(plan.hidden.includes(15), 'the least important column is hidden');
  assert.ok(!plan.hidden.includes(2), 'a more important column outlives a less important one');
});

test('priority numbers win over document order', () => {
  const columns = [
    { index: 1, priority: 0, essential: true },
    { index: 2, priority: 9 },
    { index: 3, priority: 1 },
    { index: 4, priority: 2 },
    { index: 5, priority: 3 },
  ];
  const plan = planColumns({ available: 560, fixed: 120, columns });
  assert.equal(plan.mode, 'priority');
  assert.ok(plan.hidden.includes(2));
});

test('a container narrower than the phone breakpoint becomes cards with a short summary', () => {
  const plan = planColumns({ available: 390, fixed: 168, columns: cols(10) });
  assert.equal(plan.mode, 'cards');
  assert.equal(plan.visible.length, 4);
  assert.equal(plan.visible.length + plan.hidden.length, 10);
  assert.ok(plan.visible.includes(1));
});

test('essential columns that cannot fit even alone also become cards', () => {
  const columns = [
    { index: 1, priority: 0, essential: true, min: 400 },
    { index: 2, priority: 1, essential: true, min: 400 },
    { index: 3, priority: 2 },
  ];
  const plan = planColumns({ available: 700, fixed: 100, columns });
  assert.equal(plan.mode, 'cards');
});

test('controls need more room than text', () => {
  const text = [{ index: 1, priority: 0, essential: true }, { index: 2, priority: 1 }, { index: 3, priority: 2 }, { index: 4, priority: 3 }];
  const inputs = text.map(c => ({ ...c, control: true }));
  const input = { available: 520 + 40, fixed: 0 };
  assert.equal(planColumns({ ...input, columns: text }).mode, 'table');
  assert.equal(planColumns({ ...input, columns: inputs }).mode, 'priority');
});

test('an explicit data-hub-min overrides the default', () => {
  const columns = [{ index: 1, priority: 0, essential: true, min: 700 }, { index: 2, priority: 1 }];
  assert.equal(planColumns({ available: 600, columns }).mode, 'cards');
});

test('no columns is a table', () => {
  assert.deepEqual(planColumns({ available: 900, columns: [] }), { mode: 'table', visible: [], hidden: [] });
});

test('priorityOf prefers the explicit attribute', () => {
  assert.equal(priorityOf('3', 7), 3);
  assert.equal(priorityOf(null, 7), 7);
  assert.equal(priorityOf('', 2), 2);
  assert.equal(priorityOf('x', 4), 4);
});
