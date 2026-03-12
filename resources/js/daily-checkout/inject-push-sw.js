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
const swPath = path.join(__dirname, '..', '..', '..', 'public', 'daily', 'sw.js');

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

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  const data = event.notification.data || {};
  let urlToOpen = data.url || '/admin';
  if (event.action === 'open-chat' || urlToOpen.includes('/chat')) {
    urlToOpen = data.url || '/admin/chat';
  }
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      for (let i = 0; i < clientList.length; i++) {
        const client = clientList[i];
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.navigate(urlToOpen);
          return client.focus();
        }
      }
      return clients.openWindow(urlToOpen);
    })
  );
});
`;

if (fs.existsSync(swPath)) {
  const existing = fs.readFileSync(swPath, 'utf-8');
  if (!existing.includes("addEventListener('push'")) {
    fs.appendFileSync(swPath, pushListeners);
    console.log('[inject-push-sw] \u2713 Push listeners appended to sw.js');
  } else {
    console.log('[inject-push-sw] Push listeners already present, skipping.');
  }
} else {
  console.error('[inject-push-sw] \u2717 sw.js not found at', swPath);
  process.exit(1);
}
