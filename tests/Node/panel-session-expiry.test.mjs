import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const path = new URL('../../resources/views/filament/partials/session-expiry.blade.php', import.meta.url);
const source = existsSync(path) ? readFileSync(path, 'utf8').match(/<script[^>]*>([\s\S]*?)<\/script>/i)[1] : '';

function fixture() {
    const listeners = {};
    const hooks = [];
    const navigations = [];
    const controls = [{ value: 'secret-password', checked: true }, { value: 'private-form-data' }];
    const forbidden = () => { throw new Error('Expiry must not confirm, reload, or persist data'); };
    const window = { location: { replace: url => navigations.push(url), reload: forbidden },
        localStorage: { setItem: forbidden }, sessionStorage: { setItem: forbidden } };
    const context = { window, document: { addEventListener: (name, fn) => { listeners[name] = fn; },
        querySelectorAll: () => controls }, AbortSignal, confirm: forbidden };
    runInNewContext(source, context);
    window.Livewire = { hook: (name, fn) => { assert.equal(name, 'request'); hooks.push(fn); } };
    listeners['livewire:init']?.();
    return { context, hooks, controls, navigations };
}

test('overlapping 401/419 failures cause one canonical navigation with no native or HTML modal', () => {
    const app = fixture();
    assert.equal(app.hooks.length, 1);
    runInNewContext(source, app.context); // Navigation/script duplication must not add a second hook.
    assert.equal(app.hooks.length, 1);
    let prevented = 0;
    for (const status of [419, 401, 419, 503]) {
        app.hooks[0]({ options: {}, fail: callback => callback({ status, content: '<html>raw failure</html>',
            preventDefault: () => { prevented++; } }) });
    }
    assert.equal(prevented, 4);
    assert.deepEqual(app.navigations, ['/login?session_expired=1']);
    assert.ok(app.controls.every(control => control.value === '' && control.disabled));
    const options = {};
    app.hooks[0]({ options, fail: () => {} });
    assert.equal(options.signal.aborted, true);
});

test('authorization, validation and server errors keep their existing error handling', () => {
    const app = fixture();
    assert.equal(app.hooks.length, 1);
    for (const status of [403, 422, 500, 503]) {
        app.hooks[0]({ options: {}, fail: callback => callback({ status, preventDefault: assert.fail }) });
    }
    assert.deepEqual(app.navigations, []);
    assert.equal(app.controls[0].value, 'secret-password');
});
