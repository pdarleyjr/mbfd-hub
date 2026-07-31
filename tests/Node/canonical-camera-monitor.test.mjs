import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

test('origin monitoring probes only the canonical Anpviz and Guest Computer streams', () => {
    const monitor = read('scripts/operations/mbfd-origin-monitor.py');
    const errorMonitor = read('scripts/operations/mbfd-site-error-monitor.sh');

    assert.match(monitor, /class LoopbackCookiePolicy/);
    assert.match(monitor, /HTTPCookieProcessor/);
    assert.match(
        monitor,
        /http:\/\/127\.0\.0\.1:8120\/hls\/anpviz-main\/index\.m3u8/,
    );
    assert.match(
        monitor,
        /http:\/\/127\.0\.0\.1:8120\/hls\/guest-computer\/index\.m3u8/,
    );
    assert.match(monitor, /"anpviz-video", "\/hls\/anpviz-main\/video1_stream\.m3u8"/);
    assert.match(monitor, /"anpviz-audio", "\/hls\/anpviz-main\/audio2_stream\.m3u8"/);
    assert.match(monitor, /"guest-video", "\/hls\/guest-computer\/video1_stream\.m3u8"/);
    assert.match(monitor, /"guest-audio", "\/hls\/guest-computer\/audio2_stream\.m3u8"/);
    assert.match(
        monitor,
        /https:\/\/cameras\.mbfdhub\.com\/hls\/anpviz-main\/index\.m3u8/,
    );
    assert.doesNotMatch(monitor, /\/hls\/cam[13]\//);
    assert.doesNotMatch(errorMonitor, /\/hls\/cam[13]\//);
});
