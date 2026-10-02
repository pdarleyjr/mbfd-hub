import http from 'node:http';
import { createReadStream } from 'node:fs';
import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Loopback-only test adapter. Production routes are implemented in Laravel.
const root = fileURLToPath(new URL('../../', import.meta.url));
const corpus = path.resolve(process.env.POLICY_LIBRARY_TEST_CORPUS || path.join(root, 'var/preview'));
const port = Number(process.env.POLICY_LIBRARY_TEST_PORT || 8797);
const json = (response, value) => { response.writeHead(200, { 'Content-Type': 'application/json' }); response.end(JSON.stringify(value)); };
const types = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.pdf': 'application/pdf', '.wasm': 'application/wasm' };
async function sample() {
    const data = JSON.parse(await readFile(path.join(corpus, 'sample.json'), 'utf8'));
    const sections = [];
    let counter = 1000;
    for (const entry of data.documents) {
        let section = sections.find(item => item.title === entry.section);
        if (!section) { section = { id: counter++, title: entry.section, children: [] }; sections.push(section); }
        section.children.push({
            id: entry.id, slug: entry.id, title: entry.title, children: [],
            revision: { id: `sample-${entry.id}`, asset_url: `/test-assets/${entry.id}`, page_count: entry.page_count, version_label: entry.version_label || '2025–2027', pages: (entry.pages || []).map((page, index) => ({ page: index + 1, ...page })) },
        });
    }
    sections.push({ id: counter++, title: 'Updates / Inserts', children: [], metadata: { related_document_slugs: data.documents.filter(entry => /rocephin|pocus/i.test(entry.id)).map(entry => entry.id) } });
    return { data, sections };
}

const server = http.createServer(async (request, response) => {
    try {
        const url = new URL(request.url, `http://127.0.0.1:${port}`);
        if (url.pathname === '/ready') return json(response, { ready: true });
        if (url.pathname === '/api/manuals') return json(response, { manuals: [{ id: 1, slug: 'medical-protocols', name: 'Medical Protocols', type: 'medical' }, { id: 2, slug: 'sogs', name: 'Standard Operating Guidelines', type: 'sog' }], can_manage: false, manage_url: null });
        if (/^\/api\/manuals\/(medical-protocols|sogs)\/tree$/.test(url.pathname)) {
            const { sections } = await sample();
            const sogs = url.pathname.includes('/sogs/');
            const selected = sections.filter(node => sogs ? /^\d{3}\s*[-–]/.test(node.title) : !/^\d{3}\s*[-–]/.test(node.title));
            return json(response, { manual: { name: sogs ? 'SOGs' : 'Medical Protocols' }, nodes: selected, documents: selected.flatMap(node => node.children.map(child => child.id)) });
        }
        if (url.pathname === '/api/viewer-errors') { response.writeHead(204); response.end(); return; }
        if (url.pathname === '/') {
            let html = await readFile(path.join(root, 'resources/views/viewer.blade.php'), 'utf8');
            html = html.replace(/\{\{ asset\('([^']+)'\) \}\}/g, '/$1').replace('{{ csrf_token() }}', 'test-only');
            response.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }); response.end(html); return;
        }
        let filename;
        if (url.pathname.startsWith('/vendor/policy-library/')) {
            const relative = decodeURIComponent(url.pathname.slice('/vendor/policy-library/'.length));
            filename = path.resolve(root, 'public', relative);
            if (!filename.startsWith(path.join(root, 'public') + path.sep)) throw new Error('Invalid asset path');
        } else if (url.pathname.startsWith('/test-assets/')) {
            const { data } = await sample();
            const item = data.documents.find(entry => String(entry.id) === url.pathname.split('/').at(-1));
            if (!item) throw new Error('Unknown sample');
            filename = path.resolve(corpus, item.asset_path);
            if (!filename.startsWith(corpus + path.sep)) throw new Error('Invalid sample path');
        }
        if (!filename) { response.writeHead(404); response.end(); return; }
        const { size } = await stat(filename);
        const headers = { 'Content-Type': types[path.extname(filename)] || 'application/octet-stream', 'Accept-Ranges': 'bytes', 'Cache-Control': 'private, no-store', 'Content-Length': size };
        const match = /^bytes=(\d+)-(\d*)$/.exec(request.headers.range || '');
        if (match) {
            const start = Number(match[1]), end = Math.min(Number(match[2] || size - 1), size - 1);
            headers['Content-Range'] = `bytes ${start}-${end}/${size}`;
            headers['Content-Length'] = end - start + 1;
            response.writeHead(206, headers);
            const stream = createReadStream(filename, { start, end });
            response.on('close', () => stream.destroy());
            stream.pipe(response);
        } else {
            response.writeHead(200, headers);
            const stream = createReadStream(filename);
            response.on('close', () => stream.destroy());
            stream.pipe(response);
        }
    } catch (error) { response.writeHead(500, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ message: error.message })); }
});
server.listen(port, '127.0.0.1', () => process.stdout.write(`Policy library test server on loopback ${port}\n`));
