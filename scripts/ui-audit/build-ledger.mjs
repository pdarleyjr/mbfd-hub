#!/usr/bin/env node
// Builds the UI coverage ledger from the route catalog, `route:list --json`, and persona crawl output.
// Structural facts only: no personnel, incident, or operational data is read or written.
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const [routeListPath, crawlDir = 'test-results/ui-audit-artifacts'] = process.argv.slice(2);
if (!routeListPath) {
  console.error('Usage: node scripts/ui-audit/build-ledger.mjs <route-list.json> [crawl-artifact-dir]');
  process.exit(2);
}

const catalog = JSON.parse(readFileSync('tests/e2e/support/protected-ui-inventory.json', 'utf8')).pages;
const routes = JSON.parse(readFileSync(routeListPath, 'utf8').replace(/^\uFEFF/, ''));
const crawls = existsSync(crawlDir)
  ? readdirSync(crawlDir).filter(f => /^crawl-.+\.json$/.test(f)).map(f => JSON.parse(readFileSync(resolve(crawlDir, f), 'utf8')))
  : [];

const authFrozen = /^\/(login|logout|forgot-password|reset-password|member-onboarding|account\/city-email|city-email|oauth|auth|set-password)|\/set-password$|\/login$/;
const dailyFrozen = /^\/daily(\/|$)/;

function moduleFor(route) {
  if (dailyFrozen.test(route)) return 'daily';
  if (authFrozen.test(route)) return 'auth-identity';
  if (route === '/' ) return 'home';
  if (/^\/admin\/(operational-forms|forms)|^\/employee\/forms/.test(route)) return 'forms-video';
  if (/video-conferencing/.test(route)) return 'forms-video';
  if (route.startsWith('/admin')) return 'admin';
  if (route.startsWith('/employee')) return 'employee';
  if (/^\/workgroups?(\/|$)|^\/workgroup-export/.test(route)) return 'workgroups';
  if (route.startsWith('/training')) return 'training';
  return 'remaining';
}

function archetypeFor(route, name) {
  if (route === '/') return '1 Launcher';
  if (authFrozen.test(route)) return '11 Simple centered form';
  if (/^List/.test(name ?? '')) return '4 Index';
  if (/^(Create|Edit)/.test(name ?? '')) return '6 Create/edit';
  if (/^View/.test(name ?? '')) return '5 Record detail';
  if (/^\/(admin|employee|workgroups|training)$/.test(route) || /Dashboard/.test(name ?? '')) return '2 Operational dashboard';
  if (/report|summary|presentation|results|analysis|recommendations|inventory$|pulse|usage/i.test(route)) return '9 Analytics/report';
  if (/video-conferencing\/command|lineup/.test(route)) return '10 Real-time console';
  if (/\/(create|edit)$|request|compose|intake/.test(route)) return '6 Create/edit';
  if (/settings|profile|account|notification/i.test(route)) return '8 Settings';
  if (/\{[^}]+\}$|:\w+$/.test(route)) return '5 Record detail';
  if (/s$/.test(route)) return '4 Index';
  return '3 Module home';
}

function matcher(route) {
  return new RegExp(`^${route.split('/').map(s => /^\{.+\}$|^:\w+$/.test(s) ? '[^/]+' : s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('/')}/?$`);
}

const routeByUri = new Map(routes.map(r => ['/' + String(r.uri).replace(/^\//, ''), r]));
const rows = catalog.map((entry, index) => {
  const laravel = routeByUri.get(entry.route) ?? routeByUri.get(entry.route.replace(/\/$/, ''));
  const re = matcher(entry.route);
  const visits = crawls.flatMap(c => c.pages.filter(p => typeof p.finalPath === 'string' && re.test(p.finalPath) && !p.redirected).map(p => ({ persona: c.persona, ...p })));
  const first = visits[0];
  const module = moduleFor(entry.route);
  const frozen = module === 'auth-identity' ? 'FROZEN: auth/identity hard freeze' : module === 'daily' ? 'FROZEN: Daily owned by another workstream' : null;
  return {
    id: `UI-${String(index + 1).padStart(3, '0')}`,
    surfaceType: entry.panel ? `filament:${entry.panel}` : dailyFrozen.test(entry.route) ? 'react:daily' : 'blade',
    route: entry.route,
    routeName: entry.route_name ?? laravel?.name ?? null,
    name: entry.name,
    panel: entry.panel ?? null,
    parent: entry.parent,
    middleware: laravel?.middleware ?? null,
    archetype: archetypeFor(entry.route, entry.name),
    migrationModule: module,
    catalogScope: entry.scope,
    frozen,
    securitySensitive: module === 'auth-identity',
    rolesReached: [...new Set(visits.map(v => v.persona))].sort(),
    title: first?.title ?? null,
    h1: first?.h1 ?? null,
    titleConforms: typeof first?.title === 'string' ? /^.+ · .+ · MBFD Hub$/.test(first.title) : null,
    singleH1: Array.isArray(first?.h1) ? first.h1.length === 1 : null,
    overflowWidths: first ? Object.keys(first.overflow ?? {}).map(Number) : null,
    axeSeriousCritical: first ? (first.axe ?? []).filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id) : null,
    consoleErrors: first ? (first.consoleErrors ?? []).length : null,
    unnamedControls: first?.unnamedControls ?? null,
    primaryAction: 'pending-review',
    statesPresent: 'pending-review',
    status: frozen ? 'blocked' : 'not started',
    coverage: visits.length ? 'visited' : 'not visited',
    evidenceRef: visits.length ? `crawl-${visits[0].persona}.json` : null,
  };
});

const out = resolve('docs/ui-modernization');
mkdirSync(out, { recursive: true });
const summary = {
  generatedFrom: { catalogBaseline: JSON.parse(readFileSync('tests/e2e/support/protected-ui-inventory.json', 'utf8')).baseline, routeCount: routes.length, personasCrawled: crawls.map(c => c.persona).sort() },
  rows: rows.length,
  visited: rows.filter(r => r.coverage === 'visited').length,
  blocked: rows.filter(r => r.status === 'blocked').length,
  byModule: Object.fromEntries([...new Set(rows.map(r => r.migrationModule))].sort().map(m => [m, rows.filter(r => r.migrationModule === m).length])),
};
writeFileSync(resolve(out, 'coverage-ledger.json'), JSON.stringify({ summary, rows }, null, 2) + '\n');

const cell = v => v === null || v === undefined ? '' : Array.isArray(v) ? v.join(', ') : String(v).replace(/\|/g, '\\|');
const header = ['id', 'route', 'name', 'panel', 'archetype', 'module', 'roles reached', 'title ok', '1×h1', 'overflow px widths', 'axe serious/critical', 'status'];
const md = [
  '# UI Modernization Coverage Ledger',
  '',
  'Generated by `scripts/ui-audit/build-ledger.mjs`. Structural facts only; no personnel or operational data.',
  '',
  `Rows: ${summary.rows} · visited: ${summary.visited} · blocked (frozen): ${summary.blocked} · personas crawled: ${summary.generatedFrom.personasCrawled.join(', ') || 'none yet'}`,
  '',
  '| ' + header.join(' | ') + ' |',
  '|' + header.map(() => '---').join('|') + '|',
  ...rows.map(r => '| ' + [r.id, '`' + r.route + '`', r.name, r.panel, r.archetype, r.migrationModule, r.rolesReached, r.titleConforms, r.singleH1, r.overflowWidths, r.axeSeriousCritical, r.frozen ?? r.status].map(cell).join(' | ') + ' |'),
  '',
].join('\n');
writeFileSync(resolve(out, 'COVERAGE_LEDGER.md'), md);
console.log(JSON.stringify(summary, null, 2));
