// Browser contract harness: serves the actual built supply kiosk at /supply-kiosk; every test mocks the API with page.route.
// Build first (npx vite build or npm run build) so public/build/manifest.json lists resources/js/supply-kiosk/main.tsx.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, resolve, sep } from 'node:path';

const port = Number(process.env.SUPPLY_KIOSK_E2E_PORT ?? 4196);
const publicRoot = resolve('public');
const manifest = JSON.parse(await readFile(resolve(publicRoot, 'build/manifest.json'), 'utf8'));
const entry = manifest['resources/js/supply-kiosk/main.tsx'];
if (!entry) throw new Error('Build the supply kiosk (vite build) before running its browser tests.');
const css = new Set(entry.css ?? []);
for (const name of entry.imports ?? []) for (const file of manifest[name]?.css ?? []) css.add(file);
const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="csrf-token" content="synthetic-meta-csrf"><meta name="theme-color" content="#012766"><title>Synthetic supply kiosk browser test</title>${[...css].map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}<script type="module" src="/build/${entry.file}"></script></head><body><div id="supply-kiosk-root"></div></body></html>`;
const types = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.woff2': 'font/woff2', '.woff': 'font/woff' };

createServer(async (req, res) => {
    const path = new URL(req.url ?? '/', 'http://127.0.0.1').pathname;
    res.setHeader('Cache-Control', 'no-store');
    if (path === '/supply-kiosk' || path === '/supply-kiosk/') {
        res.setHeader('Content-Type', 'text/html');
        res.end(html);
        return;
    }
    const file = resolve(publicRoot, `.${path}`);
    if (!file.startsWith(publicRoot + sep) || !/^\/(?:build|images|css)\//.test(path)) { res.writeHead(404); res.end(); return; }
    try {
        res.setHeader('Content-Type', types[extname(file)] ?? 'application/octet-stream');
        res.end(await readFile(file));
    } catch {
        res.writeHead(404);
        res.end();
    }
}).listen(port, '127.0.0.1');
