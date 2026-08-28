import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const css = readFileSync(resolve(root, "resources/js/video-conferencing/video-conferencing.css"), "utf8");
const app = readFileSync(resolve(root, "resources/js/video-conferencing/ConferenceApp.tsx"), "utf8");
const stationDetail = readFileSync(resolve(root, "resources/js/daily-checkout/src/components/StationDetailPage.tsx"), "utf8");

test("conference entry mode is server-bound and all controls remain touch safe", () => {
  assert.doesNotMatch(app, /type="radio"|vc-role-list|Choose how to join/);
  assert.match(app, /bootstrap\.entry_mode/);
  assert.match(app, /bootstrap\.join_as/);
  assert.match(css, /\.vc-shell button,[\s\S]*?\.vc-shell input\s*\{[^}]*min-height:\s*48px;/s);
  assert.match(css, /env\(safe-area-inset-bottom\)/);
});

test("station polling and Reverb events share a single token request in flight", () => {
    const source = readFileSync("resources/js/video-conferencing/ConferenceApp.tsx", "utf8");

    assert.match(source, /stationTokenRequestRef\.current \|\| joiningRef\.current/);
    assert.match(source, /stationTokenRequestRef\.current = true/);
    assert.match(source, /finally \{\s*stationTokenRequestRef\.current = false/);
});

test("station detail launches fixed station context without bundling LiveKit", () => {
  assert.match(stationDetail, /const stationNumber = Number\(station\.station_number\);/);
  assert.match(stationDetail, /\[1, 2, 3, 4, 6\]\.includes\(stationNumber\)/);
  assert.match(stationDetail, /href=\{`\/video-conferencing\/stations\/\$\{stationNumber\}`\}/);
  assert.match(stationDetail, /stationNumber === 2/);
  assert.match(stationDetail, /Morning Lineup Video Conference — Station 2/);
  assert.match(stationDetail, /href="\/employee\/video-conferencing\/command"/);
  assert.doesNotMatch(stationDetail, /from ['"]livekit-client['"]/);
});
