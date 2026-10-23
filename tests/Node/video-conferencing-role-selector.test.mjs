import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const css = readFileSync(resolve(root, "resources/js/video-conferencing/video-conferencing.css"), "utf8");
const app = readFileSync(resolve(root, "resources/js/video-conferencing/ConferenceApp.tsx"), "utf8");
const participantTile = readFileSync(resolve(root, "resources/js/video-conferencing/ParticipantTile.tsx"), "utf8");
const command300 = readFileSync(resolve(root, "resources/js/daily-checkout/src/components/Command300Page.tsx"), "utf8");
const stationDetail = readFileSync(resolve(root, "resources/js/daily-checkout/src/components/StationDetailPage.tsx"), "utf8");

test("conference entry mode is server-bound and all controls remain touch safe", () => {
  assert.doesNotMatch(app, /type="radio"|vc-role-list|Choose how to join/);
  assert.match(app, /bootstrap\.entry_mode/);
  assert.match(app, /bootstrap\.join_as/);
  assert.match(css, /\.vc-shell button,[\s\S]*?\.vc-shell input\s*\{[^}]*min-height:\s*48px;/s);
  assert.match(css, /env\(safe-area-inset-bottom\)/);
});

test("command entry uses the 300 Command presentation label and participant tiles render issued names", () => {
  assert.match(app, /bootstrap\.entry_mode === 'command' \? '300 Command' : 'Employee Portal'/);
  assert.doesNotMatch(app, /'command' \? 'Admin'/);
  assert.doesNotMatch(app, /No LiveKit connection/);
  assert.match(participantTile, /vc-tile__name">\{participant\.name \|\| 'MBFD participant'\}/);
});

test("station polling and Reverb events share a single token request in flight", () => {
    const source = readFileSync("resources/js/video-conferencing/ConferenceApp.tsx", "utf8");

    assert.match(source, /stationTokenRequestRef\.current \|\| joiningRef\.current/);
    assert.match(source, /stationTokenRequestRef\.current = true/);
    assert.match(source, /finally \{\s*stationTokenRequestRef\.current = false/);
});

test("station detail launches fixed station context without bundling LiveKit", () => {
  assert.match(stationDetail, /const stationNumber = Number\(station\.station_number\);/);
  // The lineup stations may be listed inline or through a named constant.
  assert.match(stationDetail, /(?:\[1, 2, 3, 4, 6\]|LINEUP_STATION_NUMBERS)\.includes\(stationNumber\)/);
  if (/LINEUP_STATION_NUMBERS/.test(stationDetail)) assert.match(stationDetail, /LINEUP_STATION_NUMBERS[^=]*=\s*\[1, 2, 3, 4, 6\]/);
  assert.match(stationDetail, /href(?:=\{|:\s*)`\/video-conferencing\/stations\/\$\{stationNumber\}`/);
  assert.match(stationDetail, /stationNumber === 2/);
  assert.match(stationDetail, /Morning Lineup Video Conference — Station 2/);
  // 300 Command has its own profile; Station 2 no longer carries a Command shortcut.
  assert.doesNotMatch(stationDetail, /\/employee\/video-conferencing\/command/);
  assert.doesNotMatch(stationDetail, /300 Command/);
  assert.doesNotMatch(stationDetail, /from ['"]livekit-client['"]/);
});

test("300 Command profile owns the Command lineup entry through the employee route", () => {
  assert.match(command300, /\/employee\/video-conferencing\/command\?return_to=%2Fdaily%2Fcommand-300/);
  assert.match(command300, /Morning Lineup — Command/);
  const boardHref = /export const COMMAND_BOARD_HREF = '([^']+)';/.exec(command300)?.[1];
  assert.equal(boardHref, "https://cmd.mbfdhub.com");
  assert.equal(new URL(boardHref).host, "cmd.mbfdhub.com");
  assert.match(command300, /href=\{COMMAND_BOARD_HREF\}/);
  assert.match(command300, /target="_blank" rel="noopener noreferrer"/);
  assert.doesNotMatch(command300, /<iframe/);
  assert.match(readFileSync(resolve(root, "resources/js/daily-checkout/src/App.tsx"), "utf8"), /path="\/command-300"/);
  assert.match(readFileSync(resolve(root, "resources/js/daily-checkout/src/utils/contextualBack.ts"), "utf8"), /'Back to 300'/);
});

test("the conference app drops its own Back / Hub Home when a Filament page already renders them", () => {
  const mount = readFileSync(resolve(root, "resources/js/video-conferencing/mount.tsx"), "utf8");
  const page = readFileSync(resolve(root, "resources/views/filament/employee/pages/video-conferencing.blade.php"), "utf8");
  const standalone = readFileSync(resolve(root, "resources/views/video-conferencing.blade.php"), "utf8");

  assert.match(app, /embedded = false/);
  assert.match(app, /\{!embedded && <nav className="vc-navigation"/);
  assert.match(mount, /embedded=\{element\.dataset\.embedded === 'true'\}/);
  assert.match(page, /data-embedded="true"/);
  assert.doesNotMatch(standalone, /data-embedded/, "standalone station / command pages keep the app's own navigation");
});
