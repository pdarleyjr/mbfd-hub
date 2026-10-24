import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { extname, join, normalize, resolve, sep } from 'node:path';

/**
 * A tiny stand-in for the production Hub origin, for service-worker upgrade tests.
 *
 * It serves the REAL built Daily app (node_modules/.cache/daily-pwa-upgrade-build, produced by
 * the suite's global setup) at /daily/, the shared images and manifest,
 * a controllable /app-release, and a small member-aware API. What it lets a test do that
 * a plain preview server cannot is change, at will and while the browser keeps its
 * registration and IndexedDB: which worker bytes /daily/sw.js and /daily/service-worker.js
 * answer, which release the shell and /app-release report, which member is signed in, and
 * which requests fail.
 *
 * Worker generations:
 *  - 'live-v10'     bytes captured from https://www.mbfdhub.com/daily/sw.js on 2026-10-23
 *                   (the Daily worker members have installed today), precache list rebuilt
 *                   for this build's asset names.
 *  - 'main-v11'     the Daily worker template on main (fc35bb83, Captain 5 merged) built the
 *                   same way the Vite plugin builds it.
 *  - 'current'      the worker this branch builds (dist/sw.js).
 */
export type WorkerGeneration = 'live-v10' | 'main-v11' | 'current';

export interface Member {
  id: number;
  label: string;
}

export interface OriginState {
  worker: WorkerGeneration;
  /** Release written into the shell's <meta name="mbfd-release"> and answered by /app-release. */
  release: string;
  /** Signed-in member, or null for a lapsed session (401 from /api/me/context). */
  member: Member | null;
  /** 'ok' accepts inspection POSTs; 'fail' answers 503 so queued work stays queued. */
  submitMode: 'ok' | 'fail';
  /** Asset paths (as /daily/assets/x.js) that answer 404, like chunks from a previous deploy. */
  goneAssets: Set<string>;
  /** Asset paths left out of the 'current' worker's precache list (they changed hash in this deploy). */
  omitFromPrecache: Set<string>;
  /** When true the worker script requests are dropped, like a network that vanished mid-update. */
  dropWorkerScripts: boolean;
  /** When true any /daily/assets/* request answers 500, so a worker install (precache) fails. */
  failAssets: boolean;
  /**
   * When set, a signed-in request for the app is redirected there instead of rendering the shell, like
   * forced password change, city e-mail review or the canonical-host redirect (none of which is sign-out).
   */
  redirectDaily: string | null;
}

export interface DailyOrigin {
  url: string;
  state: OriginState;
  /** Inspection bodies the server accepted. */
  submissions: Array<Record<string, unknown>>;
  /** Count of requests per path. */
  hits: Map<string, number>;
  assets(): string[];
  /** Asset file name of the first dist chunk matching a prefix, e.g. 'FormsHub' -> '/daily/assets/FormsHub-xxxx.js'. */
  chunk(prefix: string): string;
  close(): Promise<void>;
}

const root = process.cwd();
/** Where the Daily app is built for this suite (tests/e2e/daily-pwa-upgrade.setup.ts), relative to the repository root. */
// Not under test-results/: every Playwright run in this repository empties that directory when it starts,
// which would delete the build of a suite that is running at the same time.
export const DAILY_PWA_DIST_RELATIVE = 'node_modules/.cache/daily-pwa-upgrade-build';
export const DIST = resolve(root, DAILY_PWA_DIST_RELATIVE);
const PUBLIC = resolve(root, 'public');

export const CHECKLIST_VERSION = 'a'.repeat(64);
export const FIXTURE_SLUG = 'offline-fixture';

const TYPES: Record<string, string> = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.webmanifest': 'application/manifest+json',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
  '.txt': 'text/plain',
};

export function builtAssets(): string[] {
  return readdirSync(join(DIST, 'assets'))
    .filter((file) => /\.(js|css|png)$/.test(file))
    .sort()
    .map((file) => `/daily/assets/${file}`);
}

/** Same substitution the Vite plugin makes, applied to a worker source. */
function withPrecacheList(source: string, assets: string[]): string {
  if (source.includes('/* DAILY_BUILD_ASSETS */ []')) {
    return source.replace('/* DAILY_BUILD_ASSETS */ []', JSON.stringify(assets));
  }

  // A worker already built for production: swap its list for this build's assets.
  const listPattern = /const BUILD_ASSETS = \[[^\]]*\];/;
  if (!listPattern.test(source)) throw new Error('Could not find the precache list in the worker source');

  return source.replace(listPattern, () => `const BUILD_ASSETS = ${JSON.stringify(assets)};`);
}

export function workerSource(generation: WorkerGeneration, omit: Set<string> = new Set()): string {
  const assets = builtAssets().filter((asset) => !omit.has(asset));
  switch (generation) {
    case 'live-v10':
      return withPrecacheList(readFileSync(resolve(root, 'tests/e2e/fixtures/production-daily-sw-2026-10-23.js'), 'utf8'), assets);
    case 'main-v11':
      return withPrecacheList(readFileSync(resolve(root, 'tests/e2e/fixtures/daily-sw-template-fc35bb83.js'), 'utf8'), assets);
    default:
      // dist/sw.js carries the full list; re-apply the list so omissions take effect.
      return withPrecacheList(readFileSync(join(DIST, 'sw.js'), 'utf8'), assets);
  }
}

const apparatus = (member: Member) => ({
  id: 101,
  name: `Offline Fixture Apparatus (${member.label})`,
  slug: FIXTURE_SLUG,
  designation: 'TEST',
  type: 'rescue',
  vehicle_number: 'TEST-101',
  status: 'In Service',
});

function sendJson(response: ServerResponse, status: number, body: unknown): void {
  response.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'no-store' });
  response.end(JSON.stringify(body));
}

async function readBody(request: IncomingMessage): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of request) chunks.push(chunk as Buffer);

  return Buffer.concat(chunks).toString('utf8');
}

function safeFile(base: string, relativePath: string): string | null {
  const resolved = normalize(join(base, relativePath));
  if (resolved !== base && !resolved.startsWith(base + sep)) return null;

  return existsSync(resolved) && statSync(resolved).isFile() ? resolved : null;
}

export async function startDailyOrigin(initial: Partial<OriginState> = {}): Promise<DailyOrigin> {
  if (!existsSync(join(DIST, 'index.html'))) {
    throw new Error(`Build the Daily app first (the suite's global setup does): ${DIST}`);
  }
  const state: OriginState = {
    worker: 'current',
    release: 'aaaaaaaaaaaa',
    member: { id: 101, label: 'Member A' },
    submitMode: 'ok',
    goneAssets: new Set(),
    omitFromPrecache: new Set(),
    dropWorkerScripts: false,
    failAssets: false,
    redirectDaily: null,
    ...initial,
  };
  const submissions: Array<Record<string, unknown>> = [];
  const hits = new Map<string, number>();
  const distIndex = readFileSync(join(DIST, 'index.html'), 'utf8');
  let origin = '';

  const shell = () => distIndex.replace(
    '</head>',
    `<meta name="mbfd-release" content="${state.release}"><script>window.__MBFD_CANONICAL_ORIGIN__ = ${JSON.stringify(origin)};</script></head>`,
  );

  const handleApi = async (request: IncomingMessage, response: ServerResponse, path: string): Promise<void> => {
    const method = request.method ?? 'GET';
    const member = state.member;
    if (path === '/api/me/context') {
      if (!member) return sendJson(response, 401, { message: 'Unauthenticated.' });

      return sendJson(response, 200, {
        version: 1,
        identity: { user_id: member.id, has_personnel_profile: true },
        personnel: { employee_profile_id: member.id + 1_000, employee_number: `E${member.id}`, name: member.label, rank: 'Firefighter' },
        offline: { security_version: 1 },
        session: { authenticated: true },
      });
    }
    if (!member) return sendJson(response, 401, { message: 'Unauthenticated.' });

    if (method === 'GET' && path === '/api/public/stations') return sendJson(response, 200, { stations: [], served_to: member.label });
    if (method === 'GET' && path === '/api/public/employees/list') return sendJson(response, 200, []);
    if (method === 'GET' && path === '/api/public/fleet-vehicles') return sendJson(response, 200, { vehicles: [] });
    if (method === 'GET' && path === '/api/public/apparatuses') return sendJson(response, 200, [apparatus(member)]);
    if (method === 'GET' && path === '/api/public/apparatuses/101/checklist') {
      return sendJson(response, 200, {
        checklist_version: CHECKLIST_VERSION,
        checklist: { officerChecklist: [], compartments: [{ id: 'cab', title: 'Cab', items: [{ id: 'radio', name: 'Test radio' }, { id: 'bag', name: 'Test bag' }] }] },
        served_to: member.label,
      });
    }
    if (method === 'POST' && path === '/api/public/apparatuses/101/inspections') {
      const body = JSON.parse((await readBody(request)) || '{}') as Record<string, unknown>;
      if (state.submitMode === 'fail') return sendJson(response, 503, { message: 'Temporarily unavailable.' });
      submissions.push(body);

      return sendJson(response, 201, { success: true, review_status: 'approved' });
    }

    return sendJson(response, 200, { data: [], meta: { total: 0 } });
  };

  const server: Server = createServer((request, response) => {
    const url = new URL(request.url ?? '/', 'http://fixture.invalid');
    const path = url.pathname;
    hits.set(path, (hits.get(path) ?? 0) + 1);

    void (async () => {
      if (path.startsWith('/api/')) return handleApi(request, response, path);
      if (path === '/app-release') {
        return sendJson(response, 200, { release: state.release });
      }
      if (path === '/daily/sw.js' || path === '/daily/service-worker.js') {
        if (state.dropWorkerScripts) {
          request.socket.destroy();

          return;
        }
        response.writeHead(200, { 'content-type': 'text/javascript; charset=UTF-8', 'cache-control': 'no-cache, must-revalidate', 'x-content-type-options': 'nosniff' });
        response.end(workerSource(state.worker, state.omitFromPrecache));

        return;
      }
      if (path === '/login') {
        response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        response.end('<!doctype html><title>Sign in</title><body>Sign in</body>');

        return;
      }
      if (path === '/employee/set-password') {
        response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        response.end('<!doctype html><title>Set a password</title><body>Set a new password</body>');

        return;
      }
      if (path === '/daily/index.html') {
        // Like production's server.php: the real file is handed out raw, without the release meta tag
        // or the runtime config that Laravel injects when it renders /daily/.
        response.writeHead(200, { 'content-type': 'text/html; charset=UTF-8', 'cache-control': 'no-cache' });
        response.end(distIndex);

        return;
      }

      if (path.startsWith('/daily/assets/')) {
        if (state.failAssets) {
          response.writeHead(500).end();

          return;
        }
        if (state.goneAssets.has(path)) {
          response.writeHead(404, { 'content-type': 'text/html' }).end('<!doctype html><title>Not found</title>');

          return;
        }
      }

      const fromDist = path.startsWith('/daily/') && path !== '/daily/index.html' ? safeFile(DIST, path.slice('/daily/'.length)) : null;
      const fromPublic = fromDist ?? safeFile(PUBLIC, path.slice(1));
      if (fromPublic && !path.endsWith('/')) {
        const type = TYPES[extname(fromPublic).toLowerCase()] ?? 'application/octet-stream';
        const immutable = path.startsWith('/daily/assets/');
        response.writeHead(200, { 'content-type': type, 'cache-control': immutable ? 'public, max-age=31536000, immutable' : 'no-cache' });
        response.end(readFileSync(fromPublic));

        return;
      }

      if (path === '/daily' || path.startsWith('/daily/')) {
        if (!state.member) {
          // Like production: a signed-out request for the app is sent to sign in.
          response.writeHead(302, { location: '/login', 'cache-control': 'no-store' }).end();

          return;
        }
        if (state.redirectDaily) {
          response.writeHead(302, { location: state.redirectDaily, 'cache-control': 'no-store' }).end();

          return;
        }
        // A navigation delivers a fresh shell: assets that "disappeared in the deploy" exist again for it,
        // because a fresh shell references the new file names.
        // (Only a page navigation counts (it asks for text/html), not the worker's own background fetch of the shell.)
        if (String(request.headers.accept ?? '').includes('text/html')) state.goneAssets.clear();
        response.writeHead(200, { 'content-type': 'text/html; charset=UTF-8', 'cache-control': 'no-store, no-cache, must-revalidate, max-age=0' });
        response.end(shell());

        return;
      }
      response.writeHead(404, { 'content-type': 'text/plain' }).end('not found');
    })().catch((error) => {
      if (!response.headersSent) response.writeHead(500, { 'content-type': 'text/plain' });
      response.end(String(error));
    });
  });

  await new Promise<void>((done) => server.listen(0, '127.0.0.1', done));
  origin = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;

  return {
    url: origin,
    state,
    submissions,
    hits,
    assets: builtAssets,
    chunk(prefix: string) {
      const match = builtAssets().find((asset) => asset.startsWith(`/daily/assets/${prefix}-`) && asset.endsWith('.js'));
      if (!match) throw new Error(`No built chunk named ${prefix}`);

      return match;
    },
    close: () => new Promise<void>((done) => {
      server.close(() => done());
      server.closeAllConnections();
    }),
  };
}
