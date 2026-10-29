import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const advisory = 'https://github.com/advisories/GHSA-vfj7-8cjw-p6xm';
const expires = Date.parse('2026-11-12T00:00:00Z');
// Approved under ROOT_BUILD_BRACES_EXCEPTION_20261003T0413Z and the P0 request
// to document existing unrelated advisories using project policy. Renewed once,
// 2026-10-12, by the owner (Peter) for 30 days: GHSA-vfj7-8cjw-p6xm has no patched
// braces release (3.0.3 is the latest) and only Tailwind 3 build tooling is affected.
// Scope, advisory and input hashes are unchanged; the next step is a Tailwind 4
// migration, not another renewal. This covers
// exact locked build graphs until 2026-11-12, not assembled Vacation runtime
// images or emitted browser bundles. Raw reports and all other gates remain.
// 2026-10-22: Vacation lock/web manifest hashes re-pinned for the Next.js
// 15.5.24 -> 15.5.28 security upgrade (GHSA-2464-fhr4-w9xw, CVE-2026-94482).
// Only next, @next/env and @next/swc-* moved; the braces graph is byte-identical.
// 2026-10-23: re-pinned for the zod 3 -> 4.6.7 upgrade (HIGH GHSA-mrhx-c7xc-m3w5),
// postcss 8.5.29, hono 4.13.13 and wrangler 4.149.0 (drops miniflare's zod 3).
// Tailwind 3 and its braces graph are unchanged.
// 2026-10-23: root package.json re-pinned for five added npm scripts (service-worker build/check and
// PWA test runners). No dependency, version or lock change; package-lock.json is byte-identical.
// 2026-10-24: re-pinned for undici GHSA-xjj6-f33q-929c (testcontainers>undici 6.29.1,
// miniflare>undici 7.30.1; dev tooling only). Tailwind 3 / braces graph unchanged.
// 2026-10-29: root package.json re-pinned for two npm script changes (test:employee-messages-e2e added;
// test:pwa-worker-core also runs pwa-refresh-safety). No dependency, version or lock change.
const inputs = {
    '.': {
        'package.json': '7f0109fa7099540d9fc0b3c8994509c76aaa8b1e9124de5c7d18f20de8524d5e',
        'package-lock.json': 'd2716fe1c072e7aff6741ea5f738a3d2760a19e7230f94871e6a2312924a0010',
    },
    'resources/js/daily-checkout': {
        'resources/js/daily-checkout/package.json': 'dd808f286eff6301a2e51dd39dc08739868eac9d473c08ea818776c5ebbcfb81',
        'resources/js/daily-checkout/package-lock.json': '3c4419f65bcca76d340ff9f537ff51b85f6e265961cdcba570d9225ad4a71903',
    },
};
const runtimeInputs = {
    'docker/production/Dockerfile': 'ed5a4d865fa96f674b54129db7aefb066341df0c1db9c79225eafb72b0108c8c',
    '.dockerignore': 'c360145b9617b149ed9b27b613b63cc577e2abd22005efa5367f608e71c67dbc',
};
const vacationInputs = {
    'vacation-app/package.json': '16c91186f725d792d917d3e63c02954fc4e3a7c6aaeb996e3aff211056c80ce7',
    'vacation-app/pnpm-lock.yaml': '66d4ef0315b778d925967563d0ea5a75da8b297b7f7bd5cbc9fab7249b5cb7ad',
    'vacation-app/apps/web/package.json': '3209e36ed461f072d9bc4fbf269afbf9314b88b7c60e4dded7b5f0ed5ceb13f7',
    'vacation-app/apps/web/tailwind.config.ts': '96732f9aaa71986b4c6a9af762ac99323eb6024849cbb1ab4daf1e6122ddd5c6',
};

function verifyInputs(files) {
    for (const [file, sha256] of Object.entries(files)) assert.equal(createHash('sha256').update(readFileSync(resolve(root, file))).digest('hex'), sha256, `Unreviewed input: ${file}`);
}

function validateCounts(findings, counts, withTotal) {
    const severities = ['info', 'low', 'moderate', 'high', 'critical'];
    assert.ok(findings.every((finding) => severities.includes(finding.severity)), 'Unknown severity');
    for (const severity of severities) {
        assert.ok(Number.isInteger(counts?.[severity]) && counts[severity] >= 0, `Invalid ${severity} count`);
        assert.equal(counts[severity], findings.filter((finding) => finding.severity === severity).length, `Incomplete ${severity} findings`);
    }
    if (withTotal) assert.equal(counts.total, findings.length, 'Invalid total count');
}

export function classifyVacationBuildAdvisory(report, auditExit, now = Date.now()) {
    assert.ok(auditExit === 0 || auditExit === 1, 'Audit command did not complete normally');
    assert.ok(!report.error && !report.auditReportVersion && Array.isArray(report.actions), 'Unsupported or failed pnpm report');
    assert.ok(report.advisories && typeof report.advisories === 'object' && !Array.isArray(report.advisories), 'Missing pnpm advisories');
    for (const action of report.actions) {
        assert.ok(action && typeof action === 'object' && !Array.isArray(action)
            && typeof action.module === 'string' && typeof action.action === 'string' && Array.isArray(action.resolves), 'Invalid pnpm action schema');
        for (const resolved of action.resolves) assert.ok(resolved && typeof resolved === 'object' && !Array.isArray(resolved)
            && Number.isInteger(resolved.id) && typeof resolved.path === 'string', 'Invalid pnpm resolve schema');
    }
    const findings = Object.values(report.advisories);
    validateCounts(findings, report.metadata?.vulnerabilities, false);
    for (const [id, finding] of Object.entries(report.advisories)) assert.ok(Number.isInteger(finding.id) && String(finding.id) === id, 'Invalid pnpm advisory ID');
    const blocking = findings.filter((finding) => ['high', 'critical'].includes(finding.severity));
    // Pinned pnpm 9.12.0 JSON mode exits 1 for any severity, before audit-level
    // filtering. Validate that status separately from the HIGH/CRITICAL gate.
    // https://github.com/pnpm/pnpm/blob/v9.12.0/lockfile/plugin-commands-audit/src/audit.ts#L204-L226
    assert.equal(auditExit, findings.length ? 1 : 0, 'Audit exit contradicts its findings');
    if (blocking.length === 0) return 'No HIGH or CRITICAL findings';
    assert.ok(Number.isFinite(now) && now < expires, 'Build advisory classification expired');
    verifyInputs(vacationInputs);
    const finding = blocking[0];
    assert.ok(blocking.length === 1 && finding.id === 1240992 && finding.github_advisory_id === 'GHSA-vfj7-8cjw-p6xm'
        && finding.module_name === 'braces' && finding.severity === 'high' && finding.vulnerable_versions === '<=3.0.3', 'Unclassified pnpm HIGH or CRITICAL finding');
    assert.equal(finding.findings?.length, 1, 'Unreviewed pnpm findings');
    assert.equal(finding.findings[0].version, '3.0.3', 'Unreviewed braces version');
    const paths = finding.findings[0].paths;
    assert.ok(Array.isArray(paths), 'Unreviewed pnpm paths');
    // Exact routes from the pinned lock for the two Web build dependencies.
    const prefixes = ['apps/web > tailwindcss@3.4.19', 'apps/web > tailwindcss-animate@1.0.7 > tailwindcss@3.4.19'];
    const routes = ['chokidar@3.6.0 > braces@3.0.3', 'fast-glob@3.3.3 > micromatch@4.0.8 > braces@3.0.3', 'micromatch@4.0.8 > braces@3.0.3'];
    if (paths.length) assert.deepEqual([...paths].sort(), prefixes.flatMap((prefix) => routes.map((route) => `${prefix} > ${route}`)).sort(), 'Unreviewed pnpm paths');
    const actions = report.actions.filter((action) => Array.isArray(action.resolves)
        && action.resolves.some((resolved) => String(resolved.id) === String(finding.id)));
    assert.equal(actions.length, 1, 'Unreviewed pnpm actions');
    assert.equal(actions[0].module, 'braces', 'Unreviewed pnpm action module');
    assert.equal(actions[0].action, 'review', 'Unreviewed pnpm action');
    assert.equal(actions[0].resolves?.length, 1, 'Unreviewed pnpm dependency graph');
    assert.equal(actions[0].resolves[0].id, finding.id, 'Unreviewed pnpm action ID');
    assert.equal(actions[0].resolves[0].path, 'apps__web>tailwindcss>chokidar>braces', 'Unreviewed pnpm dependency graph');
    // pnpm 9's audit API flattens dev flags. Classification comes from these
    // exact manifest and full lock bytes, not its reported dev:false value.
    const web = JSON.parse(readFileSync(resolve(root, 'vacation-app/apps/web/package.json'), 'utf8'));
    assert.equal(web.dependencies?.['tailwindcss-animate'], undefined, 'Production peer remains');
    assert.equal(web.devDependencies?.['tailwindcss-animate'], '^1.0.7', 'Unreviewed build plugin');
    return `VISIBLE BUILD ADVISORY: ${advisory}; braces 3.0.3; exact vacation lock/build inputs; expires 2026-11-12T00:00:00Z; assembled Vacation runtime remains unqualified`;
}

export function classifyBuildAdvisory(report, scope, auditExit, now = Date.now()) {
    assert.ok(Object.hasOwn(inputs, scope), 'Unreviewed audit scope');
    assert.ok(auditExit === 0 || auditExit === 1, 'Audit command did not complete normally');
    assert.ok(!report.error && report.auditReportVersion === 2, 'Audit failed or returned an unsupported report');
    const findings = report.vulnerabilities;
    assert.ok(findings && typeof findings === 'object' && !Array.isArray(findings), 'Missing audit findings');
    validateCounts(Object.values(findings), report.metadata?.vulnerabilities, true);
    const blocking = Object.entries(findings).filter(([, finding]) => ['high', 'critical'].includes(finding.severity));
    assert.equal(auditExit, blocking.length ? 1 : 0, 'Audit exit contradicts its threshold findings');
    if (blocking.length === 0) return 'No HIGH or CRITICAL findings';
    assert.ok(Number.isFinite(now) && now < expires, 'Build advisory classification expired');
    verifyInputs({ ...inputs[scope], ...runtimeInputs });
    const lock = JSON.parse(readFileSync(resolve(root, scope, 'package-lock.json'), 'utf8'));
    assert.equal(lock.packages?.['node_modules/braces']?.version, '3.0.3', 'Unreviewed braces version');

    const edges = { braces: [], chokidar: ['braces'], 'fast-glob': ['micromatch'], micromatch: ['braces'], tailwindcss: ['chokidar', 'fast-glob', 'micromatch'] };
    assert.deepEqual(blocking.map(([name]) => name).sort(), Object.keys(edges).sort(), 'Unclassified HIGH or CRITICAL graph');
    function isReviewedFinding(name) {
        const finding = findings[name];
        const node = `node_modules/${name}`;
        if (!finding || finding.name !== name || finding.severity !== 'high') return false;
        if (!Array.isArray(finding.nodes) || finding.nodes.length !== 1 || finding.nodes[0] !== node || lock.packages[node]?.dev !== true) return false;
        if (!Array.isArray(finding.via) || finding.via.length === 0) return false;
        if (name === 'braces') return finding.via.length === 1 && finding.via[0].name === 'braces' && finding.via[0].dependency === 'braces'
            && finding.via[0].url === advisory && finding.via[0].severity === 'high' && finding.via[0].range === '<=3.0.3';
        return finding.via.length === edges[name].length && edges[name].every((dependency) => finding.via.includes(dependency)
            && typeof lock.packages[node]?.dependencies?.[dependency] === 'string' && findings[dependency]?.severity === 'high');
    }

    for (const [name] of blocking) assert.ok(isReviewedFinding(name), `Unclassified HIGH or CRITICAL finding: ${name}`);
    return `VISIBLE BUILD ADVISORY: ${advisory}; braces 3.0.3; exact ${scope} inputs; expires 2026-11-12T00:00:00Z; ${blocking.length} transitive HIGH findings classified`;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const [scope, reportFile, auditExit] = process.argv.slice(2);
        assert.equal(process.argv.length, 5, 'Usage: classify-build-advisory.mjs SCOPE REPORT AUDIT_EXIT');
        const raw = readFileSync(reportFile, 'utf8');
        process.stdout.write(raw.endsWith('\n') ? raw : `${raw}\n`);
        assert.match(auditExit, /^[01]$/, 'Invalid audit command exit');
        const report = JSON.parse(raw);
        process.stdout.write(`${scope === 'vacation-app' ? classifyVacationBuildAdvisory(report, Number(auditExit)) : classifyBuildAdvisory(report, scope, Number(auditExit))}\n`);
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
