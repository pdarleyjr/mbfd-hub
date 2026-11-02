/**
 * Home greeting block: keeps the greeting, the date, the shift on duty and the 300 current on a page left
 * open. It swaps text in a few known elements and nothing else, so it can never disturb what a member is
 * doing elsewhere on the page. The server composes every line (App\Services\DailyRoster\HomeDutyStatus); this
 * only places it. Nothing is stored: the version lives in memory and the response is never cached.
 *
 * A request is made when the clock crosses a greeting or duty-day boundary the server named, when the page
 * comes back into view, and every minute while it is visible. It never asks while hidden or offline, and it is
 * marked as a background refresh so an unattended Home page cannot keep a member's session alive.
 */
const POLL_MS = 60_000;
const MAX_BACKOFF_MS = 600_000;
/** Coming back into view only asks again if the last attempt is older than this. */
const MIN_GAP_MS = 10_000;
/** A moment after the boundary, so the server's clock is certain to be past it. */
const BOUNDARY_GRACE_MS = 1_500;

function initHomeDuty(root) {
    const slot = (name) => root.querySelector(`[data-home-${name}]`);
    const greeting = slot('greeting');
    const dateLabel = slot('date-label');
    const shiftWrap = slot('shift-wrap');
    const shift = slot('shift');
    const command = slot('command');
    const meta = slot('meta');
    const asOf = slot('as-of');
    const separator = slot('meta-separator');
    const rosterLink = slot('roster-link');
    const announce = slot('announce');

    let version = root.dataset.version;
    let nextChange = Date.parse(root.dataset.nextChange);
    let delay = POLL_MS;
    let pollTimer = 0;
    let boundaryTimer = 0;
    let busy = false;
    let lastAttempt = Date.now();
    let ended = false;

    const setText = (element, text) => {
        if (element && element.textContent !== text) {
            element.textContent = text;
        }
    };

    function apply(data) {
        if (typeof data.greeting !== 'string' || typeof data.date?.label !== 'string') {
            throw new Error('unexpected payload');
        }
        const before = `${shift?.textContent}|${command?.textContent}`;

        setText(greeting, data.greeting);
        setText(dateLabel, data.date.label);
        dateLabel?.setAttribute('datetime', data.date.iso);

        shiftWrap.hidden = !data.shift;
        setText(shift, data.shift?.text ?? '');
        shift.dataset.state = data.shift?.state ?? '';

        command.hidden = !data.command;
        setText(command, data.command?.text ?? '');
        command.dataset.state = data.command?.state ?? '';

        asOf.hidden = !data.as_of;
        setText(asOf, data.as_of ?? '');
        rosterLink.hidden = !data.roster_url;
        // The link's text only exists while the member may open the roster, so it is never in the page otherwise.
        if (data.roster_url) {
            rosterLink.setAttribute('href', data.roster_url);
            setText(rosterLink, 'Daily Roster');
        } else {
            rosterLink.removeAttribute('href');
            setText(rosterLink, '');
        }
        separator.hidden = !data.as_of || !data.roster_url;
        meta.hidden = !data.as_of && !data.roster_url;

        // A change in who is on duty is worth saying aloud; a new time of day or date is not.
        if (before !== `${shift?.textContent}|${command?.textContent}` && announce) {
            announce.textContent = 'Shift information updated.';
        }

        version = data.version;
        nextChange = Date.parse(data.next_change_at);
        scheduleBoundary();
    }

    /** Access ended or the session did: take the person-specific lines off the screen for good. */
    function end() {
        ended = true;
        clearTimeout(pollTimer);
        clearTimeout(boundaryTimer);
        shiftWrap.hidden = true;
        command.hidden = true;
        meta.hidden = true;
        setText(command, '');
        setText(shift, '');
        setText(asOf, '');
        rosterLink.removeAttribute('href');
        setText(rosterLink, '');
    }

    function scheduleBoundary() {
        clearTimeout(boundaryTimer);
        if (ended || !Number.isFinite(nextChange)) {
            return;
        }
        const wait = Math.max(BOUNDARY_GRACE_MS, nextChange - Date.now() + BOUNDARY_GRACE_MS);
        boundaryTimer = setTimeout(refresh, wait);
    }

    function schedulePoll() {
        clearTimeout(pollTimer);
        if (!ended) {
            pollTimer = setTimeout(tick, delay);
        }
    }

    // A hidden page pauses the chain; becoming visible again restarts it.
    function tick() {
        if (document.visibilityState === 'visible') {
            refresh();
        }
    }

    async function refresh() {
        if (ended || busy) {
            return;
        }
        if (document.visibilityState !== 'visible' || navigator.onLine === false) {
            // The boundary passed while hidden or offline: resume() asks as soon as the page can.
            return;
        }
        busy = true;
        lastAttempt = Date.now();
        try {
            const response = await fetch(root.dataset.url, {
                cache: 'no-store',
                credentials: 'same-origin',
                redirect: 'manual',
                headers: {
                    Accept: 'application/json',
                    'If-None-Match': `"${version}"`,
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Hub-Background-Refresh': '1',
                },
            });
            if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 403) {
                end();
                return;
            }
            if (response.status !== 304) {
                if (!response.ok) {
                    throw new Error(`status ${response.status}`);
                }
                apply(await response.json());
            }
            delay = POLL_MS;
        } catch {
            // Silent: what is on screen stays, and the next attempt waits longer.
            delay = Math.min(delay * 2, MAX_BACKOFF_MS);
        } finally {
            busy = false;
            schedulePoll();
        }
    }

    function resume() {
        if (ended || document.visibilityState !== 'visible') {
            return;
        }
        if (Date.now() >= nextChange || Date.now() - lastAttempt >= MIN_GAP_MS) {
            refresh();
        } else {
            schedulePoll();
        }
    }

    document.addEventListener('visibilitychange', resume);
    window.addEventListener('online', resume);
    schedulePoll();
    scheduleBoundary();
}

const root = document.querySelector('[data-home-duty]');
if (root) {
    initHomeDuty(root);
}
