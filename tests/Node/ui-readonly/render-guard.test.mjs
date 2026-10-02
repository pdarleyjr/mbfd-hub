import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import Module from 'node:module';
import { dirname } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';

const sourcePath = fileURLToPath(new URL('../../e2e/support/ui-readonly.ts', import.meta.url));
const guardModule = new Module(sourcePath);
guardModule.filename = sourcePath;
guardModule.paths = Module._nodeModulePaths(dirname(sourcePath));
guardModule._compile(ts.transpileModule(readFileSync(sourcePath, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText, sourcePath);
const { guardUiRendering } = guardModule.exports;

const origin = 'http://127.0.0.1:19999';
const notifications = 'filament.livewire.database-notifications';
const trainingStats = 'app.filament.training.widgets.training-stats-widget';
const component = (name = notifications, methods = [], updates = {}) => ({
    snapshot: JSON.stringify({ memo: { name } }),
    updates,
    calls: methods.map(method => ({ method, params: [] })),
});

async function classify({ body = { components: [component()] }, method = 'POST',
    url = origin + '/livewire/update', pagePath = '/admin/capital-projects/create',
    navigation = false, invalidJson = false, guardOptions = {} } = {}) {
    let handler;
    const blocked = [];
    const allowed = [];
    const actions = [];
    await guardUiRendering({ route: async (pattern, callback) => {
        assert.equal(pattern, '**/*');
        handler = callback;
    } }, origin, { ...guardOptions, onBlocked: description => blocked.push(description),
        onAllowedLivewireRequest: request => allowed.push(request) });
    const request = {
        method: () => method, url: () => url, isNavigationRequest: () => navigation,
        frame: () => ({ url: () => origin + pagePath }),
        postDataJSON: () => { if (invalidJson) throw new SyntaxError('Synthetic invalid JSON'); return body; },
    };
    await handler({ request: () => request,
        abort: async reason => { assert.equal(reason, 'blockedbyclient'); actions.push('abort'); },
        continue: async () => { actions.push('continue'); } });
    assert.equal(actions.length, 1, 'Every request receives exactly one route decision');
    assert.equal(blocked.length, actions[0] === 'abort' ? 1 : 0);
    assert.equal(allowed.length, actions[0] === 'continue' && method === 'POST' ? 1 : 0);
    return actions[0];
}

test('the actual guard permits the source-proven native notification empty poll', async () => {
    assert.equal(await classify(), 'continue');
});

test('the existing TrainingStats empty poll and a batch of both native polls remain permitted', async () => {
    assert.equal(await classify({ body: { components: [component(trainingStats)] } }), 'continue');
    assert.equal(await classify({ body: { components: [component(), component(trainingStats)] } }), 'continue');
});

for (const method of ['loadTable', '__lazyLoad', 'getFormUploadedFiles']) {
    test(`the existing native render method ${method} remains permitted`, async () => {
        assert.equal(await classify({ body: { components: [component(notifications, [method])] } }), 'continue');
    });
}

test('the existing Pulse refresh and scoped disabled-AI report retain their gates', async () => {
    assert.equal(await classify({ body: { components: [component('pulse.usage', ['$refresh'])] } }), 'continue');
    const body = { components: [component('app.filament.pages.session-results-page', ['loadAiReport'])] };
    assert.equal(await classify({ body, pagePath: '/workgroups/session-results', guardOptions: { allowDisabledAiReport: true } }), 'continue');
    assert.equal(await classify({ body, pagePath: '/workgroups/session-results' }), 'abort');
    assert.equal(await classify({ body, guardOptions: { allowDisabledAiReport: true } }), 'abort');
});

for (const method of ['removeNotification', 'markNotificationAsRead', 'markNotificationAsUnread',
    'clearNotifications', 'markAllNotificationsAsRead', '__dispatch', '$set', '$refresh']) {
    test(`notification call ${method} stays blocked`, async () => {
        assert.equal(await classify({ body: { components: [component(notifications, [method])] } }), 'abort');
    });
}

for (const name of ['database-notifications', 'filament.livewire.notifications',
    'filament.livewire.database-notifications.extra', 'other.filament.livewire.database-notifications',
    'Filament.livewire.database-notifications', 'app.filament.resources.capital-project-resource.pages.create-capital-project', '']) {
    test(`an empty commit for ${name || 'an unnamed component'} stays blocked`, async () => {
        assert.equal(await classify({ body: { components: [component(name)] } }), 'abort');
    });
}

for (const updates of [{ read_at: 'synthetic' }, { 'paginators.database-notifications-page': 2 },
    { 'data.title': 'synthetic' }, null, [], '', 0]) {
    test(`notification updates ${JSON.stringify(updates)} stay blocked`, async () => {
        assert.equal(await classify({ body: { components: [component(notifications, [], updates)] } }), 'abort');
    });
}

for (const [label, change] of [
    ['missing updates', value => { delete value.updates; }],
    ['missing calls', value => { delete value.calls; }],
    ['object calls', value => { value.calls = {}; }],
    ['null calls', value => { value.calls = null; }],
    ['malformed call', value => { value.calls = [null]; }],
    ['malformed snapshot', value => { value.snapshot = '{'; }],
    ['null snapshot', value => { value.snapshot = 'null'; }],
    ['array snapshot', value => { value.snapshot = '[]'; }],
    ['missing snapshot', value => { delete value.snapshot; }],
]) {
    test(`${label} stays blocked`, async () => {
        const value = component();
        change(value);
        assert.equal(await classify({ body: { components: [value] } }), 'abort');
    });
}

for (const body of [{}, { components: [] }, { components: null }, { components: {} },
    { components: 'synthetic' }, { components: [null] }]) {
    test(`malformed component batch ${JSON.stringify(body)} stays blocked`, async () => {
        assert.equal(await classify({ body }), 'abort');
    });
}

test('invalid request JSON stays blocked', async () => {
    assert.equal(await classify({ invalidJson: true }), 'abort');
});

test('a safe notification poll cannot admit a mutation or an unrelated empty commit in its batch', async () => {
    for (const unsafe of [component(notifications, ['markNotificationAsRead']),
        component(notifications, [], { read_at: 'synthetic' }), component('app.livewire.unrelated')]) {
        for (const components of [[component(), unsafe], [unsafe, component()]]) {
            assert.equal(await classify({ body: { components } }), 'abort');
        }
    }
});

test('a native read combined with a notification mutation stays blocked', async () => {
    assert.equal(await classify({ body: { components: [component(notifications, ['__lazyLoad', 'clearNotifications'])] } }), 'abort');
});

test('the exception does not admit other origins, methods, endpoints or external navigation', async () => {
    for (const request of [{ url: 'https://example.invalid/livewire/update' }, { method: 'PUT' },
        { url: origin + '/login' }, { url: origin + '/livewire/update/extra' },
        { method: 'GET', navigation: true, url: 'https://example.invalid/' }]) {
        assert.equal(await classify(request), 'abort');
    }
    for (const method of ['GET', 'HEAD', 'OPTIONS']) {
        assert.equal(await classify({ method, url: origin + '/admin' }), 'continue');
    }
});
