if(!self.define){let e,s={};const i=(i,n)=>(i=new URL(i+".js",n).href,s[i]||new Promise(s=>{if("document"in self){const e=document.createElement("script");e.src=i,e.onload=s,document.head.appendChild(e)}else e=i,importScripts(i),s()}).then(()=>{let e=s[i];if(!e)throw new Error(`Module ${i} didn’t register its module`);return e}));self.define=(n,r)=>{const o=e||("document"in self?document.currentScript.src:"")||location.href;if(s[o])return;let t={};const c=e=>i(e,o),a={module:{uri:o},exports:t,require:c};s[o]=Promise.all(n.map(e=>a[e]||c(e))).then(e=>(r(...e),t))}}define(["./workbox-4b126c97"],function(e){"use strict";self.skipWaiting(),e.clientsClaim(),e.precacheAndRoute([{url:"service-worker.js",revision:"466c1a98200bf3bbbad5e0a012528ce5"},{url:"registerSW.js",revision:"8f0b7680aa804037f71e8e34afa1339e"},{url:"index.html",revision:"979ef8e802942659662d3deceebad979"},{url:"icons/icon-512.png",revision:"b3ab4b66e4f902e5b16eb5dd81d8aca1"},{url:"icons/icon-192.png",revision:"98d4203525e38f3684f875539c058e46"},{url:"assets/index-CRT-VNSP.js",revision:null},{url:"assets/index-BUw7CfLY.css",revision:null},{url:"assets/browser-image-compression-5Xiw9QKB.js",revision:null},{url:"manifest.webmanifest",revision:"f615812bf0c4a8ef667bca29ee1b207c"}],{}),e.cleanupOutdatedCaches(),e.registerRoute(new e.NavigationRoute(e.createHandlerBoundToURL("index.html"))),e.registerRoute(/^https:\/\/.*\/api\//i,new e.NetworkFirst({cacheName:"api-cache",networkTimeoutSeconds:5,plugins:[new e.ExpirationPlugin({maxEntries:100,maxAgeSeconds:86400}),new e.CacheableResponsePlugin({statuses:[0,200]})]}),"GET"),e.registerRoute(/\.(?:png|jpg|jpeg|svg|gif|webp)$/i,new e.CacheFirst({cacheName:"image-cache",plugins:[new e.ExpirationPlugin({maxEntries:60,maxAgeSeconds:2592e3})]}),"GET"),e.registerRoute(/\.(?:woff2?|ttf|eot)$/i,new e.CacheFirst({cacheName:"font-cache",plugins:[new e.ExpirationPlugin({maxEntries:20,maxAgeSeconds:31536e3})]}),"GET")});
//# sourceMappingURL=sw.js.map

// ─── Injected Push Notification Handlers (ERROR-036 fix) ───────────────
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
