import {
    POLL_INTERVAL_MS,
    RELOAD_NOTE,
    ROW_SELECTOR,
    SIGN_IN_NOTE,
    STALE_CHECK_MS,
    bodyHasBoard,
    buildMismatch,
    classifyResponse,
    describeChanges,
    failureNote,
    isStale,
    nextDelay,
    profileChanged,
    snapshotRows,
    staleNote,
    toEntityTag,
} from './unit-status/board-state.js';

/**
 * Unit Status: the profile dialog and an in-place refresh. A new board replaces
 * [data-us-board]; the page itself never reloads and no worker or busy registry is touched.
 * The server renders and escapes the board HTML; nothing from JSON is ever written as HTML.
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
    let dialogMarkup = '';
    let confirmedAt = Date.now();
    let failed = false;
    let stale = false;
    let signedOut = false;
    let reloadNeeded = false;

    /* ---------- profile dialog ---------- */

    function template(id) {
        return page.querySelector(`[data-us-profile-template="${CSS.escape(id)}"]`);
    }

    function fillDialog(id) {
        const source = template(id);
        if (!source || !dialog) {
            return false;
        }
        const fresh = source.content.cloneNode(true);
        dialogContent.replaceChildren(fresh);
        dialog.setAttribute('aria-labelledby', `${id}-full-title`);
        dialogUnit = id;
        dialogMarkup = source.innerHTML;
        if (stale) {
            downgradeChips(dialogContent);
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
        dialogMarkup = '';
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

    /* ---------- clicks ---------- */

    page.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const open = target?.closest('[data-us-open]');
        if (open) {
            openProfile(open.dataset.usOpen, open);
            return;
        }
        if (target?.closest('[data-us-reload]')) {
            window.location.reload(); // only on this button: the member chooses when to lose their place
            return;
        }
        if (target?.closest('[data-us-dialog-close]')) {
            dialog?.close();
            return;
        }
    });

    /* ---------- refresh ---------- */

    function snapshot() {
        return snapshotRows(page.querySelectorAll(ROW_SELECTOR));
    }

    function renderNote() {
        let text = '';
        if (reloadNeeded) {
            text = RELOAD_NOTE;
        } else if (signedOut) {
            text = SIGN_IN_NOTE;
        } else if (stale) {
            text = staleNote(board()?.dataset.updatedLabel);
        } else if (failed) {
            text = failureNote(board()?.dataset.updatedLabel);
        }
        note.replaceChildren();
        if (text) {
            note.append(text);
            if (reloadNeeded) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'hub-btn hub-btn--secondary us-reload';
                button.dataset.usReload = '';
                button.textContent = 'Reload';
                note.append(' ', button);
            } else if (signedOut) {
                const link = document.createElement('a');
                link.href = loginUrl;
                link.textContent = 'Sign in';
                note.append(' ', link);
            }
        }
        note.hidden = !text;
    }

    /** Never green while stale: an Available chip becomes the warning "Not confirmed" chip. */
    function downgradeChips(root) {
        root.querySelectorAll('.us-chip.hub-tag--success:not([data-us-original])').forEach((chip) => {
            chip.dataset.usOriginal = chip.innerHTML;
            chip.classList.remove('hub-tag--success', 'us-tone--available');
            chip.classList.add('hub-tag--warning', 'us-tone--not_confirmed');
            const dot = document.createElement('span');
            dot.className = 'us-dot';
            dot.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.textContent = 'Not confirmed';
            chip.replaceChildren(dot, label);
        });
    }

    function evaluateStale(force = false) {
        const root = board();
        if (!root) {
            return;
        }
        stale = force || isStale(root.dataset.generatedAt, confirmedAt, Date.now());
        root.toggleAttribute('data-stale', stale);
        if (stale) {
            downgradeChips(root);
            if (dialog?.open) {
                downgradeChips(dialogContent);
            }
        }
        renderNote();
    }

    function refreshDialog() {
        const source = dialog?.open && dialogUnit ? template(dialogUnit) : null;
        if (!source || !profileChanged(dialogMarkup, source.innerHTML)) {
            return;
        }
        const key = dialogContent.contains(document.activeElement) ? document.activeElement.dataset.focusKey : null;
        fillDialog(dialogUnit);
        const target = (key ? dialogContent.querySelector(`[data-focus-key="${CSS.escape(key)}"]`) : null)
            ?? dialog.querySelector('[data-us-dialog-close]');
        target?.focus({ preventScroll: true });
    }

    function swapBoard(html) {
        const next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-us-board]');
        if (!next) {
            throw new Error('The response had no board.');
        }
        if (buildMismatch(page.dataset.build, next.dataset.build)) {
            reloadNeeded = true;
            stopped = true;
            window.clearTimeout(timer);
            evaluateStale();
            return false;
        }
        const scrollY = window.scrollY;
        const active = document.activeElement;
        const focusId = active?.id && board().contains(active) ? active.id : null;

        board().replaceWith(document.adoptNode(next));

        refreshDialog();
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
        return true;
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
        failed = true;
        evaluateStale();
        schedule(nextDelay(failures));
    }

    function stop() {
        stopped = true;
        signedOut = true;
        window.clearTimeout(timer);
        controller?.abort();
        evaluateStale(true);
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
            const response = await fetch(boardUrl, {
                cache: 'no-store', credentials: 'same-origin', redirect: 'manual', headers, signal: own.signal,
            });
            const kind = classifyResponse(response);
            if (kind === 'auth') {
                stop();
                return;
            }
            if (kind === 'not_modified') {
                confirmedAt = Date.now();
            } else if (kind === 'ok') {
                const html = await response.text();
                if (!bodyHasBoard(html)) {
                    stop();
                    return;
                }
                if (!swapBoard(html)) {
                    return;
                }
                etag = toEntityTag(response.headers.get('ETag')) ?? toEntityTag(board()?.dataset.etag);
                confirmedAt = Date.now();
            } else {
                throw new Error(String(response.status));
            }
            failures = 0;
            failed = false;
            evaluateStale();
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

    window.setInterval(() => evaluateStale(), STALE_CHECK_MS);
    schedule(POLL_INTERVAL_MS);
}

const page = document.querySelector('[data-us-page]');
if (page) {
    initUnitStatus(page);
}
