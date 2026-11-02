// Home page visit counts and who signed in, collapsed on the Admin Overview (see hub-shell.js). Reads nothing until it
// is opened, and refreshes only while it is open and on screen.

const POLL_MS = 60_000;
const MAX_BACKOFF_MS = 600_000;
const numbers = new Intl.NumberFormat('en-US');

const card = document.querySelector('[data-home-activity]');
if (card) {
    startCounters(card);
    startSignInList(card);
    startDismissal(card);
}

function memberLabel(count) {
    return `${numbers.format(count)} ${count === 1 ? 'member' : 'members'} signed in`;
}

function startCounters(root) {
    const past = root.querySelector('[data-activity-past]');
    const total = root.querySelector('[data-activity-total]');
    const caption = root.querySelector('[data-activity-caption]');
    const label = root.querySelector('[data-activity-link-label]');
    let delay = POLL_MS;
    let timer = 0;
    let busy = false;
    let loaded = false;

    function show(data) {
        if (!Number.isFinite(data.past_24_hours) || !Number.isFinite(data.total)) throw new Error('unexpected payload');
        past.textContent = numbers.format(data.past_24_hours);
        total.textContent = numbers.format(data.total);
        caption.textContent = typeof data.since === 'string' && data.since ? `Since ${data.since}` : 'No visits recorded yet';
        if (Number.isFinite(data.signed_in)) label.textContent = memberLabel(data.signed_in);
        loaded = true;
    }

    function schedule() {
        clearTimeout(timer);
        if (root.open && document.visibilityState === 'visible') timer = setTimeout(refresh, delay);
    }

    async function refresh() {
        if (busy || !root.open) return;
        busy = true;
        try {
            // Marked as a background refresh so a card left open cannot keep the session alive on an unattended screen.
            const response = await fetch(root.dataset.summaryUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Hub-Background-Refresh': '1' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok) throw new Error(`status ${response.status}`);
            show(await response.json());
            delay = POLL_MS;
        } catch {
            delay = Math.min(delay * 2, MAX_BACKOFF_MS);
            // Numbers already on screen stay; only a card that never loaded says so.
            if (!loaded) caption.textContent = 'Could not load the counts. Close this and open it again to retry.';
        } finally {
            busy = false;
            schedule();
        }
    }

    root.addEventListener('toggle', () => {
        delay = POLL_MS;
        if (root.open) refresh();
        else clearTimeout(timer);
    });
    document.addEventListener('visibilitychange', () => {
        if (root.open && document.visibilityState === 'visible') refresh();
    });
    window.addEventListener('online', () => refresh());
    // This module loads lazily, so on a slow connection the member may have opened the card before it got here.
    if (root.open) refresh();
}

function startSignInList(root) {
    const dialog = root.querySelector('[data-activity-dialog]');
    const open = root.querySelector('[data-activity-open]');
    if (!dialog || !open || typeof dialog.showModal !== 'function') return;

    const label = root.querySelector('[data-activity-link-label]');
    const list = root.querySelector('[data-activity-list]');
    const states = {
        loading: root.querySelector('[data-activity-loading]'),
        empty: root.querySelector('[data-activity-empty]'),
        error: root.querySelector('[data-activity-error]'),
        list,
    };
    let latest = 0;

    function show(state) {
        for (const [name, element] of Object.entries(states)) element.hidden = name !== state;
    }

    function row(member) {
        const item = document.createElement('li');
        item.className = 'mbfd-visits-dialog__row';
        const name = document.createElement('span');
        name.className = 'mbfd-visits-dialog__name';
        name.textContent = String(member.name ?? '');
        const when = document.createElement('span');
        when.className = 'mbfd-visits-dialog__when';
        when.textContent = String(member.at ?? '');
        item.append(name, when);
        return item;
    }

    async function load() {
        const mine = ++latest;
        show('loading');
        try {
            const response = await fetch(root.dataset.membersUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok) throw new Error(`status ${response.status}`);
            const data = await response.json();
            if (!Array.isArray(data.members)) throw new Error('unexpected payload');
            if (mine !== latest) return;
            list.replaceChildren(...data.members.map(row));
            show(data.members.length > 0 ? 'list' : 'empty');
            if (Number.isFinite(data.count)) label.textContent = memberLabel(data.count);
        } catch {
            if (mine === latest) show('error');
        }
    }

    open.addEventListener('click', () => {
        if (!dialog.open) dialog.showModal();
        load();
    });
    root.querySelector('[data-activity-close]')?.addEventListener('click', () => dialog.close());
    root.querySelector('[data-activity-retry]')?.addEventListener('click', load);
    // Clicks on the dimmed page land on the dialog element itself, outside its box.
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        const inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
        if (!inside) dialog.close();
    });
    dialog.addEventListener('close', () => {
        latest += 1;
        open.focus();
    });
}

// A tap elsewhere or Escape folds the card back up, unless the sign-in list is open on top of it.
function startDismissal(root) {
    const dialog = root.querySelector('[data-activity-dialog]');
    const summary = root.querySelector('summary');

    document.addEventListener('click', event => {
        if (root.open && !dialog?.open && !root.contains(event.target)) root.open = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !root.open || dialog?.open) return;
        root.open = false;
        summary?.focus();
    });
}
