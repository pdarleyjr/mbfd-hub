// MBFD Hub Service Worker - Push Notifications
const CACHE_NAME = 'mbfd-hub-v1';

// Push notification handler
self.addEventListener('push', function(event) {
    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return;
    }

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

// Notification click handler
self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    const data = event.notification.data || {};
    let urlToOpen = data.url || '/admin';

    // Handle chat notification clicks
    if (event.action === 'open-chat' || urlToOpen.includes('/chat')) {
        urlToOpen = data.url || '/admin/chat';
    }

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            // Check if there's already a window open
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(urlToOpen);
                    return client.focus();
                }
            }
            // No window open, open a new one
            return clients.openWindow(urlToOpen);
        })
    );
});

// Install event - cache static assets
self.addEventListener('install', function(event) {
    self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', function(event) {
    event.waitUntil(clients.claim());
});
