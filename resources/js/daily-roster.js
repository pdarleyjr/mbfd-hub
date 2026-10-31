/**
 * Daily Roster: client-side search and filter, plus an in-place refresh. A new roster replaces
 * [data-roster-body]; the page itself never reloads and no worker or busy registry is touched.
 * The version (ETag) lives in memory only, so nothing about the roster outlives the page.
 */
const POLL_INTERVAL_MS = 60_000;
const COUNT_DELAY_MS = 250;
const WIDE_LEGEND_QUERY = '(min-width: 640px)';
/** A timer asking is not a member using the Hub: the idle clock stays put, so a shared screen still signs out. */
const BACKGROUND_REFRESH = { 'X-Hub-Background-Refresh': '1' };

const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;

function initDailyRoster(page) {
    const announce = page.querySelector('[data-roster-announce]');
    const versionUrl = page.dataset.versionUrl;
    const sectionsUrl = page.dataset.sectionsUrl;
    const changesUrl = page.dataset.changesUrl;
    const loginUrl = page.dataset.loginUrl;

    const body = () => page.querySelector('[data-roster-body]');
    const part = (selector) => body()?.querySelector(selector) ?? null;
    const sections = () => [...(body()?.querySelectorAll('[data-roster-section]') ?? [])];

    let version = body().dataset.version;
    let etag = body().dataset.etag;
    let checking = false;
    let manualQueued = false;
    let closedBeforeFilter = null;
    let countTimer = null;
    let pollTimer = null;
    let statusMessage = '';
    let ended = false;

    function showStatus(message) {
        statusMessage = message;
        const element = part('[data-roster-connection]');
        if (element) {
            element.textContent = message;
            element.hidden = message === '';
        }
    }

    // Closed keys, not open ones: a section this page has not seen yet renders open.
    function closedSectionKeys() {
        return new Set(sections().filter((section) => !section.open).map((section) => section.dataset.sectionKey));
    }

    function restoreClosedSections(keys) {
        sections().forEach((section) => { section.open = !keys.has(section.dataset.sectionKey); });
    }

    function applyFilter() {
        const search = part('[data-roster-search]');
        if (!search) {
            return;
        }
        const query = search.value.trim().toLowerCase();
        const status = part('[data-roster-status]').value;
        const filtering = query !== '' || status !== '';

        if (filtering && closedBeforeFilter === null) {
            closedBeforeFilter = closedSectionKeys();
        }

        let shownRows = 0;
        let shownSections = 0;
        let totalRows = 0;
        const allSections = sections();
        allSections.forEach((section) => {
            const unitMatches = query !== '' && section.dataset.search.includes(query);
            let sectionRows = 0;
            section.querySelectorAll('[data-roster-seat]').forEach((seat) => {
                let seatRows = 0;
                seat.querySelectorAll('[data-roster-row]').forEach((row) => {
                    totalRows += 1;
                    const matches = (query === '' || unitMatches || row.dataset.search.includes(query))
                        && (status === '' || row.dataset.status === status);
                    row.hidden = !matches;
                    // The first row still showing carries the seat's rank; the rest stay grouped under it.
                    row.classList.toggle('roster-row--seat-lead', matches && seatRows === 0);
                    seatRows += matches ? 1 : 0;
                });
                seat.hidden = seatRows === 0;
                sectionRows += seatRows;
            });
            section.hidden = sectionRows === 0;
            if (filtering && sectionRows > 0) {
                section.open = true;
            }
            shownRows += sectionRows;
            shownSections += sectionRows > 0 ? 1 : 0;
        });

        if (!filtering && closedBeforeFilter !== null) {
            restoreClosedSections(closedBeforeFilter);
            closedBeforeFilter = null;
        }

        part('[data-roster-clear]').hidden = !filtering;
        part('[data-roster-no-match]').hidden = shownRows > 0;

        window.clearTimeout(countTimer);
        const summary = filtering
            ? `Showing ${shownRows} of ${plural(totalRows, 'entry', 'entries')} in ${plural(shownSections, 'section', 'sections')}`
            : `${plural(totalRows, 'entry', 'entries')} in ${plural(allSections.length, 'section', 'sections')}`;
        countTimer = window.setTimeout(() => {
            const count = part('[data-roster-count]');
            if (count) {
                count.textContent = summary;
            }
        }, COUNT_DELAY_MS);
    }

    function setAllSections(open) {
        closedBeforeFilter = null;
        sections().forEach((section) => { section.open = open; });
    }

    function clearFilter() {
        part('[data-roster-search]').value = '';
        part('[data-roster-status]').value = '';
        applyFilter();
    }

    function openLegendOnWideScreens() {
        const legend = part('[data-roster-legend]');
        if (legend && window.matchMedia(WIDE_LEGEND_QUERY).matches) {
            legend.open = true;
        }
    }

    function captureView() {
        const active = document.activeElement;
        return {
            search: part('[data-roster-search]')?.value ?? '',
            status: part('[data-roster-status]')?.value ?? '',
            closed: closedBeforeFilter ?? closedSectionKeys(),
            filtering: closedBeforeFilter !== null,
            legendOpen: part('[data-roster-legend]')?.open ?? null,
            scrollY: window.scrollY,
            focusId: active?.id && body().contains(active) ? active.id : null,
            selection: active instanceof HTMLInputElement && active.type === 'search'
                ? [active.selectionStart, active.selectionEnd]
                : null,
        };
    }

    function restoreView(view) {
        const search = part('[data-roster-search]');
        const status = part('[data-roster-status]');
        if (search && status) {
            search.value = view.search;
            status.value = [...status.options].some((option) => option.value === view.status) ? view.status : '';
        }
        restoreClosedSections(view.closed);
        closedBeforeFilter = view.filtering ? view.closed : null;
        applyFilter();

        const legend = part('[data-roster-legend]');
        if (legend && view.legendOpen !== null) {
            legend.open = view.legendOpen;
        } else {
            openLegendOnWideScreens();
        }

        window.scrollTo({ top: view.scrollY, behavior: 'instant' });
        const focused = view.focusId ? document.getElementById(view.focusId) : null;
        if (focused) {
            focused.focus({ preventScroll: true });
            if (view.selection && focused instanceof HTMLInputElement) {
                focused.setSelectionRange(view.selection[0], view.selection[1]);
            }
        }
    }

    function swapBody(html) {
        const next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-roster-body]');
        if (!next) {
            throw new Error('The roster response had no roster.');
        }
        const view = captureView();
        body().replaceWith(document.adoptNode(next));
        version = next.dataset.version;
        etag = next.dataset.etag;
        restoreView(view);
    }

    /** The times a member reads, moved in place. An export that changed nothing touches nothing else. */
    function applyFacts(facts) {
        if (!facts || body().dataset.live !== '1') {
            return;
        }
        Object.entries(facts).forEach(([name, label]) => {
            const fact = part(`[data-roster-fact="${name}"]`);
            if (fact && label) {
                fact.textContent = label;
            }
        });
        const changedRow = part('[data-roster-fact-row="changed"]');
        if (changedRow && facts.changed) {
            changedRow.hidden = facts.changed === part('[data-roster-fact="updated"]')?.textContent;
        }
    }

    function fragment(html, selector) {
        // A seat is a table body, which only parses inside a table.
        const source = selector === '[data-roster-seat]' ? `<table>${html}</table>` : html;
        return new DOMParser().parseFromString(source, 'text/html').querySelector(selector);
    }

    function setFilterOptions(filters) {
        const status = part('[data-roster-status]');
        const selected = status.value;
        const options = [status.options[0], ...filters.map((filter) => new Option(filter, filter))];
        status.replaceChildren(...options);
        status.value = filters.includes(selected) ? selected : '';
    }

    /**
     * Replaces only the seats that changed, leaving scroll, focus, search and open sections alone. Every
     * target is found before anything moves, so a page that does not match what the server expects is
     * left untouched and returns false, and takes the whole roster instead.
     */
    function applyChanges(changes) {
        const sectionsByKey = new Map(sections().map((section) => [section.dataset.sectionKey, section]));
        const swaps = [];

        for (const change of changes.seats) {
            const current = sectionsByKey.get(change.section)?.querySelector(`[data-roster-seat][data-seat-index="${Number(change.seat)}"]`);
            const next = fragment(change.html, '[data-roster-seat]');
            if (!current || !next) {
                return false;
            }
            swaps.push([current, next]);
        }
        for (const change of changes.sections) {
            const current = sectionsByKey.get(change.section);
            const next = fragment(change.html, '[data-roster-section]');
            if (!current || !next) {
                return false;
            }
            next.open = current.open;
            swaps.push([current, next]);
        }

        const summary = part('[data-roster-summary]');
        const legend = part('[data-roster-legend-items]');
        const nextSummary = fragment(changes.summary, '[data-roster-summary]');
        const nextLegend = fragment(changes.legend, '[data-roster-legend-items]');
        if (!summary || !legend || !nextSummary || !nextLegend || !part('[data-roster-status]')) {
            return false;
        }

        swaps.forEach(([current, next]) => {
            current.replaceWith(document.adoptNode(next));
            next.classList.add('roster-updated');
        });
        changes.counts.forEach((count) => {
            const label = sectionsByKey.get(count.section)?.querySelector('[data-roster-section-count]');
            if (label) {
                label.textContent = count.label;
            }
        });
        summary.replaceWith(document.adoptNode(nextSummary));
        legend.replaceWith(document.adoptNode(nextLegend));
        setFilterOptions(changes.filters);

        version = changes.version;
        etag = changes.etag;
        const element = body();
        element.dataset.version = changes.version;
        element.dataset.etag = changes.etag;
        element.dataset.snapshot = String(changes.snapshot);
        applyFacts(changes.facts);
        applyFilter();
        return true;
    }

    /**
     * Resolves to the number of spots now changed on screen, which can be 0 when TeleStaff re-exported
     * something no member can see, or to null when the page must take the whole roster.
     */
    async function applySpotUpdate(background) {
        const element = body();
        if (element.dataset.live !== '1' || !element.dataset.snapshot) {
            return null;
        }
        const url = new URL(changesUrl, window.location.origin);
        url.searchParams.set('from', element.dataset.snapshot);
        url.searchParams.set('state', element.dataset.state);
        let changes;
        try {
            const response = await fetch(url, {
                cache: 'no-store',
                credentials: 'same-origin',
                redirect: 'manual',
                headers: { Accept: 'application/json', ...background },
            });
            if (!response.ok) {
                return null; // Includes a redirect or a refusal: the full request below answers those properly.
            }
            changes = await response.json();
        } catch (error) {
            console.warn('Daily Roster spot update failed', error);
            return null;
        }
        return changes.mode === 'changes' && applyChanges(changes) ? changes.changed : null;
    }

    /** Takes the roster off the screen for good: personnel data does not outlive the member's access. */
    function endRoster(title, detail) {
        ended = true;
        window.clearInterval(pollTimer);
        window.clearTimeout(countTimer);
        const view = page.querySelector('[data-roster-ended-template]').content.cloneNode(true);
        view.querySelector('[data-roster-ended-title]').textContent = title;
        view.querySelector('[data-roster-ended-detail]').textContent = detail;
        body().replaceWith(view);
        announce.textContent = title;
        window.scrollTo({ top: 0, behavior: 'instant' });
        page.querySelector('[data-roster-ended] h1').focus({ preventScroll: true });
    }

    function goToLogin() {
        endRoster('Your session has ended', 'Taking you to the sign-in page.');
        window.location.assign(loginUrl);
    }

    function showAccessRemoved() {
        endRoster('You no longer have access to the Daily Roster', 'If you think this is a mistake, ask an administrator.');
    }

    /** Resolves to true when a newer roster is now on screen. Any failure other than a refusal keeps what is showing. */
    async function refreshIfChanged(manual) {
        const background = manual ? {} : BACKGROUND_REFRESH;
        const response = await fetch(versionUrl, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'If-None-Match': `"${etag}"`, ...background },
        });
        if (response.status === 304) {
            return false;
        }
        if (response.status === 401) {
            goToLogin();
            return false;
        }
        if (response.status === 403) {
            showAccessRemoved();
            return false;
        }
        if (!response.ok) {
            throw new Error(`Version check returned ${response.status}.`);
        }
        const latest = await response.json();
        if (latest.version === version) {
            // The same roster again: only its times moved.
            etag = latest.etag ?? etag;
            applyFacts(latest.facts);
            return false;
        }

        const spots = await applySpotUpdate(background);
        if (spots !== null) {
            return spots > 0;
        }

        // A redirect here means the session ended; do not follow it into the login page's HTML.
        const whole = await fetch(sectionsUrl, {
            cache: 'no-store',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: { Accept: 'text/html', ...background },
        });
        if (whole.type === 'opaqueredirect' || whole.status === 401) {
            goToLogin();
            return false;
        }
        if (whole.status === 403) {
            showAccessRemoved();
            return false;
        }
        if (!whole.ok) {
            throw new Error(`Roster request returned ${whole.status}.`);
        }
        swapBody(await whole.text());
        return true;
    }

    async function check({ manual = false } = {}) {
        if (ended) {
            return;
        }
        if (checking) {
            // A tap during a background check still gets its own answer.
            manualQueued = manualQueued || manual;
            return;
        }
        if (!navigator.onLine) {
            showStatus('Requires connection');
            return;
        }
        checking = true;
        const button = part('[data-roster-refresh]');
        if (manual && button) {
            button.setAttribute('aria-busy', 'true');
            showStatus('Checking for updates');
        }
        try {
            const changed = await refreshIfChanged(manual);
            if (changed) {
                announce.textContent = 'Roster updated.';
                showStatus(manual ? 'Roster updated' : '');
            } else {
                showStatus(manual ? 'Roster is up to date' : '');
            }
        } catch (error) {
            console.warn('Daily Roster refresh failed', error);
            showStatus('Could not check for updates. This is the last roster loaded.');
        } finally {
            checking = false;
            part('[data-roster-refresh]')?.removeAttribute('aria-busy');
            if (manualQueued) {
                manualQueued = false;
                check({ manual: true });
            }
        }
    }

    // Every control lives inside the swapped body, so listeners stay on the stable page element.
    page.addEventListener('input', (event) => {
        if (event.target.matches('[data-roster-search]')) {
            applyFilter();
        }
    });
    page.addEventListener('change', (event) => {
        if (event.target.matches('[data-roster-status]')) {
            applyFilter();
        }
    });
    page.addEventListener('submit', (event) => event.preventDefault());
    page.addEventListener('click', (event) => {
        const control = event.target.closest('button');
        if (!control) {
            return;
        }
        if (control.matches('[data-roster-refresh]')) {
            check({ manual: true });
        } else if (control.matches('[data-roster-clear]')) {
            clearFilter();
            part('[data-roster-search]').focus();
        } else if (control.matches('[data-roster-expand]')) {
            setAllSections(true);
        } else if (control.matches('[data-roster-collapse]')) {
            setAllSections(false);
        }
    });

    pollTimer = window.setInterval(() => {
        if (document.visibilityState === 'visible') {
            check();
        }
    }, POLL_INTERVAL_MS);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            check();
        }
    });
    window.addEventListener('online', () => {
        showStatus('');
        check();
    });
    window.addEventListener('offline', () => showStatus('Requires connection'));
    window.addEventListener('beforeprint', () => {
        sections().forEach((section) => { section.open = !section.hidden; });
    });

    openLegendOnWideScreens();
    if (!navigator.onLine) {
        showStatus('Requires connection');
    } else {
        showStatus(statusMessage);
    }
}

const rosterPage = document.querySelector('[data-roster-page]');
if (rosterPage) {
    initDailyRoster(rosterPage);
}
