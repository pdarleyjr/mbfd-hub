import { expect, type BrowserContext, type Page, type Request } from '@playwright/test';

// The inventory's render-only Livewire calls read fixture records; edits and actions stay blocked.
const renderMethods = new Set(['loadTable', '__lazyLoad', 'getFormUploadedFiles']);
const trainingStatsComponent = 'app.filament.training.widgets.training-stats-widget';
const nativeNotificationsComponent = 'filament.livewire.database-notifications';
const nativePulseCards = new Set(['pulse.cache', 'pulse.usage', 'pulse.queues', 'pulse.servers',
  'pulse.slow-jobs', 'pulse.exceptions', 'pulse.slow-requests', 'pulse.slow-queries', 'pulse.slow-outgoing-requests']);
const nativeSelectSelector = '[x-load-src][x-data^="selectFormComponent("]';

type RenderingGuardOptions = {
  // Inventory asserts the local AI integration is disabled before enabling this adapter.
  allowDisabledAiReport?: boolean;
  onBlocked?: (description: string) => void;
  onAllowedLivewireRequest?: (request: Request) => void;
};

export async function guardUiRendering(context: BrowserContext, origin: string, options: RenderingGuardOptions = {}): Promise<void> {
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const block = (description: string) => {
      options.onBlocked?.(description);
      return route.abort('blockedbyclient');
    };
    if (request.isNavigationRequest() && url.origin !== origin) return block(`External navigation: ${url.origin}${url.pathname}`);
    if (['GET', 'HEAD', 'OPTIONS'].includes(request.method())) return route.continue();
    if (request.method() !== 'POST' || url.origin !== origin || url.pathname !== '/livewire/update') return block(`${request.method()} ${url.pathname}: non-read request`);
    try {
      const components = request.postDataJSON()?.components;
      const safe = Array.isArray(components) && components.length > 0 && components.every(component => {
        const name = JSON.parse(component.snapshot || '{}').memo?.name || '';
        const pagePath = new URL(request.frame().url() || origin).pathname;
        if (!component.updates || typeof component.updates !== 'object' || Array.isArray(component.updates)
          || Object.keys(component.updates).length !== 0 || !Array.isArray(component.calls)) return false;
        // Installed CanPoll, notification and Pulse card polling invoke $commit(): no updates or calls.
        // Only these source/trace-proven read-only components may make an empty commit.
        if (component.calls.length === 0) return name === trainingStatsComponent || name === nativeNotificationsComponent
          || (nativePulseCards.has(name) && pagePath === '/pulse' && new URL(request.frame().url()).origin === origin);
        return component.calls.every((call: { method: string }) => renderMethods.has(call.method)
            || (call.method === '$refresh' && name.startsWith('pulse.'))
            || (options.allowDisabledAiReport === true && call.method === 'loadAiReport'
              && name.endsWith('session-results-page') && pagePath === '/workgroups/session-results'));
      });
      if (safe) {
        options.onAllowedLivewireRequest?.(request);
        return route.continue();
      }
      const methods = Array.isArray(components) ? components.flatMap(component => Array.isArray(component.calls)
        ? component.calls.map((call: { method?: string }) => call.method) : []).join(',') : '';
      return block(`${request.method()} ${url.pathname}: ${methods || 'non-read request'}`);
    } catch {
      return block(`${request.method()} ${url.pathname}: non-read request`);
    }
  });
}

export async function waitForUiRendering(page: Page, origin: string) {
  const localFrames = () => page.frames().filter(frame => frame === page.mainFrame() || frame.url().startsWith(origin + '/'));
  let nativeSelects = { rendered: 0, hydrated: 0 };
  await expect.poll(async () => {
    const pending: string[] = [];
    for (const frame of localFrames()) {
      const widgets = await frame.locator('[wire\\:snapshot]').evaluateAll(elements => elements.filter(el => {
        const style = getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && el.getClientRects().length > 0
          && !el.closest('[aria-hidden="true"], [inert]') && JSON.parse(el.getAttribute('wire:snapshot') || '{}').memo?.lazyLoaded === false;
      }).map(el => ({ id: el.getAttribute('wire:id'), name: JSON.parse(el.getAttribute('wire:snapshot') || '{}').memo?.name })));
      for (const widget of widgets) {
        pending.push(widget.name || 'unnamed native lazy widget');
        if (!widget.id) throw new Error('Native lazy widget is missing wire:id');
        await frame.locator(`[wire\\:id=${JSON.stringify(widget.id)}]`).scrollIntoViewIfNeeded({ timeout: 10_000 });
      }
    }
    return pending;
  }, { timeout: 60_000, message: 'Visible native lazy widgets hydrate before acceptance' }).toEqual([]);

  await expect.poll(async () => {
    const pending: string[] = [];
    nativeSelects = { rendered: 0, hydrated: 0 };
    for (const frame of localFrames()) {
      pending.push(...await frame.locator('.fi-ta').evaluateAll(tables => tables.filter(table => {
        const style = getComputedStyle(table);
        if (style.display === 'none' || style.visibility === 'hidden' || table.getClientRects().length === 0) return false;
        const snapshot = table.closest('[wire\\:snapshot]')?.getAttribute('wire:snapshot');
        return Boolean(table.getAttribute('wire:init') === 'loadTable' && snapshot && JSON.parse(snapshot).data?.isTableLoaded === false);
      }).map(() => 'native deferred table')));
      const selects = await frame.locator(nativeSelectSelector).evaluateAll(elements => elements.flatMap((element, index) => {
        const style = getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden' || element.getClientRects().length === 0
          || element.closest('[aria-hidden="true"], [inert]')) return [];
        const inner = element.querySelector('.choices__inner');
        const bounds = inner?.getBoundingClientRect();
        return [{ index, hydrated: Boolean(inner && bounds && bounds.width > 0 && bounds.height > 0 && getComputedStyle(inner).visibility !== 'hidden') }];
      }));
      nativeSelects.rendered += selects.length;
      nativeSelects.hydrated += selects.filter(select => select.hydrated).length;
      for (const select of selects.filter(select => !select.hydrated)) {
        pending.push(`native searchable select ${select.index + 1}`);
        await frame.locator(nativeSelectSelector).nth(select.index).scrollIntoViewIfNeeded({ timeout: 10_000 });
      }
    }
    return pending;
  }, { timeout: 60_000, message: 'Visible native deferred tables and searchable selects finish loading before acceptance' }).toEqual([]);

  const path = new URL(page.url()).pathname.replace(/\/$/, '') || '/';
  if (path === '/training') {
    await expect(page.locator('.fi-wi-stats-overview-stat')).toHaveCount(3, { timeout: 60_000 });
    for (const label of ['Open Training Todos', 'Stale Items', 'Recently Updated']) {
      await expect(page.locator('.fi-wi-stats-overview').getByText(label, { exact: true })).toBeVisible();
    }
    await expect(page.locator('.fi-wi-table').getByRole('heading', { name: 'Recent & Pending Training Todos', exact: true })).toBeVisible();
  }
  if (path === '/employee/forms') {
    await expect(page.locator('.of-loading')).toBeHidden({ timeout: 60_000 });
    await expect(page.locator('.of-library')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Start a form', exact: true })).toBeVisible();
    await expect(page.locator('.of-form-card')).toHaveCount(2);
    for (const label of ['ICS 214 — Activity Log', 'Daily Activity Report — Firefighter (FROC-LOG-001-FF), Version 11']) {
      await expect(page.locator('.of-form-card').getByRole('heading', { name: label, exact: true })).toBeVisible();
    }
    await expect(page.locator('.of-record-table')).toBeVisible();
  }
  let pushWorker = null;
  if (['/admin/settings', '/training/settings'].includes(path)) {
    await expect(page.locator('#push-notification-manager')).toBeVisible();
    await expect.poll(() => page.evaluate(async () => {
      const registration = await navigator.serviceWorker.getRegistration(location.origin + '/');
      return registration?.active?.state === 'activated' && new URL(registration.active.scriptURL).pathname === '/sw.js';
    }), { timeout: 60_000, message: 'Native push worker activates without notification actions' }).toBe(true);
    await expect(page.locator('#push-loading')).toBeHidden();
    await expect(page.locator('#error-section')).toBeHidden();
    pushWorker = await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.getRegistration(location.origin + '/');
      return { scopePath: registration ? new URL(registration.scope).pathname : null,
        scriptPath: registration?.active ? new URL(registration.active.scriptURL).pathname : null,
        state: registration?.active?.state ?? null, permission: Notification.permission,
        subscriptionPresent: Boolean(registration && await registration.pushManager.getSubscription()) };
    });
    expect(['default', 'denied'], 'Read-only fixture never grants notification permission').toContain(pushWorker.permission);
    expect(pushWorker.subscriptionPresent, 'Read-only fixture never subscribes to push').toBe(false);
    // Fresh headless Chromium can deny notifications by default; the native widget
    // must show that source-defined status, without requesting permission.
    await expect(page.locator(pushWorker.permission === 'denied' ? '#permission-denied' : '#subscribe-section')).toBeVisible();
  }
  for (const frame of localFrames()) await frame.evaluate(() => window.scrollTo(0, 0));
  return { pushWorker, nativeSelects };
}
