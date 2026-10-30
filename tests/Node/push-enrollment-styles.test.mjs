import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// 2026-10-30: on every Filament panel (Employee, Admin, Training, Workgroups) the "Turn on alerts for this
// device" button was invisible (white text, `bg-hub-blue` never compiled into the panel theme) and Tailwind's
// `inline-flex` overrode `hidden`, so un-enrolled iPhones were offered "Send a test" instead of "Turn on".

const theme = readFileSync('resources/css/filament/admin/theme.css', 'utf8');
const styles = readFileSync('resources/css/filament/push-enrollment.css', 'utf8');
const view = readFileSync('resources/views/filament/pages/notification-settings.blade.php', 'utf8');

test('the panel theme imports the enrollment styles among its leading @imports', () => {
  const imports = theme.split('\n').filter((line) => line.startsWith('@import'));
  assert.ok(imports.includes("@import '../push-enrollment.css';"));
  const firstRule = theme.search(/^[^@\s/*][^\n]*\{/m);
  assert.ok(theme.indexOf("@import '../push-enrollment.css';") < firstRule, '@import must precede every rule or it is dropped');
});

test('hidden enrollment buttons stay hidden whatever utility classes they carry', () => {
  assert.match(styles, /\[data-push-enrollment\] \[hidden\]\s*\{\s*display:\s*none !important;/);
});

test('the turn-on button gets a visible background from theme tokens, not an uncompiled utility', () => {
  assert.match(styles, /\[data-role='enable'\]\s*\{[^}]*background-color:\s*rgb\(var\(--hub-blue\)\)/);
  assert.match(view, /data-role="enable" hidden/);
  assert.match(view, /data-role="test" hidden/);
  assert.match(view, /data-role="disable" hidden/);
});
