/**
 * Daily used to live only on the primary host, so a device installed on the apex saved its inspections there.
 * Now that an installed window stays on its own host, unsent work from before that change is in the other origin's
 * storage. Nothing is moved: a hidden same-site frame on the primary host counts what is waiting, and the page
 * offers the way back to it. See the /daily-saved-work-probe route.
 */

export const SAVED_WORK_MESSAGE = 'MBFD_DAILY_SAVED_WORK';
const ANSWER_TIMEOUT_MS = 6_000;

declare global {
  interface Window {
    __MBFD_PRIMARY_ORIGIN__?: string;
  }
}

/** The primary origin, when this page is running on the other host. */
export const otherPrimaryOrigin = (view: Pick<Window, '__MBFD_PRIMARY_ORIGIN__' | 'location'> = window): string | null => {
  const primary = view.__MBFD_PRIMARY_ORIGIN__;

  return typeof primary === 'string' && primary !== '' && primary !== view.location.origin ? primary : null;
};

/** How many unsent records the primary origin holds on this device. Anything unexpected reads as none. */
export const countSavedWorkElsewhere = (primaryOrigin: string, doc: Document = document, timeoutMs = ANSWER_TIMEOUT_MS): Promise<number> => new Promise((resolve) => {
  const view = doc.defaultView;
  if (!view) {
    resolve(0);
    return;
  }
  const frame = doc.createElement('iframe');
  frame.hidden = true;
  frame.title = '';
  frame.setAttribute('aria-hidden', 'true');
  frame.src = `${primaryOrigin}/daily-saved-work-probe`;

  let timer: ReturnType<typeof setTimeout> | undefined;
  const finish = (count: number) => {
    clearTimeout(timer);
    view.removeEventListener('message', onMessage);
    frame.remove();
    resolve(count);
  };
  const onMessage = (event: MessageEvent) => {
    if (event.origin !== primaryOrigin || event.source !== frame.contentWindow || event.data?.type !== SAVED_WORK_MESSAGE) return;
    const pending = Number(event.data.pending);
    finish(Number.isFinite(pending) && pending > 0 ? Math.floor(pending) : 0);
  };
  view.addEventListener('message', onMessage);
  timer = setTimeout(() => finish(0), timeoutMs);
  doc.body.appendChild(frame);
});
