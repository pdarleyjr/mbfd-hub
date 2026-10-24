/**
 * MBFD Hub root service worker.
 *
 * Script URL: /sw.js   Scope: /   (both are frozen: installed devices only ever
 * look for updates at exactly this URL, and their push subscriptions live on
 * this registration. Never rename, redirect, version-suffix or unregister it.)
 *
 * Job: push display and notification routing only. There is deliberately no
 * fetch handler, so this worker intercepts no network traffic and taking
 * control of a page (skipWaiting + clients.claim) is harmless.
 *
 * The push/notificationclick/pushsubscriptionchange/message behavior is the
 * shared worker core, inlined from resources/js/pwa/worker-core.js by
 * scripts/pwa/build-service-workers.mjs. Edit the core, not the block below.
 */
const MBFD_WORKER_BUILD_ID = 'mbfd-root-push-2026-10-23.1';

/* MBFD_WORKER_CORE:START */
// GENERATED from resources/js/pwa/worker-core.js by scripts/pwa/build-service-workers.mjs.
// Do not edit between the markers; edit the core and run `npm run build:service-workers`.
/**
 * MBFD Hub shared service-worker core (classic script, no module syntax).
 *
 * One authoritative home for push display, notification routing, push
 * subscription refresh and the update message protocol. It is inlined, by
 * scripts/pwa/build-service-workers.mjs, between the MBFD_WORKER_CORE markers
 * of every worker script that ships it (public/sw.js and
 * public/admin-pwa/service-worker.js). Edit this file, then run
 * `npm run build:service-workers`; CI fails if an inlined copy drifts.
 *
 * Rules this file must keep:
 *  - Classic-script safe: no import/export, no importScripts, no top-level
 *    await. Workers are fetched as classic scripts at stable URLs.
 *  - Every push event ends in showNotification inside event.waitUntil. Safari
 *    and iOS revoke subscriptions that receive a silent push, so there is no
 *    early return, not even when the payload is empty, garbled or the
 *    permission looks wrong.
 *  - Nothing here opens, closes or removes a cache, a registration or an
 *    IndexedDB database. Caching strategy belongs to each worker.
 *  - Never use WindowClient.navigate(): Safari throws, and Chromium rejects it
 *    for windows controlled by a different registration. The page decides.
 *
 * Messages understood by the page side (resources/js/pwa/update-registrar.ts):
 *   worker -> page  { type: 'MBFD_NAVIGATE', url }   (+ one MessagePort for an ack)
 *   page -> worker  { type: 'SKIP_WAITING' }
 *   page -> worker  { type: 'GET_VERSION' }  (reply on ports[0])
 */

// .2: the page-acknowledgement wait is a 600 ms total budget that starts before focus() (was 800 ms after it).
const MBFD_WORKER_CORE_VERSION = '2026-10-23.2';

(function mbfdWorkerCore(scope) {
  'use strict';

  var DEFAULT_TITLE = 'MBFD Hub Update';
  var DEFAULT_BODY = 'New activity reported.';
  var DEFAULT_ICON = '/images/mbfd-logo.png';
  var DEFAULT_BADGE = '/images/mbfd-logo.png';
  var DEFAULT_VIBRATE = [200, 100, 200];
  var DEFAULT_URL = '/';
  var DECLARATIVE_PUSH_MARKER = 8030;
  var MAX_ACTIONS = 2;
  var DISMISS_ACTIONS = ['dismiss', 'close'];
  // Total budget, counted from the moment the page is asked (not from when focus() returns), for the
  // page to acknowledge. Firefox and WebKit only allow openWindow for ~1s after a notification click,
  // so the wait must end well inside that even when focusing the window is slow.
  var NAVIGATE_ACK_TIMEOUT_MS = 600;
  var SUBSCRIPTION_ENDPOINT = '/api/push-subscriptions';

  function origin() {
    return scope.location.origin;
  }

  function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
  }

  function asText(value) {
    return typeof value === 'string' ? value : '';
  }

  /**
   * Resolve a destination against this origin. Returns path + search + hash,
   * or the fallback for anything cross-origin, malformed or non-http(s).
   */
  function sameOriginNavigation(value, fallback) {
    var safeFallback = typeof fallback === 'string' ? fallback : DEFAULT_URL;
    try {
      var candidate = new URL(String(value || safeFallback), origin());

      return candidate.origin === origin()
        ? candidate.pathname + candidate.search + candidate.hash
        : safeFallback;
    } catch (_) {
      return safeFallback;
    }
  }

  /** Same-origin asset path for icons, or the default when the payload value is unusable. */
  function sameOriginAsset(value, fallback) {
    if (typeof value !== 'string' || value === '') {
      return fallback;
    }
    try {
      var candidate = new URL(value, origin());

      return candidate.origin === origin() ? candidate.pathname + candidate.search : fallback;
    } catch (_) {
      return fallback;
    }
  }

  function pathAndSearch(value) {
    try {
      var candidate = new URL(String(value), origin());

      return candidate.origin === origin() ? candidate.pathname + candidate.search : null;
    } catch (_) {
      return null;
    }
  }

  function hashString(value) {
    var hash = 5381;
    for (var i = 0; i < value.length; i += 1) {
      hash = ((hash << 5) + hash + value.charCodeAt(i)) | 0;
    }

    return (hash >>> 0).toString(36);
  }

  // -------------------------------------------------------------------------
  // Payload parsing: current app format, Declarative Web Push, plain text.
  // -------------------------------------------------------------------------

  function readRawPayload(event) {
    var data = event && event.data;
    if (!data) {
      return {};
    }

    var text = '';
    try {
      text = data.text();
    } catch (_) {
      return {};
    }
    if (typeof text !== 'string' || text.trim() === '') {
      return {};
    }

    try {
      var parsed = JSON.parse(text);

      return isPlainObject(parsed) ? parsed : { body: String(parsed) };
    } catch (_) {
      return { body: text };
    }
  }

  function normalizeActions(list, urls) {
    var actions = [];
    if (!Array.isArray(list)) {
      return actions;
    }

    for (var i = 0; i < list.length && actions.length < MAX_ACTIONS; i += 1) {
      var item = list[i];
      if (!isPlainObject(item) || !asText(item.action) || !asText(item.title)) {
        continue;
      }

      var action = { action: item.action, title: item.title };
      var icon = sameOriginAsset(item.icon, '');
      if (icon) {
        action.icon = icon;
      }
      actions.push(action);

      var destination = asText(item.url) || asText(item.navigate);
      if (destination) {
        urls[item.action] = sameOriginNavigation(destination, DEFAULT_URL);
      }
    }

    return actions;
  }

  /** Map a Declarative Web Push message onto the current app payload shape. */
  function fromDeclarative(raw) {
    var n = raw.notification;
    var mapped = {
      title: n.title,
      body: n.body,
      icon: n.icon,
      badge: n.badge,
      tag: n.tag,
      actions: n.actions,
      requireInteraction: n.require_interaction !== undefined ? n.require_interaction : n.requireInteraction,
      renotify: n.renotify,
      silent: n.silent,
      vibrate: n.vibrate,
      data: isPlainObject(n.data) ? Object.assign({}, n.data) : {},
    };
    if (n.navigate && !mapped.data.url) {
      mapped.data.url = n.navigate;
    }

    return mapped;
  }

  function buildNotification(raw) {
    var payload = isPlainObject(raw) ? raw : {};
    if (payload.web_push === DECLARATIVE_PUSH_MARKER && isPlainObject(payload.notification)) {
      payload = fromDeclarative(payload);
    }

    var data = isPlainObject(payload.data) ? Object.assign({}, payload.data) : {};
    var title = asText(payload.title) || DEFAULT_TITLE;
    var body = asText(payload.body) || DEFAULT_BODY;
    var url = sameOriginNavigation(data.url || payload.url || payload.navigate, DEFAULT_URL);
    var actionUrls = {};
    var actions = normalizeActions(payload.actions, actionUrls);

    data.url = url;
    if (Object.keys(actionUrls).length > 0) {
      data.mbfdActionUrls = actionUrls;
    }

    // Same tag replaces. Without a tag, identical alerts (for example one push
    // delivered through two registrations) collapse while different alerts stack.
    var tag = asText(payload.tag) || 'mbfd-' + hashString(title + '\n' + body + '\n' + url);

    var options = {
      body: body,
      icon: sameOriginAsset(payload.icon, DEFAULT_ICON),
      badge: sameOriginAsset(payload.badge, DEFAULT_BADGE),
      data: data,
      tag: tag,
      requireInteraction: typeof payload.requireInteraction === 'boolean' ? payload.requireInteraction : true,
      vibrate: Array.isArray(payload.vibrate) ? payload.vibrate : DEFAULT_VIBRATE,
    };
    if (actions.length > 0) {
      options.actions = actions;
    }
    if (payload.renotify === true) {
      options.renotify = true;
    }

    return { title: title, options: options };
  }

  // -------------------------------------------------------------------------
  // push
  // -------------------------------------------------------------------------

  async function showPush(event) {
    var built;
    try {
      built = buildNotification(readRawPayload(event));
    } catch (_) {
      built = buildNotification({});
    }

    try {
      await scope.registration.showNotification(built.title, built.options);
    } catch (_) {
      // A rejected option set (bad icon URL, unsupported action) must still end
      // in a visible notification, otherwise the push counts as silent.
      try {
        await scope.registration.showNotification(built.title, {
          body: built.options.body,
          tag: built.options.tag,
          data: built.options.data,
        });
      } catch (_ignored) {
        // Nothing more can be shown; do not turn this into an unhandled rejection.
      }
    }
  }

  function onPush(event) {
    event.waitUntil(showPush(event));
  }

  // -------------------------------------------------------------------------
  // notificationclick
  // -------------------------------------------------------------------------

  function resolveClickTarget(notification, action) {
    var data = isPlainObject(notification && notification.data) ? notification.data : {};
    var byAction = isPlainObject(data.mbfdActionUrls) ? data.mbfdActionUrls : {};

    if (action && typeof byAction[action] === 'string') {
      return sameOriginNavigation(byAction[action], DEFAULT_URL);
    }

    return sameOriginNavigation(data.url, DEFAULT_URL);
  }

  function isSameOriginClient(client) {
    return pathAndSearch(client && client.url) !== null;
  }

  function rankClient(client) {
    return (client.focused ? 2 : 0) + (client.visibilityState === 'visible' ? 1 : 0);
  }

  async function focusClient(client) {
    try {
      if (client && typeof client.focus === 'function') {
        await client.focus();
      }
    } catch (_) {
      // Focus can be refused by the platform; routing continues.
    }
  }

  /**
   * Ask a page to navigate itself. Resolves true when the page acknowledged
   * (even if it deferred the navigation because the member has unsaved work),
   * false when it never answered, for example because that page has no
   * MBFD_NAVIGATE listener.
   */
  function askPageToNavigate(client, target) {
    return new Promise(function (resolve) {
      var settled = false;
      var channel = null;
      var timer = null;

      function finish(value) {
        if (settled) {
          return;
        }
        settled = true;
        if (timer !== null) {
          clearTimeout(timer);
        }
        try {
          if (channel) {
            channel.port1.onmessage = null;
            channel.port1.close();
          }
        } catch (_) {
          // Port already closed.
        }
        resolve(value);
      }

      try {
        channel = new MessageChannel();
        channel.port1.onmessage = function () {
          finish(true);
        };
        client.postMessage({ type: 'MBFD_NAVIGATE', url: target }, [channel.port2]);
        timer = setTimeout(function () {
          finish(false);
        }, NAVIGATE_ACK_TIMEOUT_MS);
      } catch (_) {
        finish(false);
      }
    });
  }

  /** Resolves when the promise settles or after ms, whichever comes first. Never rejects. */
  function settleWithin(promise, ms) {
    return new Promise(function (resolve) {
      var timer = setTimeout(resolve, ms);
      function done() {
        clearTimeout(timer);
        resolve();
      }
      promise.then(done, done);
    });
  }

  async function openTarget(target) {
    try {
      await scope.clients.openWindow(target);
    } catch (_) {
      // openWindow can be refused outside a user-initiated click; nothing else to try.
    }
  }

  async function routeToTarget(target) {
    var clientList = [];
    try {
      clientList = await scope.clients.matchAll({ type: 'window', includeUncontrolled: true });
    } catch (_) {
      clientList = [];
    }

    var candidates = clientList.filter(isSameOriginClient);
    var wanted = pathAndSearch(target);

    // (a) A window already shows exactly this destination: just bring it forward.
    var exact = candidates.filter(function (client) {
      return pathAndSearch(client.url) === wanted;
    })[0];
    if (exact) {
      await focusClient(exact);

      return;
    }

    // (b) Another Hub window exists: focus it and let the page decide, because it
    // may hold unsaved work. A page that never answers gets a new window instead
    // of a silently dropped deep link.
    if (candidates.length > 0) {
      var best = candidates.slice().sort(function (a, b) {
        return rankClient(b) - rankClient(a);
      })[0];
      // Focus and ask at the same time, and start the acknowledgement timer immediately: a slow
      // focus() must not eat into the time openWindow still has before the browser stops
      // treating this as a user-initiated click.
      var focusing = focusClient(best);
      var acknowledged = await askPageToNavigate(best, target);
      if (acknowledged) {
        await settleWithin(focusing, NAVIGATE_ACK_TIMEOUT_MS);

        return;
      }
    }

    // (c) No usable window.
    await openTarget(target);
  }

  function onNotificationClick(event) {
    var notification = event.notification;
    try {
      notification.close();
    } catch (_) {
      // Already closed.
    }

    if (event.action && DISMISS_ACTIONS.indexOf(event.action) !== -1) {
      return;
    }

    event.waitUntil(routeToTarget(resolveClickTarget(notification, event.action)));
  }

  // -------------------------------------------------------------------------
  // pushsubscriptionchange (best effort, never throws)
  // -------------------------------------------------------------------------

  async function readCsrfHeaders() {
    var headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    try {
      if (scope.cookieStore && typeof scope.cookieStore.get === 'function') {
        var cookie = await scope.cookieStore.get('XSRF-TOKEN');
        if (cookie && cookie.value) {
          headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.value);
        }
      }
    } catch (_) {
      // cookieStore is not available in every browser; the request is still attempted.
    }

    return headers;
  }

  async function postSubscription(subscription) {
    var json = subscription.toJSON();
    var keys = isPlainObject(json.keys) ? json.keys : {};
    if (!json.endpoint || !keys.p256dh || !keys.auth) {
      return;
    }

    await fetch(SUBSCRIPTION_ENDPOINT, {
      method: 'POST',
      headers: await readCsrfHeaders(),
      credentials: 'same-origin',
      body: JSON.stringify({ endpoint: json.endpoint, keys: { p256dh: keys.p256dh, auth: keys.auth } }),
    });
  }

  async function refreshSubscription(event) {
    try {
      var subscription = event.newSubscription || null;

      if (!subscription) {
        var previous = event.oldSubscription;
        var key = previous && previous.options ? previous.options.applicationServerKey : null;
        if (!key) {
          return;
        }
        subscription = await scope.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: key,
        });
      }

      await postSubscription(subscription);
    } catch (_) {
      // Best effort. The Hub re-syncs the subscription the next time the member
      // opens notification settings; the browser may also have no session here.
    }
  }

  function onPushSubscriptionChange(event) {
    event.waitUntil(refreshSubscription(event));
  }

  // -------------------------------------------------------------------------
  // message: update protocol
  // -------------------------------------------------------------------------

  function workerBuildId() {
    return typeof MBFD_WORKER_BUILD_ID === 'string' ? MBFD_WORKER_BUILD_ID : 'unknown';
  }

  /** Only a window or worker of this Hub origin may drive the update protocol. */
  function isSameOriginSource(event) {
    var source = event && event.source;
    if (!source || typeof source.url !== 'string') {
      return false;
    }
    try {
      return new URL(source.url).origin === origin();
    } catch (_) {
      return false;
    }
  }

  function onMessage(event) {
    if (!isSameOriginSource(event)) {
      return;
    }
    var message = event.data;
    if (!isPlainObject(message)) {
      return;
    }

    if (message.type === 'SKIP_WAITING') {
      scope.skipWaiting();

      return;
    }

    if (message.type === 'GET_VERSION') {
      var reply = { type: 'MBFD_VERSION', build: workerBuildId(), core: MBFD_WORKER_CORE_VERSION };
      try {
        if (event.ports && event.ports[0]) {
          event.ports[0].postMessage(reply);
        } else if (event.source && typeof event.source.postMessage === 'function') {
          event.source.postMessage(reply);
        }
      } catch (_) {
        // The asking page went away.
      }
    }
  }

  scope.addEventListener('push', onPush);
  scope.addEventListener('notificationclick', onNotificationClick);
  scope.addEventListener('pushsubscriptionchange', onPushSubscriptionChange);
  scope.addEventListener('message', onMessage);
})(self);
/* MBFD_WORKER_CORE:END */

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});
