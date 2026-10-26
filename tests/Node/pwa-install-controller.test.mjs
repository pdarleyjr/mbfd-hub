import assert from 'node:assert/strict';
import test from 'node:test';

import {
  IMPRESSIONS_STORAGE_KEY,
  MAX_UNANSWERED_IMPRESSIONS,
  SNOOZE_DAYS,
  SNOOZE_STORAGE_KEY,
  createInstallController,
  detectBrowser,
  detectEnvironment,
  detectPlatform,
  isPromotionEligible,
  resolveMethod,
  shouldShowPromotion,
} from '../../resources/js/pwa/install-controller.ts';
import { afterTipFor, guideFor } from '../../resources/js/pwa/install-ui.ts';

const UA = {
  iphoneSafari: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1',
  iphoneChrome: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.6778.73 Mobile/15E148 Safari/604.1',
  iphoneEdge: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 EdgiOS/131.0.2903.68 Mobile/15E148 Safari/605.1.15',
  iphoneFirefox: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/133.0 Mobile/15E148 Safari/605.1.15',
  iphoneInstagram: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.0',
  ipadDesktopMode: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15',
  macSafari: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15',
  macSafari16: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15',
  macChrome: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  androidChrome: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
  androidSamsung: 'Mozilla/5.0 (Linux; Android 14; SM-S921U) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
  androidFirefox: 'Mozilla/5.0 (Android 14; Mobile; rv:133.0) Gecko/133.0 Firefox/133.0',
  androidEdge: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 EdgA/131.0.2903.70',
  androidWebView: 'Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/131.0.0.0 Mobile Safari/537.36',
  windowsChrome: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  windowsEdge: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.2903.70',
  windowsFirefox: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
  linuxChrome: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  chromebook: 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
};

const methodFor = (userAgent, touch = 0, canPrompt = false) => resolveMethod(detectEnvironment(userAgent, touch), canPrompt);

test('platform detection covers phones, tablets in desktop mode and desktops', () => {
  assert.equal(detectPlatform(UA.iphoneSafari), 'ios');
  assert.equal(detectPlatform(UA.ipadDesktopMode, 5), 'ipados');
  assert.equal(detectPlatform(UA.macSafari, 0), 'macos');
  assert.equal(detectPlatform(UA.androidChrome), 'android');
  assert.equal(detectPlatform(UA.windowsEdge), 'windows');
  assert.equal(detectPlatform(UA.linuxChrome), 'linux');
  assert.equal(detectPlatform(UA.chromebook), 'chromeos');
});

test('browser detection names the real browser, not the engine', () => {
  assert.equal(detectBrowser(UA.iphoneChrome), 'chrome');
  assert.equal(detectBrowser(UA.iphoneEdge), 'edge');
  assert.equal(detectBrowser(UA.iphoneFirefox), 'firefox');
  assert.equal(detectBrowser(UA.iphoneSafari), 'safari');
  assert.equal(detectBrowser(UA.androidSamsung), 'samsung');
  assert.equal(detectBrowser(UA.androidEdge), 'edge');
  assert.equal(detectBrowser(UA.windowsEdge), 'edge');
  assert.equal(detectBrowser(UA.windowsChrome), 'chrome');
  assert.equal(detectBrowser(UA.windowsFirefox), 'firefox');
});

test('each device gets an accurate install method, and only a real prompt is "native"', () => {
  assert.equal(methodFor(UA.iphoneSafari), 'ios-safari');
  assert.equal(methodFor(UA.ipadDesktopMode, 5), 'ios-safari');
  assert.equal(methodFor(UA.iphoneChrome), 'ios-other');
  assert.equal(methodFor(UA.iphoneEdge), 'ios-other');
  assert.equal(methodFor(UA.iphoneFirefox), 'ios-other');
  assert.equal(methodFor(UA.iphoneInstagram), 'unsupported');
  assert.equal(methodFor(UA.androidChrome), 'chromium-menu');
  assert.equal(methodFor(UA.androidChrome, 0, true), 'native');
  assert.equal(methodFor(UA.androidSamsung), 'samsung-menu');
  assert.equal(methodFor(UA.androidFirefox), 'firefox-android');
  assert.equal(methodFor(UA.androidWebView), 'unsupported');
  assert.equal(methodFor(UA.windowsEdge), 'chromium-menu');
  assert.equal(methodFor(UA.windowsFirefox), 'unsupported');
  assert.equal(methodFor(UA.macSafari), 'mac-safari');
  assert.equal(methodFor(UA.macSafari16), 'unsupported');
  assert.equal(methodFor(UA.macChrome), 'chromium-menu');
  assert.equal(methodFor(UA.linuxChrome), 'chromium-menu');
  assert.equal(methodFor(UA.chromebook), 'chromium-menu');
});

test('each method opens the matching guide and after-install tip', () => {
  const state = (userAgent, touch = 0, canPrompt = false) => {
    const environment = detectEnvironment(userAgent, touch);
    return { installed: false, installedThisSession: false, canPrompt, platform: environment.platform, browser: environment.browser, method: resolveMethod(environment, canPrompt) };
  };
  assert.equal(guideFor(state(UA.iphoneSafari)), 'ios-safari');
  assert.equal(guideFor(state(UA.iphoneChrome)), 'ios-other');
  assert.equal(guideFor(state(UA.androidChrome, 0, true)), 'android-chrome');
  assert.equal(guideFor(state(UA.androidSamsung)), 'android-samsung');
  assert.equal(guideFor(state(UA.androidFirefox)), 'android-firefox');
  assert.equal(guideFor(state(UA.windowsEdge, 0, true)), 'desktop-chromium');
  assert.equal(guideFor(state(UA.macChrome)), 'mac-chromium');
  assert.equal(guideFor(state(UA.macSafari)), 'mac-safari');
  assert.equal(guideFor(state(UA.windowsFirefox)), 'other');
  assert.equal(afterTipFor(state(UA.windowsEdge)), 'windows');
  assert.equal(afterTipFor(state(UA.macSafari)), 'macos');
  assert.equal(afterTipFor(state(UA.chromebook)), 'linux');
  assert.equal(afterTipFor(state(UA.iphoneSafari)), null);
});

class FakeWindow {
  constructor({ userAgent = UA.windowsChrome, displayMode = 'browser', standalone, deferred = null, appInstalled = false, sessionStore, store } = {}) {
    this.listeners = new Map();
    this.mediaListeners = new Map();
    this.displayMode = displayMode;
    this.navigator = { userAgent, maxTouchPoints: 0, ...(standalone === undefined ? {} : { standalone }) };
    this.store = store ?? new Map();
    this.sessionStore = sessionStore ?? new Map();
    this.sessionStorage = {
      getItem: (key) => (this.sessionStore.has(key) ? this.sessionStore.get(key) : null),
      setItem: (key, value) => this.sessionStore.set(key, String(value)),
    };
    this.localStorage = {
      getItem: (key) => (this.store.has(key) ? this.store.get(key) : null),
      setItem: (key, value) => this.store.set(key, String(value)),
      removeItem: (key) => this.store.delete(key),
    };
    this.__mbfdDeferredInstall = deferred;
    this.__mbfdAppInstalled = appInstalled;
  }

  addEventListener(type, listener) {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
  }

  dispatch(type, event = { type }) {
    (this.listeners.get(type) ?? []).forEach((listener) => listener(event));
  }

  matchMedia(query) {
    return {
      matches: query === `(display-mode: ${this.displayMode})`,
      addEventListener: (type, listener) => this.mediaListeners.set(query, listener),
    };
  }

  setDisplayMode(mode) {
    this.displayMode = mode;
    this.mediaListeners.forEach((listener) => listener());
  }
}

function fakePrompt(outcome = 'accepted') {
  const calls = { prompt: 0, preventDefault: 0 };
  const event = {
    type: 'beforeinstallprompt',
    preventDefault: () => { calls.preventDefault += 1; },
    prompt: async () => { calls.prompt += 1; },
    userChoice: Promise.resolve({ outcome }),
  };
  return { event, calls };
}

test('a prompt captured by the shared head before the bundle loaded is adopted', () => {
  const { event } = fakePrompt();
  const controller = createInstallController(new FakeWindow({ deferred: event }));
  const state = controller.getState();
  assert.equal(state.canPrompt, true);
  assert.equal(state.method, 'native');
});

test('the controller captures later prompts itself and keeps the browser mini-infobar away', () => {
  const win = new FakeWindow();
  const controller = createInstallController(win);
  const seen = [];
  controller.subscribe((state) => seen.push(state.canPrompt));
  const { event, calls } = fakePrompt();
  win.dispatch('beforeinstallprompt', event);
  assert.equal(calls.preventDefault, 1);
  assert.deepEqual(seen, [true]);
  assert.equal(win.__mbfdDeferredInstall, event);
});

test('a prompt is used once; accepting marks the app installed for this page', async () => {
  const win = new FakeWindow();
  const controller = createInstallController(win);
  const { event, calls } = fakePrompt('accepted');
  win.dispatch('beforeinstallprompt', event);

  const [first, concurrent] = await Promise.all([controller.promptInstall(), controller.promptInstall()]);
  assert.equal(first, 'accepted');
  assert.equal(concurrent, 'accepted');
  assert.equal(calls.prompt, 1);
  assert.equal(await controller.promptInstall(), 'unavailable');
  assert.equal(win.__mbfdDeferredInstall, null);
  const state = controller.getState();
  assert.equal(state.canPrompt, false);
  assert.equal(state.installedThisSession, true);
  assert.equal(isPromotionEligible(state, controller), false);
});

test('a dismissed prompt reports dismissed and leaves no reusable prompt', async () => {
  const win = new FakeWindow();
  const controller = createInstallController(win);
  win.dispatch('beforeinstallprompt', fakePrompt('dismissed').event);
  assert.equal(await controller.promptInstall(), 'dismissed');
  assert.equal(controller.getState().canPrompt, false);
  assert.equal(controller.getState().installedThisSession, false);
});

test('with no prompt, promptInstall reports unavailable instead of doing nothing silently', async () => {
  assert.equal(await createInstallController(new FakeWindow()).promptInstall(), 'unavailable');
});

test('running as the installed app hides promotion and the install button', () => {
  for (const displayMode of ['standalone', 'window-controls-overlay', 'minimal-ui']) {
    const { event } = fakePrompt();
    const controller = createInstallController(new FakeWindow({ displayMode, deferred: event }));
    const state = controller.getState();
    assert.equal(state.installed, true, displayMode);
    assert.equal(state.canPrompt, false, displayMode);
    assert.equal(isPromotionEligible(state, controller), false, displayMode);
  }
  const ios = createInstallController(new FakeWindow({ userAgent: UA.iphoneSafari, standalone: true }));
  assert.equal(ios.getState().installed, true);
  // Browser full screen (F11) is not the installed app.
  assert.equal(createInstallController(new FakeWindow({ displayMode: 'fullscreen' })).getState().installed, false);
});

test('appinstalled and a display-mode change notify subscribers', () => {
  const win = new FakeWindow({ appInstalled: false });
  const controller = createInstallController(win);
  const seen = [];
  controller.subscribe((state) => seen.push([state.installed, state.installedThisSession]));
  win.dispatch('appinstalled');
  win.setDisplayMode('standalone');
  assert.deepEqual(seen[0], [false, true]);
  assert.deepEqual(seen.at(-1), [true, true]);
  assert.equal(createInstallController(new FakeWindow({ appInstalled: true })).getState().installedThisSession, true);
});

test('"Not now" snoozes automatic promotion for 30 days only', () => {
  let now = Date.UTC(2026, 9, 25);
  const win = new FakeWindow({ userAgent: UA.iphoneSafari });
  const controller = createInstallController(win, () => now);
  assert.equal(isPromotionEligible(controller.getState(), controller), true);
  controller.snoozePromotion();
  assert.equal(win.store.get(SNOOZE_STORAGE_KEY), String(now + SNOOZE_DAYS * 86_400_000));
  assert.equal(isPromotionEligible(controller.getState(), controller), false);
  now += (SNOOZE_DAYS - 1) * 86_400_000;
  assert.equal(controller.isPromotionSnoozed(), true);
  now += 2 * 86_400_000;
  assert.equal(controller.isPromotionSnoozed(), false);
});

test('only a real prompt or a Safari path is promoted; menu-only and unsupported browsers are not', () => {
  const eligible = (userAgent, deferred = null) => {
    const controller = createInstallController(new FakeWindow({ userAgent, deferred }));
    return isPromotionEligible(controller.getState(), controller);
  };
  assert.equal(eligible(UA.windowsFirefox), false);
  // Chromium without an offer is usually already installed in this profile.
  assert.equal(eligible(UA.windowsEdge), false);
  assert.equal(eligible(UA.androidChrome), false);
  assert.equal(eligible(UA.androidSamsung), false);
  assert.equal(eligible(UA.androidFirefox), false);
  assert.equal(eligible(UA.androidChrome, fakePrompt().event), true);
  assert.equal(eligible(UA.iphoneSafari), true);
  assert.equal(eligible(UA.iphoneChrome), true);
  assert.equal(eligible(UA.macSafari), true);
  assert.equal(eligible(UA.iphoneInstagram), false);
});

test('an iOS app web view without a Safari token is treated as an in-app browser', () => {
  const outlook = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
  assert.equal(detectEnvironment(outlook).inAppBrowser, true);
  assert.equal(methodFor(outlook), 'unsupported');
  assert.equal(detectEnvironment(UA.iphoneSafari).inAppBrowser, false);
});

test('blocked storage never breaks the controller', () => {
  const win = new FakeWindow();
  Object.defineProperty(win, 'localStorage', { get() { throw new Error('SecurityError'); } });
  const controller = createInstallController(win);
  assert.doesNotThrow(() => controller.snoozePromotion());
  assert.equal(controller.isPromotionSnoozed(), false);
});

test('the card shows on one page per session and snoozes itself after three unanswered sessions', () => {
  const store = new Map();
  let now = Date.UTC(2026, 9, 25);
  const page = (sessionStore) => createInstallController(new FakeWindow({ userAgent: UA.iphoneSafari, store, sessionStore }), () => now);

  const session1 = new Map();
  const home = page(session1);
  assert.equal(shouldShowPromotion(home.getState(), home, true), true);
  assert.equal(shouldShowPromotion(home.getState(), home, true), true, 'repeat renders on the same page agree');
  const nextPage = page(session1);
  assert.equal(shouldShowPromotion(nextPage.getState(), nextPage, true), false, 'another page in the same session stays quiet');
  assert.equal(store.get(IMPRESSIONS_STORAGE_KEY), '1');

  const offline = page(new Map());
  assert.equal(shouldShowPromotion(offline.getState(), offline, false), false, 'never offered offline');
  assert.equal(store.get(IMPRESSIONS_STORAGE_KEY), '1', 'an offline page does not spend the slot');

  const session2 = page(new Map());
  assert.equal(shouldShowPromotion(session2.getState(), session2, true), true);
  const session3 = page(new Map());
  assert.equal(shouldShowPromotion(session3.getState(), session3, true), true, `shown for the last time in session ${MAX_UNANSWERED_IMPRESSIONS}`);
  const session4 = page(new Map());
  assert.equal(shouldShowPromotion(session4.getState(), session4, true), false, 'then snoozed');
  now += (SNOOZE_DAYS + 1) * 86_400_000;
  const later = page(new Map());
  assert.equal(shouldShowPromotion(later.getState(), later, true), true, 'and offered again after the snooze');
});

test('"Not now" on the page that showed the card snoozes it there too', () => {
  const controller = createInstallController(new FakeWindow({ userAgent: UA.macSafari }));
  assert.equal(shouldShowPromotion(controller.getState(), controller, true), true);
  controller.snoozePromotion();
  assert.equal(controller.isPromotionSnoozed(), true);
  assert.equal(shouldShowPromotion(controller.getState(), controller, true), false);
});

test('Samsung Internet with a native prompt opens the Samsung guide', () => {
  const environment = detectEnvironment(UA.androidSamsung);
  const state = { installed: false, installedThisSession: false, canPrompt: true, platform: environment.platform, browser: environment.browser, method: resolveMethod(environment, true) };
  assert.equal(guideFor(state), 'android-samsung');
});