// Temporary Playwright profile only. No content scripts or application mutations.
globalThis.setLocalZoom = async (url, factor) => {
    const target = new URL(url);
    if (target.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(target.hostname)) {
        throw new Error('Browser zoom acceptance only permits the local test server.');
    }
    const tabs = await chrome.tabs.query({ url: `${target.origin}/*` });
    const tab = tabs.find(candidate => candidate.url === url);
    if (!tab?.id) throw new Error('Local acceptance tab was not found.');
    await chrome.tabs.setZoomSettings(tab.id, { mode: 'automatic', scope: 'per-tab' });
    await chrome.tabs.setZoom(tab.id, factor);
    return { factor: await chrome.tabs.getZoom(tab.id), settings: await chrome.tabs.getZoomSettings(tab.id) };
};
chrome.runtime.onInstalled.addListener(() => {});
