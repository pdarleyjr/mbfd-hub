import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';

const root = process.cwd();
const buildDir = path.join(root, 'public', 'build');
const dailyDir = path.join(root, 'public', 'daily');
const manifestPath = path.join(buildDir, 'manifest.json');

if (!fs.existsSync(manifestPath)) {
    throw new Error('Vite manifest is missing. Run npm run build first.');
}

const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const entry = manifest['resources/js/video-conferencing/main.tsx'];
const mount = manifest['resources/js/video-conferencing/mount.tsx'];
const livekit = Object.values(manifest).find((item) => item.name === 'livekit');

for (const [name, item] of Object.entries({ entry, mount, livekit })) {
    if (!item?.file) throw new Error(`Conference ${name} chunk is missing from the Vite manifest.`);
}

const limits = [
    ['entry', entry.file, 5_000, 3_000],
    ['mount', mount.file, 30_000, 15_000],
    ['livekit', livekit.file, 500_000, 140_000],
];

for (const [name, file, rawLimit, gzipLimit] of limits) {
    const bytes = fs.readFileSync(path.join(buildDir, file));
    const gzipBytes = gzipSync(bytes).byteLength;
    if (bytes.byteLength > rawLimit || gzipBytes > gzipLimit) {
        throw new Error(`${name} bundle exceeds budget: ${bytes.byteLength} raw / ${gzipBytes} gzip bytes.`);
    }
    process.stdout.write(`${name}: ${bytes.byteLength} raw / ${gzipBytes} gzip bytes\n`);
}

for (const cssFile of mount.css ?? []) {
    const size = fs.statSync(path.join(buildDir, cssFile)).size;
    if (size > 15_000) throw new Error(`Conference CSS exceeds budget: ${size} bytes.`);
    process.stdout.write(`css: ${size} raw bytes\n`);
}

function findMaps(directory) {
    if (!fs.existsSync(directory)) return [];
    return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const fullPath = path.join(directory, entry.name);
        return entry.isDirectory() ? findMaps(fullPath) : (entry.name.endsWith('.map') ? [fullPath] : []);
    });
}

const publicMaps = [...findMaps(buildDir), ...findMaps(dailyDir)];
if (publicMaps.length > 0) {
    throw new Error(`Public source maps are forbidden:\n${publicMaps.join('\n')}`);
}

process.stdout.write('Conference bundle budgets and public source-map guard passed.\n');
