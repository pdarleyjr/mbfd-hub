<script data-mbfd-async-components>
(() => {
    const install = () => {
        if (window.mbfdAsyncComponentsInstalled) return;
        window.mbfdAsyncComponentsInstalled = true;

        // A deferred Livewire table can morph before its AsyncAlpine module loads.
        // Preserve the loader's temporary ignore during cloning; AsyncAlpine
        // removes it and initializes the real component when its module resolves.
        window.Alpine.interceptClone((from, to) => {
            if (from._x_async === 'await' && from.matches('[x-load][x-ignore]')) {
                to.setAttribute('x-ignore', '');
            }
        });
    };

    if (window.Alpine) install();
    else document.addEventListener('alpine:init', install, { once: true });
})();
</script>
