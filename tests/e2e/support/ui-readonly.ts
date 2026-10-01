import type { BrowserContext } from '@playwright/test';

// The inventory's render-only Livewire calls read fixture records; edits and actions stay blocked.
const renderMethods = new Set(['loadTable', '__lazyLoad', 'getFormUploadedFiles']);

export async function guardUiRendering(context: BrowserContext, origin: string): Promise<void> {
  await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.isNavigationRequest() && url.origin !== origin) return route.abort('blockedbyclient');
    if (['GET', 'HEAD', 'OPTIONS'].includes(request.method())) return route.continue();
    if (url.origin !== origin || url.pathname !== '/livewire/update') return route.abort('blockedbyclient');
    try {
      const components = request.postDataJSON()?.components;
      const safe = Array.isArray(components) && components.length > 0 && components.every(component => {
        const name = JSON.parse(component.snapshot || '{}').memo?.name || '';
        return Object.keys(component.updates || {}).length === 0
          && Array.isArray(component.calls) && component.calls.length > 0
          && component.calls.every((call: { method: string }) => renderMethods.has(call.method)
            || (call.method === '$refresh' && name.startsWith('pulse.')));
      });
      return safe ? route.continue() : route.abort('blockedbyclient');
    } catch {
      return route.abort('blockedbyclient');
    }
  });
}
