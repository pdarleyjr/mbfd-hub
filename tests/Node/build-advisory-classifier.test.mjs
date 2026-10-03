import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';
import { classifyBuildAdvisory, classifyVacationBuildAdvisory } from '../../scripts/ci/classify-build-advisory.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const now = Date.parse('2026-10-03T00:00:00Z');
// Exact fields from the 2026-10-03 raw npm-v2 report; no audit network calls.
const report = {
    auditReportVersion: 2,
    vulnerabilities: {
        braces: { name: 'braces', severity: 'high', nodes: ['node_modules/braces'], via: [{ name: 'braces', dependency: 'braces', url: 'https://github.com/advisories/GHSA-vfj7-8cjw-p6xm', severity: 'high', range: '<=3.0.3' }] },
        chokidar: { name: 'chokidar', severity: 'high', nodes: ['node_modules/chokidar'], via: ['braces'] },
        'fast-glob': { name: 'fast-glob', severity: 'high', nodes: ['node_modules/fast-glob'], via: ['micromatch'] },
        micromatch: { name: 'micromatch', severity: 'high', nodes: ['node_modules/micromatch'], via: ['braces'] },
        tailwindcss: { name: 'tailwindcss', severity: 'high', nodes: ['node_modules/tailwindcss'], via: ['chokidar', 'fast-glob', 'micromatch'] },
    },
    metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 5, critical: 0, total: 5 } },
};
function changed(change) {
    const value = structuredClone(report);
    change(value);
    return value;
}
const pnpmReport = {
    actions: [{ action: 'review', module: 'braces', resolves: [{ id: 1240992, path: 'apps__web>tailwindcss>chokidar>braces', dev: false, bundled: false, optional: false }] }],
    advisories: { '1240992': { id: 1240992, module_name: 'braces', severity: 'high', github_advisory_id: 'GHSA-vfj7-8cjw-p6xm', vulnerable_versions: '<=3.0.3', findings: [{ version: '3.0.3', paths: [] }] } },
    metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 1, critical: 0 } },
};

test('the exact reviewed root and Daily reports classify only the known advisory', () => {
    for (const scope of ['.', 'resources/js/daily-checkout']) assert.match(classifyBuildAdvisory(report, scope, 1, now), /VISIBLE BUILD ADVISORY/);
    assert.throws(() => classifyBuildAdvisory(report, 'vacation-app', 1, now), /Unreviewed audit scope/);
    assert.throws(() => classifyBuildAdvisory(report, 'modules/policy-library', 1, now), /Unreviewed audit scope/);
});
test('another HIGH advisory remains blocking beside the known finding', () => {
    const value = changed((copy) => copy.vulnerabilities.braces.via.push({ ...copy.vulnerabilities.braces.via[0], url: 'https://github.com/advisories/GHSA-induced-other-high' }));
    assert.throws(() => classifyBuildAdvisory(value, '.', 1, now), /Unclassified HIGH or CRITICAL/);
});
test('CRITICAL findings, unrelated nodes and invented dependency edges fail', () => {
    for (const change of [
        (copy) => { copy.vulnerabilities.braces.severity = 'critical'; copy.metadata.vulnerabilities.high--; copy.metadata.vulnerabilities.critical++; },
        (copy) => { copy.vulnerabilities.braces.nodes = ['node_modules/chokidar']; },
        (copy) => { copy.vulnerabilities.tailwindcss.via = ['braces', 'fast-glob', 'micromatch']; },
    ]) assert.throws(() => classifyBuildAdvisory(changed(change), '.', 1, now), /Unclassified HIGH or CRITICAL/);
});
test('expiry has no CLI or environment override', () => {
    assert.throws(() => classifyBuildAdvisory(report, '.', 1, Date.parse('2026-10-10T00:00:00Z')), /expired/);
});
test('failed clean audits, malformed reports and inconsistent counts fail', () => {
    const clean = { auditReportVersion: 2, vulnerabilities: {}, metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 0, critical: 0, total: 0 } } };
    assert.match(classifyBuildAdvisory(clean, '.', 0, now), /No HIGH/);
    assert.throws(() => classifyBuildAdvisory(clean, '.', 1, now), /Audit exit contradicts/);
    for (const change of [
        (copy) => { copy.error = { message: 'ENOTFOUND' }; },
        (copy) => { copy.auditReportVersion = 1; },
        (copy) => { copy.metadata.vulnerabilities.low = -1; },
        (copy) => { copy.metadata.vulnerabilities.high = 4; },
        (copy) => { copy.metadata.vulnerabilities.total = 6; },
    ]) assert.throws(() => classifyBuildAdvisory(changed(change), '.', 1, now));
});
test('the CLI exposes raw malformed or failed audit reports before refusing them', () => {
    const directory = mkdtempSync(join(tmpdir(), 'mbfd-audit-report-'));
    try {
        const file = join(directory, 'audit.json');
        for (const [raw, exit] of [['{malformed', '1'], [JSON.stringify(report), '2']]) {
            writeFileSync(file, raw);
            const result = spawnSync(process.execPath, [resolve(root, 'scripts/ci/classify-build-advisory.mjs'), '.', file, exit], { encoding: 'utf8', timeout: 10000 });
            assert.equal(result.status, 1);
            assert.ok(result.stdout.startsWith(raw));
        }
    } finally { assert.equal(dirname(directory), tmpdir()); rmSync(directory, { recursive: true, force: true }); }
});
test('a changed lock SHA cannot reuse the exception', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'mbfd-audit-input-'));
    try {
        for (const file of ['scripts/ci/classify-build-advisory.mjs', 'package.json', 'package-lock.json', 'docker/production/Dockerfile', '.dockerignore']) {
            mkdirSync(dirname(join(directory, file)), { recursive: true });
            copyFileSync(resolve(root, file), join(directory, file));
        }
        writeFileSync(join(directory, 'package-lock.json'), `${readFileSync(resolve(root, 'package-lock.json'), 'utf8')}\n`);
        const isolated = await import(pathToFileURL(join(directory, 'scripts/ci/classify-build-advisory.mjs')).href);
        assert.throws(() => isolated.classifyBuildAdvisory(report, '.', 1, now), /Unreviewed input: package-lock.json/);
    } finally { assert.equal(dirname(directory), tmpdir()); rmSync(directory, { recursive: true, force: true }); }
});
test('pnpm classifies only the exact reviewed braces build graph and expiry', () => {
    assert.match(classifyVacationBuildAdvisory(pnpmReport, 1, now), /runtime remains unqualified/);
    assert.throws(() => classifyVacationBuildAdvisory(pnpmReport, 1, Date.parse('2026-10-10T00:00:00Z')), /expired/);
    for (const mutate of [
        (copy) => { copy.advisories['1240992'].github_advisory_id = 'GHSA-induced-other-high'; },
        (copy) => { copy.advisories['1240992'].findings[0].version = '3.0.2'; },
        (copy) => { copy.actions[0].resolves[0].path = 'apps__api>braces'; },
        (copy) => { copy.advisories['1240992'].severity = 'critical'; copy.metadata.vulnerabilities.high--; copy.metadata.vulnerabilities.critical++; },
        (copy) => { copy.advisories['1234'] = { id: 1234, module_name: 'other', severity: 'high' }; copy.metadata.vulnerabilities.high++; },
        (copy) => { copy.metadata.vulnerabilities.high = 0; },
        (copy) => { copy.metadata.vulnerabilities.low = 0.5; },
        (copy) => { copy.error = { message: 'ENOTFOUND' }; },
        (copy) => { copy.actions = null; },
    ]) {
        const value = structuredClone(pnpmReport);
        mutate(value);
        assert.throws(() => classifyVacationBuildAdvisory(value, 1, now));
    }
    const clean = { actions: [], advisories: {}, metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 0, critical: 0 } } };
    assert.throws(() => classifyVacationBuildAdvisory(clean, 1, now), /Audit exit contradicts/);
    assert.throws(() => classifyVacationBuildAdvisory(pnpmReport, 2, now), /did not complete normally/);
});
test('a changed pnpm lock SHA cannot reuse the exception', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'mbfd-pnpm-input-'));
    try {
        for (const file of ['scripts/ci/classify-build-advisory.mjs', 'vacation-app/package.json', 'vacation-app/pnpm-lock.yaml', 'vacation-app/apps/web/package.json', 'vacation-app/apps/web/tailwind.config.ts']) {
            mkdirSync(dirname(join(directory, file)), { recursive: true });
            copyFileSync(resolve(root, file), join(directory, file));
        }
        writeFileSync(join(directory, 'vacation-app/pnpm-lock.yaml'), `${readFileSync(resolve(root, 'vacation-app/pnpm-lock.yaml'), 'utf8')}\n`);
        const isolated = await import(pathToFileURL(join(directory, 'scripts/ci/classify-build-advisory.mjs')).href);
        assert.throws(() => isolated.classifyVacationBuildAdvisory(pnpmReport, 1, now), /Unreviewed input: vacation-app\/pnpm-lock.yaml/);
    } finally { assert.equal(dirname(directory), tmpdir()); rmSync(directory, { recursive: true, force: true }); }
});
test('an additional action for the same pnpm advisory cannot hide an unreviewed route', () => {
    const value = structuredClone(pnpmReport);
    value.actions.push({ action: 'review', module: 'other', resolves: [{ id: 1240992, path: 'apps__api>braces', dev: false }] });
    assert.throws(() => classifyVacationBuildAdvisory(value, 1, now), /Unreviewed pnpm actions/);
});
test('a malformed additional pnpm action cannot evade the route checks', () => {
    const value = structuredClone(pnpmReport);
    value.actions.push({ module: 'other', resolves: { id: 1240992, path: 'apps__api>braces' } });
    assert.throws(() => classifyVacationBuildAdvisory(value, 1, now), /Invalid pnpm action schema/);
});
