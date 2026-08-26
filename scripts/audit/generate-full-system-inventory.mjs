import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, readFile, readdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';

const auditDate = process.env.MBFD_AUDIT_DATE ?? '2026-08-25';
const root = process.cwd();
const auditDirectory = path.join(root, 'docs', 'audits');
const outputStem = `MBFD_FULL_SYSTEM_AUDIT_${auditDate}`;
const gitExecutable = process.env.GIT_EXECUTABLE
    ?? (existsSync('C:/Program Files/Git/cmd/git.exe') ? 'C:/Program Files/Git/cmd/git.exe' : 'git');
const sourceFindings = [
    {
        id: 'MBFD-AUDIT-001',
        severity: 'P0',
        status: 'open',
        evidenceType: 'static-source',
        title: 'Daily checklist source path is not the tracked deployment path and can yield an empty checkout.',
        sources: [
            'app/Http/Controllers/Api/ApparatusController.php:72-83',
            'storage/checklists/*.json',
            '.github/workflows/deploy.yml:165-206',
            'resources/js/daily-checkout/src/utils/api.ts:55-76',
        ],
    },
    {
        id: 'MBFD-AUDIT-002',
        severity: 'P0',
        status: 'open',
        evidenceType: 'static-source',
        title: 'Daily checkout submissions have no durable idempotency key, so acknowledgement loss can create duplicates.',
        sources: [
            'app/Http/Controllers/Api/ApparatusController.php:100-160',
            'resources/js/daily-checkout/src/components/InspectionWizard.tsx:99-194',
            'resources/js/daily-checkout/src/utils/storage.ts:51-72',
        ],
    },
    {
        id: 'MBFD-AUDIT-003',
        severity: 'P0',
        status: 'open',
        evidenceType: 'static-source',
        title: 'No canonical Daily check-required apparatus rule is represented in the tracked application model.',
        sources: [
            'app/Http/Controllers/Api/ApparatusController.php:26-37',
            'resources/js/daily-checkout/src/components/VehicleInspectionSelect.tsx:27-160',
            'app/Models/Apparatus.php:15-61',
        ],
    },
    {
        id: 'MBFD-AUDIT-004',
        severity: 'P0',
        status: 'open',
        evidenceType: 'static-source',
        title: 'Command Display computes daily readiness from event counts and a static complement, allowing false completion.',
        sources: [
            'app/Services/Display/DisplaySnapshotService.php:164-176,772-801',
            'app/Services/Display/DisplayReadiness.php:72-80',
            'app/Services/StationStaffingService.php:62-166',
        ],
    },
];
const ignoredDirectories = new Set([
    '.git',
    'node_modules',
    'vendor',
    'public/build',
    'test-results',
    'playwright-report',
]);

function command(...args) {
    try {
        return execFileSync(args[0], args.slice(1), {
            cwd: root,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'ignore'],
        }).trim();
    } catch {
        return null;
    }
}

async function exists(relativePath) {
    try {
        await stat(path.join(root, relativePath));
        return true;
    } catch {
        return false;
    }
}

async function walk(relativeDirectory = '') {
    const absoluteDirectory = path.join(root, relativeDirectory);
    const entries = await readdir(absoluteDirectory, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const relativePath = path.join(relativeDirectory, entry.name);
        const normalizedPath = relativePath.split(path.sep).join('/');

        if (entry.isDirectory()) {
            if (!ignoredDirectories.has(normalizedPath) && !ignoredDirectories.has(entry.name)) {
                files.push(...await walk(relativePath));
            }
        } else if (entry.isFile()) {
            files.push(normalizedPath);
        }
    }

    return files;
}

async function lines(relativePath) {
    return (await readFile(path.join(root, relativePath), 'utf8')).split(/\r?\n/);
}

function lineMatches(sourceLines, expression, transform) {
    const matches = [];

    sourceLines.forEach((line, index) => {
        expression.lastIndex = 0;
        let match;

        while ((match = expression.exec(line)) !== null) {
            matches.push(transform(match, index + 1, line));
        }
    });

    return matches;
}

async function jsonManifest(relativePath) {
    if (!await exists(relativePath)) {
        return null;
    }

    try {
        const manifest = JSON.parse(await readFile(path.join(root, relativePath), 'utf8'));

        return {
            path: relativePath,
            name: manifest.name ?? null,
            version: manifest.version ?? null,
            private: manifest.private ?? null,
            scripts: Object.keys(manifest.scripts ?? {}).sort(),
            dependencies: Object.keys(manifest.dependencies ?? {}).sort(),
            devDependencies: Object.keys(manifest.devDependencies ?? {}).sort(),
            require: manifest.require ?? null,
            requireDev: manifest['require-dev'] ?? null,
        };
    } catch (error) {
        return { path: relativePath, parseError: error.message };
    }
}

async function inventoryRoutes(routeFiles) {
    const routeEntries = [];
    const declaration = /\bRoute::(get|post|put|patch|delete|options|any|match|resource|apiResource|view|redirect)\s*\(\s*['\"]([^'\"]*)['\"]/g;

    for (const file of routeFiles) {
        const sourceLines = await lines(file);
        routeEntries.push(...lineMatches(sourceLines, declaration, (match, line) => ({
            source: file,
            line,
            declaration: match[1],
            uri: match[2],
        })));
    }

    return routeEntries;
}

async function inventoryDailyRoutes() {
    const source = 'resources/js/daily-checkout/src/App.tsx';

    if (!await exists(source)) {
        return [];
    }

    const sourceLines = await lines(source);
    return lineMatches(sourceLines, /<Route\s+path=["']([^"']+)["']/g, (match, line) => ({
        source,
        line,
        path: match[1],
    }));
}

async function inventoryApiClientEndpoints() {
    const source = 'resources/js/daily-checkout/src/utils/api.ts';

    if (!await exists(source)) {
        return [];
    }

    const sourceLines = await lines(source);
    return lineMatches(sourceLines, /\$\{API_BASE\}([^`'\"]*)/g, (match, line) => ({
        source,
        line,
        endpoint: match[1],
    }));
}

async function inventoryKeywords(allFiles) {
    const integrations = {
        'Cloudflare': /cloudflare|wrangler|access/i,
        'Command Display': /command[- ]display|display[_-]token|api\/display/i,
        'Google Sheets': /google.*sheet|sheet.*google/i,
        'LiveKit': /livekit/i,
        'Media Control': /media[- ]control/i,
        'PeerTube': /peertube/i,
        'Redis': /redis/i,
        'Reverb': /reverb/i,
        'Sentry': /sentry/i,
        'Snipe-IT': /snipe[- ]?it/i,
        'Vacation': /vacation/i,
    };
    const results = {};
    const candidateFiles = allFiles.filter((file) => /^(app|config|routes|resources|scripts|docker|infra|\.github)\//.test(file)
        && /\.(php|js|jsx|ts|tsx|mjs|json|ya?ml|md)$/i.test(file));

    for (const [name, expression] of Object.entries(integrations)) {
        const references = [];

        for (const file of candidateFiles) {
            const sourceLines = await lines(file);
            sourceLines.forEach((line, index) => {
                if (expression.test(line) && references.length < 100) {
                    references.push({ source: file, line: index + 1, excerpt: line.trim().slice(0, 240) });
                }
            });
        }

        results[name] = references;
    }

    return results;
}

function summaryTable(rows) {
    return rows.map(([label, value]) => `| ${label} | ${value} |`).join('\n');
}

async function main() {
    const allFiles = await walk();
    const routeFiles = allFiles.filter((file) => file.startsWith('routes/') && file.endsWith('.php'));
    const manifests = (await Promise.all([
        jsonManifest('composer.json'),
        jsonManifest('package.json'),
        jsonManifest('resources/js/daily-checkout/package.json'),
        jsonManifest('cloudflare-worker/package.json'),
        jsonManifest('vacation-app/package.json'),
    ])).filter(Boolean);
    const staticRoutes = await inventoryRoutes(routeFiles);
    const dailyRoutes = await inventoryDailyRoutes();
    const dailyApiEndpoints = await inventoryApiClientEndpoints();
    const integrations = await inventoryKeywords(allFiles);
    const find = (prefix, suffix = '') => allFiles.filter((file) => file.startsWith(prefix) && file.endsWith(suffix));
    const sourceCounts = {
        files: allFiles.length,
        routeFiles: routeFiles.length,
        staticRouteDeclarations: staticRoutes.length,
        dailySpaRoutes: dailyRoutes.length,
        dailyApiClientEndpoints: dailyApiEndpoints.length,
        controllers: find('app/Http/Controllers/', '.php').length,
        models: find('app/Models/', '.php').length,
        migrations: find('database/migrations/', '.php').length,
        filamentResources: find('app/Filament/Resources/', '.php').length,
        filamentPages: find('app/Filament/Pages/', '.php').length,
        featureTests: find('tests/Feature/', '.php').length,
        e2eSpecs: allFiles.filter((file) => file.startsWith('tests/e2e/') && /\.(ts|js)$/.test(file)).length,
    };
    const inventory = {
        schemaVersion: 1,
        generatedAt: new Date().toISOString(),
        inventoryType: 'static-source-inventory',
        limitations: [
            'Laravel route groups and dynamic routes require php artisan route:list once dependencies and a test configuration are available.',
            'This source inventory does not establish production availability, authorization behavior, browser behavior, or external integration health.',
            'No secret values are included.',
        ],
        workspace: {
            root,
            branch: command(gitExecutable, 'branch', '--show-current'),
            commit: command(gitExecutable, 'rev-parse', 'HEAD'),
            status: command(gitExecutable, 'status', '--short')?.split(/\r?\n/).filter(Boolean) ?? [],
        },
        sourceCounts,
        manifests,
        routeFiles,
        staticRoutes,
        daily: {
            routes: dailyRoutes,
            apiClientEndpoints: dailyApiEndpoints,
        },
        findings: sourceFindings,
        integrations,
        relatedRepositoryEvidence: Object.fromEntries(
            Object.entries(integrations).map(([name, references]) => [name, references.map(({ source, line }) => ({ source, line }))]),
        ),
    };

    await mkdir(auditDirectory, { recursive: true });
    await writeFile(path.join(auditDirectory, `${outputStem}.json`), `${JSON.stringify(inventory, null, 2)}\n`);

    const markdown = `# MBFD Full System Audit — In Progress\n\n`
        + `**Audit date:** ${auditDate}  \n`
        + `**Generated source inventory:** [${outputStem}.json](${outputStem}.json)  \n`
        + `**Workspace:** \`${inventory.workspace.root}\`  \n`
        + `**Branch / SHA:** \`${inventory.workspace.branch}\` / \`${inventory.workspace.commit}\`\n\n`
        + `## Scope and evidence status\n\n`
        + `This report begins with a generated static-source inventory. Runtime, production, browser, database, and physical acceptance are separate gates; none are represented as a pass until directly observed. Secret values are deliberately excluded.\n\n`
        + `## Programmatic inventory summary\n\n`
        + `| Surface | Count |\n|---|---:|\n`
        + summaryTable([
            ['Source files scanned', sourceCounts.files],
            ['Laravel route files', sourceCounts.routeFiles],
            ['Static route declarations', sourceCounts.staticRouteDeclarations],
            ['Daily SPA routes', sourceCounts.dailySpaRoutes],
            ['Daily API-client endpoints', sourceCounts.dailyApiClientEndpoints],
            ['Controllers', sourceCounts.controllers],
            ['Models', sourceCounts.models],
            ['Migrations', sourceCounts.migrations],
            ['Filament resource PHP files', sourceCounts.filamentResources],
            ['Filament page PHP files', sourceCounts.filamentPages],
            ['Feature tests', sourceCounts.featureTests],
            ['E2E specs', sourceCounts.e2eSpecs],
        ])
        + `\n\n## Runtime gate\n\n`
        + `- **BLOCKED (local setup):** Laravel runtime commands require generated Composer autoload files. The isolated worktree intentionally started without \`vendor/\` or \`.env\`; the locked dependency installation is being diagnosed separately rather than counted as an application pass or fail.\n`
        + `- **Pending:** \`php artisan route:list\`, \`migrate:status\`, database integrity, browser flows, external integrations, production reconnaissance, and physical acceptance.\n\n`
        + `## Static route declarations\n\n`
        + `The full declaration list, source locations, Daily route list, API-client endpoints, manifests, and integration references are machine-readable in the linked JSON inventory. Laravel group-derived routes remain pending a runtime route table.\n\n`
        + `## Findings\n\n`
        + `| ID | Severity | Evidence | Status | Finding |\n|---|---|---|---|---|\n`
        + sourceFindings.map((finding) => `| ${finding.id} | ${finding.severity} | ${finding.evidenceType} | ${finding.status} | ${finding.title} |`).join('\n')
        + `\n\n`
        + `## Change log\n\n`
        + `- ${new Date().toISOString()}: generated initial source inventory from the clean audit branch.\n`;

    const markdownPath = path.join(auditDirectory, `${outputStem}.md`);
    if (process.argv.includes('--write-markdown') || !existsSync(markdownPath)) {
        await writeFile(markdownPath, markdown);
    }
    process.stdout.write(`${JSON.stringify({ output: outputStem, sourceCounts, findings: sourceFindings.length }, null, 2)}\n`);
}

await main();
