import { startUpdateRegistrar } from './pwa/update-registrar';

function closeMenu(menu, restoreFocus = false) {
    menu.open = false;
    if (restoreFocus) menu.querySelector('summary')?.focus();
}

// The phone "More" menu is a bottom sheet whose backdrop is the open <details> itself.
const isSheet = menu => menu.classList.contains('hub-member-nav__more');

function closeMenusOutside(event) {
    document.querySelectorAll('[data-hub-menu][open]').forEach(menu => {
        if (!menu.contains(event.target)) closeMenu(menu);
        else if (isSheet(menu) && event.target === menu && event.type === 'click') closeMenu(menu, true);
    });
}
// iOS can omit compatibility clicks on noninteractive content outside the menu.
document.addEventListener('pointerdown', closeMenusOutside);
document.addEventListener('click', closeMenusOutside);

document.addEventListener('keydown', event => {
    const menu = event.target.closest('[data-hub-menu]');
    if (!menu) return;
    if (event.key === 'Escape' && menu.open) {
        event.preventDefault();
        closeMenu(menu, true);
        return;
    }
    if (event.key === 'Tab' && menu.open && isSheet(menu)) {
        // Focus stays inside an open sheet: wrap between the trigger and its last item.
        const stops = [menu.querySelector('summary'), ...menu.querySelectorAll('[role="menuitem"]')].filter(Boolean);
        const first = stops[0], last = stops[stops.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        return;
    }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    menu.open = true;
    const items = [...menu.querySelectorAll('[role="menuitem"]')];
    const current = items.indexOf(document.activeElement);
    const index = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
        : event.key === 'ArrowDown' ? (current + 1) % items.length
        : (current <= 0 ? items.length : current) - 1;
    items[index]?.focus();
});

// Opening the sheet moves focus into it so keyboard and screen reader users land on the first destination.
document.addEventListener('toggle', event => {
    const menu = event.target;
    if (menu instanceof HTMLDetailsElement && menu.open && isSheet(menu)) menu.querySelector('[role="menuitem"]')?.focus();
}, true);

document.addEventListener('focusout', event => {
    const menu = event.target.closest('[data-hub-menu]');
    // WebKit touch activation can blur the summary before the link receives its click.
    if (menu && event.relatedTarget && !menu.contains(event.relatedTarget)) closeMenu(menu);
});

function updateConnection() {
    document.querySelectorAll('[data-hub-connection]').forEach(status => {
        status.textContent = navigator.onLine ? 'Connected' : 'Requires connection';
        status.dataset.offline = String(!navigator.onLine);
    });
    // The offline state is shown once, as a strip below the bar, never inside it.
    document.querySelectorAll('[data-hub-offline-strip]').forEach(strip => {
        strip.hidden = navigator.onLine;
    });
}
updateConnection();
window.addEventListener('online', updateConnection);
window.addEventListener('offline', updateConnection);

// Release checks, the "update ready" banner, chunk-load recovery and notification deep links for every
// Hub page. Scope '/' only attaches to the root push worker if this device already has it; it never
// registers a worker itself (the push settings page does that, at /sw.js).
startUpdateRegistrar({ scope: '/' });
