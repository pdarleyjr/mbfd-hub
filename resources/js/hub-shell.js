function closeMenu(menu, restoreFocus = false) {
    menu.open = false;
    if (restoreFocus) menu.querySelector('summary')?.focus();
}

document.addEventListener('click', event => {
    document.querySelectorAll('[data-hub-menu][open]').forEach(menu => {
        if (!menu.contains(event.target)) closeMenu(menu);
    });
});

document.addEventListener('keydown', event => {
    const menu = event.target.closest('[data-hub-menu]');
    if (!menu) return;
    if (event.key === 'Escape' && menu.open) {
        event.preventDefault();
        closeMenu(menu, true);
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
}
updateConnection();
window.addEventListener('online', updateConnection);
window.addEventListener('offline', updateConnection);
