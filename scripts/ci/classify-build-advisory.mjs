import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const advisory = 'https://github.com/advisories/GHSA-vfj7-8cjw-p6xm';
const expires = Date.parse('2026-10-10T00:00:00Z');
// Approved under ROOT_BUILD_BRACES_EXCEPTION_20261003T0413Z and the P0 request
// to document existing unrelated advisories using project policy. This covers
// exact locked build graphs until 2026-10-10, not assembled Vacation runtime
// images or emitted browser bundles. Raw reports and all other gates remain.
const inputs = {
    '.': {
        'package.json': 'eed6a2bb56d771a64211f6c7cb23c7a827062c93ef1ec36ef5f3c28e9971bec7',
        'package-lock.json': 'ada5fb4f60eaccbe519538620986a1fc6e095c545c009d13304df580797b7f40',
    },
    'resources/js/daily-checkout': {
        'resources/js/daily-checkout/package.json': 'da5514ab8f3302816fab2b29a02a9e1f7a7c67d6c4a0bdcef66b6fe6b4f9f5e8',
        'resources/js/daily-checkout/package-lock.json': '9607b49199b181d5ed059f10839e0b1b5d529e82cdf00752c7e70136d993f0a4',
    },
};
const runtimeInputs = {
    'docker/production/Dockerfile': 'ed5a4d865fa96f674b54129db7aefb066341df0c1db9c79225eafb72b0108c8c',
    '.dockerignore': 'c360145b9617b149ed9b27b613b63cc577e2abd22005efa5367f608e71c67dbc',
};
const vacationInputs = {
    'vacation-app/package.json': 'd61754c0c36d0b84845861424ff69fe7724a979527f43cbad45379e4cf2cbf51',
    'vacation-app/pnpm-lock.yaml': 'c5c0d2eff1cc56f670525ad8565206f35c8d569a61e291a3c44568598b0343fc',
    'vacation-app/apps/web/package.json': '175fe663476f4d9dc88c075509d851d4de68947bc3570086074c37b73a674a24',
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
    assert.equal(auditExit, blocking.length ? 1 : 0, 'Audit exit contradicts its threshold findings');
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
    return `VISIBLE BUILD ADVISORY: ${advisory}; braces 3.0.3; exact vacation lock/build inputs; expires 2026-10-10T00:00:00Z; assembled Vacation runtime remains unqualified`;
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
    return `VISIBLE BUILD ADVISORY: ${advisory}; braces 3.0.3; exact ${scope} inputs; expires 2026-10-10T00:00:00Z; ${blocking.length} transitive HIGH findings classified`;
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
