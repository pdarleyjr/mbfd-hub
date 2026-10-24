import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const css = readFileSync(resolve(process.cwd(), 'resources/css/filament/admin/hub-admin-v2.css'), 'utf8');
const panels = ':is(.fi-panel-admin, .fi-panel-employee, .fi-panel-workgroups, .fi-panel-training)';

function rule(selector) {
  const start = css.indexOf(`${selector} {`);
  assert.notEqual(start, -1, `missing rule for ${selector}`);
  return css.slice(start, css.indexOf('}', start));
}

test('the topbar bell takes its real 44px size instead of a negative margin', () => {
  assert.match(rule(`${panels} .fi-topbar .fi-topbar-database-notifications-btn`), /margin:\s*0;/);
});

test('the notification count stays inside the bell button and never rises above the bar', () => {
  const badge = rule(`${panels} .fi-topbar .fi-topbar-database-notifications-btn .fi-icon-btn-badge-ctn`);
  assert.match(badge, /transform:\s*none;/);
  assert.match(badge, /top:\s*0\.125rem;/);
  assert.match(badge, /inset-inline-end:\s*0\.125rem;/);
  assert.match(badge, /inset-inline-start:\s*auto;/);
});
