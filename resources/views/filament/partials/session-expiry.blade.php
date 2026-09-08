<script data-mbfd-session-expiry>
(() => {
    if (window.mbfdSessionExpiryInstalled) return;
    window.mbfdSessionExpiryInstalled = true;

    let redirecting = false;
    const install = () => window.Livewire.hook('request', ({ options, fail }) => {
        // Do not send more queued polling or form requests while leaving this page.
        if (redirecting) options.signal = AbortSignal.abort();

        fail(({ status, preventDefault }) => {
            if (!redirecting && status !== 401 && status !== 419) return;
            // Suppress both Livewire's native confirm and its raw HTML error modal,
            // including failures from other requests already in flight.
            preventDefault();
            if (redirecting) return;
            redirecting = true;

            // No replay, URL payload, or browser storage of unsaved/private fields.
            document.querySelectorAll('input, textarea, select').forEach(control => {
                control.value = '';
                if ('checked' in control) control.checked = false;
                control.disabled = true;
            });
            window.location.replace('/login?session_expired=1');
        });
    });

    if (window.Livewire) install();
    else document.addEventListener('livewire:init', install, { once: true });
})();
</script>
