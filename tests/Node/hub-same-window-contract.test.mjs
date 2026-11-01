import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import test from 'node:test';

/**
 * In an installed app a new tab or window escapes into Safari/Chrome, so Hub pages open in the
 * same window; inline files open in the document viewer; downloads just download. This guard
 * fails when a file gains a new-tab link (target _blank, window.open, openUrlInNewTab,
 * shouldOpenInNewTab) that is not on the list below, and it fails when a listed file no longer
 * has one, so the list cannot go stale. Add a file here only with the reason it must open apart.
 */

const root = resolve(import.meta.dirname, '..', '..');

const VIEWER_FALLBACK = 'viewer fallback (data-hub-viewer): the new tab only happens with scripts off';

const ALLOWED = {
  // External apps and sites. They are separate origins that cannot run inside the installed Hub window.
  'app/Providers/Filament/AdminPanelProvider.php': 'external app: Snipe-IT inventory (inventory.mbfdhub.com)',
  'resources/js/hub-ui/HubShell.tsx': 'external apps in the shell menu (Command Board, Bid, Media Control, ...)',
  'resources/views/components/hub/menu-item.blade.php': 'external apps in the shell menu, announced as opening in a new tab',
  'resources/views/welcome.blade.php': 'Home: Command Board and the external application tiles (Pump Panel, Vehicle Placement Simulator, Videos, Media Control)',
  'resources/views/filament/employee/pages/my-bid-certifications.blade.php': 'external app: Bid console',
  'resources/js/daily-checkout/src/components/Command300Page.tsx': 'external app: Command Board',
  'resources/views/training-roster/launch.blade.php': 'training kiosk: classroom links go to the kiosk host and are shared with participants',

  // User-written content: only links to other sites open apart; links to the Hub stay in the window.
  'app/Support/SafeLinkifier.php': 'user text: outside links only (Hub hosts stay in the window)',
  'resources/js/daily-checkout/src/components/command-communications/PostItem.tsx': 'user text: outside links only (Hub origin and its www/apex sibling stay in the window)',
  'resources/views/updates/_card.blade.php': 'department update button: outside links only (DepartmentUpdate::hasExternalCta treats Hub hosts as internal)',

  // Mail renders in a sandboxed iframe; a link must leave it (the sandbox cannot reach the Hub).
  'app/Services/Mail/MailHtmlSanitizer.php': 'sandboxed mail body: links open in a new tab outside the sandbox',
  'app/Http/Controllers/Mail/MessageBodyController.php': 'comment about the sandboxed mail body above',

  // Document viewer: the viewer handles the click, so target _blank only matters with scripts off.
  'resources/js/pwa/document-viewer.ts': 'the viewer itself; the match is in its explanatory comment',
  'app/Filament/Employee/Pages/MyVehicleInspectionView.php': VIEWER_FALLBACK,
  'app/Filament/Training/Resources/TrainingCourseResource/Pages/ReviewTrainingPaper.php': VIEWER_FALLBACK,
  'app/Filament/Resources/UnitMasterVehicleResource/RelationManagers/DocumentsRelationManager.php': VIEWER_FALLBACK,
  'app/Filament/Resources/UnitMasterVehicleResource/Pages/ReviewVehicleDocuments.php': VIEWER_FALLBACK,
  'resources/views/filament/training/pages/course-paper.blade.php': VIEWER_FALLBACK,
  'resources/views/filament/resources/operational-form-record/view.blade.php': VIEWER_FALLBACK,
  'resources/views/filament/employee/pages/resource-library.blade.php': VIEWER_FALLBACK,
  'resources/views/filament/employee/pages/taskbooks.blade.php': VIEWER_FALLBACK,
  'resources/views/filament/resources/inventory-correction-resource/pages/view-inventory-correction.blade.php': VIEWER_FALLBACK,
  'resources/views/filament/resources/inspection-resource/pages/view-inspection.blade.php': VIEWER_FALLBACK,
  'resources/views/filament/employee/pages/my-vehicle-inspection-view.blade.php': VIEWER_FALLBACK,
  'resources/views/take-home-inspections/show.blade.php': VIEWER_FALLBACK,
  'resources/views/filament/admin/pages/command-communications/attachments.blade.php': VIEWER_FALLBACK,
  'resources/js/daily-checkout/src/components/command-communications/AttachmentList.tsx': VIEWER_FALLBACK,

  // Ordinary browser tabs only.
  'resources/views/filament/admin/partials/context-menu.blade.php': '"Open in new window" is hidden in an installed (standalone) app',
};

const SKIPPED_DIRECTORIES = new Set(['node_modules', 'vendor', 'dist', 'build', '.vite']);
const EXTENSIONS = /\.(php|js|jsx|mjs|ts|tsx|html|vue)$/;

// Every spelling of "open apart": the _blank target, window.open, and Filament's new-tab switches.
// openUrlInNewTab(false) and shouldOpenInNewTab: false keep the same window, so they are fine.
const NEW_TAB = [
  /_blank/,
  /window\.open\(/,
  /openUrlInNewTab\((?!\s*false)/,
  /shouldOpenInNewTab\s*(?::\s*|\(\s*)true/,
];

function* sourceFiles(directory) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      if (SKIPPED_DIRECTORIES.has(entry.name)) continue;
      yield* sourceFiles(path);
    } else if (EXTENSIONS.test(entry.name)) {
      yield path;
    }
  }
}

function filesWithNewTabLinks() {
  const found = [];
  for (const base of ['app', 'resources']) {
    for (const path of sourceFiles(join(root, base))) {
      if (NEW_TAB.some((pattern) => pattern.test(readFileSync(path, 'utf8')))) {
        found.push(relative(root, path).split(sep).join('/'));
      }
    }
  }
  return found.sort();
}

test('only listed files open a new tab or window', () => {
  const unlisted = filesWithNewTabLinks().filter((file) => !(file in ALLOWED));

  assert.deepEqual(
    unlisted,
    [],
    'These files open a new tab or window. Use the same window, the document viewer (data-hub-viewer) or a download; '
      + `if the destination is truly external, add the file to ALLOWED with the reason:\n${unlisted.join('\n')}`,
  );
});

test('every listed file still has a reason to be listed', () => {
  const present = new Set(filesWithNewTabLinks());
  const stale = Object.keys(ALLOWED).filter((file) => !present.has(file));

  assert.deepEqual(stale, [], `These files no longer open a new tab; remove them from ALLOWED:\n${stale.join('\n')}`);
});

test('the scan covers app/ and resources/ but not published vendor views', () => {
  const scanned = [];
  for (const base of ['app', 'resources']) for (const path of sourceFiles(join(root, base))) scanned.push(relative(root, path).split(sep).join('/'));

  assert.ok(scanned.length > 500, 'the scan found the application sources');
  assert.ok(scanned.includes('resources/views/welcome.blade.php'));
  assert.ok(scanned.includes('app/Support/SafeLinkifier.php'));
  assert.equal(scanned.some((file) => file.includes('/node_modules/')), false);
});

test('the guard recognises each spelling it is meant to catch', () => {
  const hits = (text) => NEW_TAB.some((pattern) => pattern.test(text));

  assert.equal(hits('<a href="/x" target="_blank">'), true);
  assert.equal(hits("target: external ? '_blank' : undefined"), true);
  assert.equal(hits("window.open(url, 'x')"), true);
  assert.equal(hits('->openUrlInNewTab()'), true);
  assert.equal(hits('->openUrlInNewTab(true)'), true);
  assert.equal(hits("->url('https://x', shouldOpenInNewTab: true)"), true);
  assert.equal(hits('->shouldOpenInNewTab(true)'), true);

  assert.equal(hits('->openUrlInNewTab(false)'), false);
  assert.equal(hits("->url('https://x', shouldOpenInNewTab: false)"), false);
  assert.equal(hits('<a href="/x" download>'), false);
});
