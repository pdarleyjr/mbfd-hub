import {
    POLL_INTERVAL_MS,
    SIGN_IN_NOTE,
    describeChanges,
    failureNote,
    isAuthStop,
    nextDelay,
    survivingIds,
    toEntityTag,
} from './unit-status/board-state.js';

/**
 * Unit Status: row disclosure, the profile dialog and an in-place refresh. A new board replaces
 * [data-us-board]; the page itself never reloads and no worker or busy registry is touched.
 * The server renders and escapes the board HTML; recent runs arrive as JSON and are written with textContent only.
 */
const REQUEST_TIMEOUT_MS = 15_000;
/** A timer asking is not a member using the Hub: the idle clock stays put, so a shared screen still signs out. */
const BACKGROUND_REFRESH = { 'X-Hub-Background-Refresh': '1' };

function initUnitStatus(page) {
    const boardUrl = page.dataset.boardUrl;
    const loginUrl = page.dataset.loginUrl || '/login';
    const dialog = page.querySelector('[data-us-dialog]');
    const dialogContent = page.querySelector('[data-us-dialog-content]');
    const announce = page.querySelector('[data-us-announce]');
    const note = page.querySelector('[data-us-refresh-note]');

    const board = () => page.querySelector('[data-us-board]');

    let etag = toEntityTag(board()?.dataset.etag);
    let failures = 0;
    let timer = null;
    let controller = null;
    let stopped = false;
    let dialogUnit = null;
    let dialogOpenerId = null;
    let lastSnapshot = snapshot();

    /* ---------- disclosure ---------- */

    function setExpanded(id, expanded) {
        const toggle = document.getElementById(`${id}-toggle`);
        const panel = document.getElementById(`${id}-panel`);
        if (!toggle || !panel) {
            return;
        }
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        panel.hidden = !expanded;
    }

    function expandedIds() {
        return [...page.querySelectorAll('[data-us-toggle][aria-expanded="true"]')].map((el) => el.dataset.usToggle);
    }

    /* ---------- profile dialog ---------- */

    function template(id) {
        return page.querySelector(`[data-us-profile-template="${CSS.escape(id)}"]`);
    }

    function fillDialog(id) {
        const source = template(id);
        if (!source || !dialog) {
            return false;
        }
        const previousRuns = dialogUnit === id ? dialogContent.querySelector('[data-us-runs]') : null;
        const fresh = source.content.cloneNode(true);
        const freshRuns = fresh.querySelector('[data-us-runs]');
        if (previousRuns && freshRuns) {
            freshRuns.replaceWith(previousRuns);
        }
        dialogContent.replaceChildren(fresh);
        dialog.setAttribute('aria-labelledby', `${id}-full-title`);
        dialogUnit = id;
        const runs = dialogContent.querySelector('[data-us-runs]');
        if (runs && !previousRuns) {
            loadRuns(runs);
        }
        return true;
    }

    function openProfile(id, opener) {
        if (!dialog || dialog.open || !fillDialog(id)) {
            return;
        }
        dialogOpenerId = opener?.id ?? null;
        document.body.classList.add('us-scroll-lock');
        dialog.showModal();
        dialogContent.scrollTop = 0;
    }

    dialog?.addEventListener('close', () => {
        document.body.classList.remove('us-scroll-lock');
        dialogContent.replaceChildren();
        dialog.removeAttribute('aria-labelledby');
        dialogUnit = null;
        const opener = dialogOpenerId ? document.getElementById(dialogOpenerId) : null;
        dialogOpenerId = null;
        opener?.focus();
    });

    // Only a click on the backdrop lands on the dialog element itself.
    dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    /* ---------- recent runs ---------- */

    async function loadRuns(section) {
        const list = section.querySelector('[data-us-runs-list]');
        const status = section.querySelector('[data-us-runs-status]');
        const more = section.querySelector('[data-us-runs-more]');
        const next = Number(section.dataset.page || 0) + 1;
        more.hidden = true;
        status.hidden = false;
        status.textContent = 'Loading recent runs';
        try {
            const response = await fetch(`${section.dataset.url}?page=${next}`, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', ...BACKGROUND_REFRESH },
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const body = await response.json();
            const runs = Array.isArray(body.runs) ? body.runs : [];
            runs.forEach((run) => list.append(runItem(run)));
            section.dataset.page = String(body.page ?? next);
            status.hidden = list.children.length > 0;
            status.textContent = list.children.length > 0 ? '' : 'No runs recorded yet';
            more.hidden = !body.hasMore;
        } catch {
            status.hidden = false;
            status.textContent = "Couldn't load recent runs. Try again.";
            more.hidden = false;
            more.textContent = 'Try again';
            return;
        }
        more.textContent = 'Show more';
    }

    function runItem(run) {
        const item = document.createElement('li');
        const title = document.createElement('span');
        title.textContent = [run.callType || 'Call type not given', run.idLabel].filter(Boolean).join(' · ');
        const meta = document.createElement('span');
        meta.className = 'us-runs-list__meta';
        meta.textContent = [
            run.attributedLabel,
            run.unitStatus || 'Assigned',
            run.confirmed ? null : 'unconfirmed',
            run.provenance === 'backfill' ? 'recovered after the fact' : null,
        ].filter(Boolean).join(' · ');
        item.append(title, meta);
        return item;
    }

    /* ---------- clicks ---------- */

    page.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const toggle = target?.closest('[data-us-toggle]');
        if (toggle) {
            setExpanded(toggle.dataset.usToggle, toggle.getAttribute('aria-expanded') !== 'true');
            return;
        }
        const open = target?.closest('[data-us-open]');
        if (open) {
            openProfile(open.dataset.usOpen, open);
            return;
        }
        if (target?.closest('[data-us-dialog-close]')) {
            dialog?.close();
            return;
        }
        const more = target?.closest('[data-us-runs-more]');
        if (more) {
            loadRuns(more.closest('[data-us-runs]'));
        }
    });

    /* ---------- refresh ---------- */

    function snapshot() {
        return [...page.querySelectorAll('[data-us-unit]')].map((row) => ({
            code: row.dataset.unitCode,
            status: row.dataset.status,
            summary: row.dataset.summary,
        }));
    }

    function showNote(text, withLogin = false) {
        note.replaceChildren();
        if (text) {
            note.append(text);
            if (withLogin) {
                const link = document.createElement('a');
                link.href = loginUrl;
                link.textContent = 'Sign in';
                note.append(' ', link);
            }
        }
        note.hidden = !text;
    }

    function swapBoard(html) {
        const next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-us-board]');
        if (!next) {
            throw new Error('The response had no board.');
        }
        const open = expandedIds();
        const scrollY = window.scrollY;
        const active = document.activeElement;
        const focusId = active?.id && board().contains(active) ? active.id : null;

        board().replaceWith(document.adoptNode(next));

        const available = [...page.querySelectorAll('[data-us-toggle]')].map((el) => el.dataset.usToggle);
        survivingIds(open, available).forEach((id) => setExpanded(id, true));
        if (dialog?.open && dialogUnit) {
            fillDialog(dialogUnit);
        }
        window.scrollTo({ top: scrollY, behavior: 'instant' });
        if (focusId) {
            document.getElementById(focusId)?.focus({ preventScroll: true });
        }

        const current = snapshot();
        const message = describeChanges(lastSnapshot, current);
        lastSnapshot = current;
        if (message && announce) {
            announce.textContent = message;
        }
    }

    function schedule(delay) {
        window.clearTimeout(timer);
        if (stopped || document.visibilityState !== 'visible') {
            return;
        }
        timer = window.setTimeout(check, delay);
    }

    function fail() {
        failures += 1;
        showNote(failureNote(board()?.dataset.updatedLabel));
        schedule(nextDelay(failures));
    }

    function stop() {
        stopped = true;
        window.clearTimeout(timer);
        controller?.abort();
        showNote(SIGN_IN_NOTE, true);
    }

    async function check() {
        window.clearTimeout(timer);
        if (stopped || document.visibilityState !== 'visible') {
            return;
        }
        controller?.abort();
        const own = new AbortController();
        controller = own;
        let timedOut = false;
        const limit = window.setTimeout(() => { timedOut = true; own.abort(); }, REQUEST_TIMEOUT_MS);
        try {
            const headers = { Accept: 'text/html', ...BACKGROUND_REFRESH };
            if (etag) {
                headers['If-None-Match'] = etag;
            }
            const response = await fetch(boardUrl, { cache: 'no-store', credentials: 'same-origin', headers, signal: own.signal });
            if (isAuthStop(response.status)) {
                stop();
                return;
            }
            if (response.status === 304) {
                failures = 0;
                showNote('');
            } else if (response.ok) {
                const html = await response.text();
                swapBoard(html);
                etag = toEntityTag(response.headers.get('ETag')) ?? toEntityTag(board()?.dataset.etag);
                failures = 0;
                showNote('');
            } else {
                throw new Error(String(response.status));
            }
            schedule(nextDelay(0));
        } catch (error) {
            if (own.signal.aborted && !timedOut) {
                return; // a newer check replaced this one
            }
            fail();
        } finally {
            window.clearTimeout(limit);
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            check();
        } else {
            window.clearTimeout(timer);
        }
    });

    schedule(POLL_INTERVAL_MS);
}

const page = document.querySelector('[data-us-page]');
if (page) {
    initUnitStatus(page);
}
