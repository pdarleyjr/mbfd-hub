// Live numbers on the Home visit card, and the list of members who signed in. Loaded only on pages that have the
// card (see hub-shell.js). Every failure is silent: the numbers already on screen stay.

const POLL_MS = 60_000;
const MAX_BACKOFF_MS = 600_000;
// A page that comes back into view only refreshes if the last fetch is older than this.
const MIN_GAP_MS = 10_000;
const numbers = new Intl.NumberFormat('en-US');

const card = document.querySelector('[data-home-activity]');
if (card) {
    startCounters(card);
    if (card.dataset.membersUrl) startSignInList(card);
}

function startCounters(root) {
    const past = root.querySelector('[data-activity-past]');
    const total = root.querySelector('[data-activity-total]');
    const caption = root.querySelector('[data-activity-caption]');
    let delay = POLL_MS;
    let timer = 0;
    let busy = false;
    let lastAttempt = Date.now();

    function show(data) {
        if (!Number.isFinite(data.past_24_hours) || !Number.isFinite(data.total)) throw new Error('unexpected payload');
        past.textContent = numbers.format(data.past_24_hours);
        total.textContent = numbers.format(data.total);
        caption.textContent = typeof data.since === 'string' && data.since ? `Home page visits since ${data.since}` : 'Home page visits';
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(tick, delay);
    }

    // A hidden page pauses the chain; becoming visible again restarts it.
    function tick() {
        if (document.visibilityState === 'visible') refresh();
    }

    async function refresh() {
        if (busy) return;
        busy = true;
        lastAttempt = Date.now();
        try {
            // Marked as a background refresh so an unattended Home page cannot keep the member's session alive.
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
        } finally {
            busy = false;
            schedule();
        }
    }

    function resume() {
        if (document.visibilityState !== 'visible') return;
        if (Date.now() - lastAttempt >= MIN_GAP_MS) refresh();
        else schedule();
    }

    document.addEventListener('visibilitychange', resume);
    window.addEventListener('online', resume);
    schedule();
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
        item.className = 'home-activity-dialog__row hub-well';
        const name = document.createElement('span');
        name.className = 'home-activity-dialog__name';
        name.textContent = String(member.name ?? '');
        const when = document.createElement('span');
        when.className = 'home-activity-dialog__when';
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
            if (label && Number.isFinite(data.count)) {
                label.textContent = `${numbers.format(data.count)} ${data.count === 1 ? 'member' : 'members'} signed in`;
            }
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
