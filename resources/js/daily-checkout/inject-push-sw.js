/**
 * Post-build script: Appends push notification listeners to VitePWA's
 * generated sw.js. This runs AFTER `vite build` completes, ensuring
 * VitePWA has already written its service worker.
 *
 * Fixes ERROR-036: VitePWA generateSW mode was stripping push listeners.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const defaultOutDir = path.join(__dirname, '..', '..', '..', 'public', 'daily');
const outDir = process.env.DAILY_CHECKOUT_OUT_DIR
  ? path.resolve(__dirname, process.env.DAILY_CHECKOUT_OUT_DIR)
  : defaultOutDir;
const swPath = path.join(outDir, 'sw.js');

const pushListeners = `
// \u2500\u2500\u2500 Injected Push Notification Handlers (ERROR-036 fix) \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
self.addEventListener('push', function(event) {
  if (!(self.Notification && self.Notification.permission === 'granted')) return;
  const payload = event.data ? event.data.json() : {};
  const title = payload.title || 'MBFD Hub Update';
  const options = {
    body: payload.body || 'New activity reported.',
    icon: payload.icon || '/images/mbfd-logo.png',
    badge: '/images/mbfd-logo.png',
    data: payload.data || {},
    actions: payload.actions || [],
    vibrate: [200, 100, 200],
    tag: payload.tag || 'mbfd-notification',
    requireInteraction: true
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

function sameOriginNavigation(value, fallback) {
  try {
    const candidate = new URL(String(value || fallback), self.location.origin);
    if (candidate.origin !== self.location.origin) return fallback;
    return candidate.pathname + candidate.search + candidate.hash;
  } catch {
    return fallback;
  }
}

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  const data = event.notification.data || {};
  let requestedUrl = data.url || '/admin';
  if (event.action === 'open-chat' || requestedUrl.includes('/chat')) {
    requestedUrl = data.url || '/admin/chat';
  }
  const urlToOpen = sameOriginNavigation(requestedUrl, '/admin');
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      for (let i = 0; i < clientList.length; i++) {
        const client = clientList[i];
        if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
          client.navigate(urlToOpen);
          return client.focus();
        }
      }
      return clients.openWindow(urlToOpen);
    })
  );
});
`;

let descriptor;
try {
  descriptor = fs.openSync(swPath, 'r+');
  const existing = fs.readFileSync(descriptor, 'utf-8');
  if (!existing.includes("addEventListener('push'")) {
    fs.writeSync(descriptor, pushListeners, null, 'utf-8');
    console.log('[inject-push-sw] \u2713 Push listeners appended to sw.js');
  } else {
    console.log('[inject-push-sw] Push listeners already present, skipping.');
  }
} catch (error) {
  if (error?.code === 'ENOENT') {
    console.error('[inject-push-sw] \u2717 sw.js not found at', swPath);
    process.exitCode = 1;
  } else {
    throw error;
  }
} finally {
  if (descriptor !== undefined) fs.closeSync(descriptor);
}
