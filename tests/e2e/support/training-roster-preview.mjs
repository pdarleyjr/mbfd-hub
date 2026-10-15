// Browser contract harness: serves the actual built kiosk, with test-owned API fixtures.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { resolve, extname, sep } from 'node:path';

const publicRoot = resolve('public');
const manifest = JSON.parse(await readFile(resolve(publicRoot, 'build/manifest.json'), 'utf8'));
const entry = manifest['resources/js/training-roster/main.tsx'];
if (!entry) throw new Error('Build the kiosk before running browser tests.');
const css = new Set(entry.css ?? []);
for (const name of entry.imports ?? []) for (const file of manifest[name]?.css ?? []) css.add(file);
const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="synthetic-csrf"><title>Synthetic kiosk browser test</title>${[...css].map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')}<script type="module" src="/build/${entry.file}"></script></head><body><div id="training-roster-root"></div></body></html>`;
const types = { '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.woff2': 'font/woff2', '.woff': 'font/woff' };
createServer(async (req, res) => {
    const path = new URL(req.url, 'http://127.0.0.1').pathname;
    res.setHeader('Cache-Control', 'no-store');
    if (path === '/' || /^\/r\/[A-Z0-9]+$/.test(path)) {
        res.setHeader('Content-Type', 'text/html'); res.end(html); return;
    }
    const file = resolve(publicRoot, `.${path}`);
    if (!file.startsWith(publicRoot + sep) || !/^\/(?:build|images)\//.test(path)) { res.writeHead(404); res.end(); return; }
    try { res.setHeader('Content-Type', types[extname(file)] ?? 'application/octet-stream'); res.end(await readFile(file)); }
    catch { res.writeHead(404); res.end(); }
}).listen(8028, '127.0.0.1');
