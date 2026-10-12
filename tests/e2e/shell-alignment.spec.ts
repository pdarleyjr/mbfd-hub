import { expect, test, type Page, type TestInfo } from '@playwright/test';

/*
 * Shell alignment contract for the MBFD Hub UI modernization (WP0 baseline).
 *
 * Every shell bar on every surface has one height (`--hub-shell-bar-height`), and on Filament
 * panels the top bar and the sidebar header end on the same pixel row. Run it with
 *   npx playwright test -c playwright.shell-alignment.config.ts
 * on the protected-UI harness (clean checkout, no .env, isolated SQLite database).
 *
 * Each case starts as a first-time visitor (no persisted sidebar state). Admin closes its drawer
 * on phones with its own viewport script; the Employee, Training and Workgroups panels keep
 * Filament's default of an open drawer, so their phone cases measure both bars and assert they align.
 */

const TOLERANCE_PX = 0.5;

type Surface = { name: string; path: string; kind: 'filament' | 'blade' };

const filamentSurfaces: Surface[] = [
  { name: 'admin dashboard', path: '/admin', kind: 'filament' },
  { name: 'admin index', path: '/admin/apparatuses', kind: 'filament' },
  { name: 'employee dashboard', path: '/employee/dashboard', kind: 'filament' },
  { name: 'employee index', path: '/employee/my-requests', kind: 'filament' },
  { name: 'training dashboard', path: '/training', kind: 'filament' },
  { name: 'training index', path: '/training/training-todos', kind: 'filament' },
  { name: 'workgroups dashboard', path: '/workgroups', kind: 'filament' },
  { name: 'workgroups index', path: '/workgroups/workgroup/candidate-products', kind: 'filament' },
];

const bladeSurfaces: Surface[] = [
  { name: 'home', path: '/', kind: 'blade' },
  { name: 'updates', path: '/updates', kind: 'blade' },
  { name: 'account', path: '/account', kind: 'blade' },
];

type Box = { top: number; bottom: number; height: number };

type ShellMeasurement = {
  path: string;
  diagnostics: {
    viewportWidth: number;
    sidebarLeft: number | null;
    sidebarRight: number | null;
    sidebarOpenClass: boolean | null;
    persistedIsOpen: string | null;
  };
  tokenPx: number | null;
  topbar: Box | null;
  sidebarHeader: Box | null;
  blade: Box | null;
};

async function open(page: Page, surface: Surface): Promise<void> {
  const response = await page.goto(surface.path, { waitUntil: 'load' });
  expect(response?.status(), `${surface.path} must render for the seeded super admin`).toBe(200);
  await expect(page.locator(surface.kind === 'blade' ? '.hub-shell-bar' : '.fi-topbar > nav').first()).toBeVisible();
  await page.evaluate(() => document.fonts.ready.then(() => undefined));
  // The sidebar drawer slides with a CSS transition after Alpine initialises; measure only at rest.
  for (let round = 0; round < 3; round += 1) {
    await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => resolve())));
    await page.evaluate(() => Promise.all(document.getAnimations().map(animation => animation.finished.catch(() => undefined))).then(() => undefined));
  }
}

/** Measures the bars in the page itself so every number comes from the live layout. */
async function measure(page: Page, surface: Surface): Promise<ShellMeasurement> {
  return page.evaluate(({ kind, path }) => {
    const box = (selector: string): Box | null => {
      const element = document.querySelector(selector);
      if (element === null) return null;
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      // A bar counts as present only when it is actually on screen (drawers sit off-canvas).
      const onScreen = rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < innerWidth
        && style.display !== 'none' && style.visibility !== 'hidden';
      return onScreen ? { top: rect.top, bottom: rect.bottom, height: rect.height } : null;
    };
    const probe = document.createElement('div');
    probe.style.cssText = 'position:absolute;visibility:hidden;width:1px;height:var(--hub-shell-bar-height)';
    document.body.append(probe);
    const declared = getComputedStyle(document.documentElement).getPropertyValue('--hub-shell-bar-height').trim();
    const tokenPx = declared === '' ? null : probe.getBoundingClientRect().height;
    probe.remove();
    const rawSidebar = document.querySelector('.fi-sidebar');
    const rawRect = rawSidebar?.getBoundingClientRect();
    let isOpen: string | null = null;
    try { isOpen = localStorage.getItem('isOpen'); } catch { isOpen = null; }
    return {
      path,
      diagnostics: {
        viewportWidth: innerWidth,
        sidebarLeft: rawRect === undefined ? null : rawRect.left,
        sidebarRight: rawRect === undefined ? null : rawRect.right,
        sidebarOpenClass: rawSidebar?.classList.contains('fi-sidebar-open') ?? null,
        persistedIsOpen: isOpen,
      },
      tokenPx,
      topbar: kind === 'filament' ? box('.fi-topbar > nav') : null,
      sidebarHeader: kind === 'filament' ? box('.fi-sidebar-header') : null,
      blade: kind === 'blade' ? box('.hub-shell-bar') : null,
    };
  }, { kind: surface.kind, path: surface.path });
}

function barHeights(measurement: ShellMeasurement): number[] {
  return [measurement.topbar, measurement.sidebarHeader, measurement.blade]
    .filter((bar): bar is Box => bar !== null)
    .map(bar => bar.height);
}

async function attachMeasurement(testInfo: TestInfo, measurement: ShellMeasurement): Promise<void> {
  await testInfo.attach('shell-measurement.json', {
    body: Buffer.from(JSON.stringify(measurement, null, 2)),
    contentType: 'application/json',
  });
}

test.describe('unauthenticated panel login routes', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  for (const path of ['/admin/login', '/employee/login', '/training/login', '/workgroups/login']) {
    test(`${path} answers a signed-out visitor without a server error`, async ({ page, request }, testInfo) => {
      test.skip(testInfo.project.name !== 'vp-1366x768', 'Route health does not vary by viewport.');
      const response = await request.get(path, { maxRedirects: 0 });
      expect(response.status(), `${path} must not fail`).toBeLessThan(400);
      expect([200, 301, 302, 303, 307, 308]).toContain(response.status());
      await page.goto(path, { waitUntil: 'load' });
      await expect(page.locator('body')).toBeVisible();
    });
  }
});

test.describe('shell bars', () => {
  test.beforeEach(async ({ page }) => {
    // Filament persists the sidebar's open state in localStorage and the shared login state was
    // captured on a desktop viewport. Start every case as a first-time visitor.
    await page.addInitScript(() => {
      try {
        localStorage.removeItem('isOpen');
        localStorage.removeItem('_x_isOpen');
      } catch {
        // Storage can be unavailable; the default state is then already the first-visit state.
      }
    });
  });

  for (const surface of [...filamentSurfaces, ...bladeSurfaces]) {
    test(`${surface.name} (${surface.path}) bar height equals --hub-shell-bar-height`, async ({ page }, testInfo) => {
      await open(page, surface);
      const measurement = await measure(page, surface);
      await attachMeasurement(testInfo, measurement);

      expect(measurement.tokenPx, '--hub-shell-bar-height must be declared on :root').not.toBeNull();
      const heights = barHeights(measurement);
      expect(heights.length, 'a shell bar must be on screen').toBeGreaterThan(0);
      for (const height of heights) {
        expect.soft(Math.abs(height - (measurement.tokenPx ?? Number.NaN)), `bar is ${height}px, token is ${measurement.tokenPx}px`).toBeLessThanOrEqual(TOLERANCE_PX);
      }
    });
  }

  for (const surface of filamentSurfaces) {
    test(`${surface.name} (${surface.path}) top bar and sidebar header end on the same row`, async ({ page }, testInfo) => {
      await open(page, surface);
      const measurement = await measure(page, surface);
      await attachMeasurement(testInfo, measurement);

      test.skip(measurement.sidebarHeader === null, 'The sidebar is a closed drawer at this width, so there is no second bar to align.');
      expect(measurement.topbar, 'the top bar must be on screen').not.toBeNull();
      const { topbar, sidebarHeader } = measurement;
      expect(
        Math.abs(topbar!.bottom - sidebarHeader!.bottom),
        `top bar ends at ${topbar!.bottom}px (${topbar!.height}px tall) but the sidebar header ends at ${sidebarHeader!.bottom}px (${sidebarHeader!.height}px tall)`,
      ).toBeLessThanOrEqual(TOLERANCE_PX);
    });
  }

  test('every shell surface reports the same bar height', async ({ page }, testInfo) => {
    const seen: Record<string, number[]> = {};
    for (const surface of [...bladeSurfaces, ...filamentSurfaces.filter((_, index) => index % 2 === 0)]) {
      await open(page, surface);
      seen[surface.path] = barHeights(await measure(page, surface));
    }
    await testInfo.attach('bar-heights-by-surface.json', { body: Buffer.from(JSON.stringify(seen, null, 2)), contentType: 'application/json' });
    const distinct = [...new Set(Object.values(seen).flat().map(height => Math.round(height * 2) / 2))];
    expect(distinct, `surfaces disagree on bar height: ${JSON.stringify(seen)}`).toHaveLength(1);
  });
});
