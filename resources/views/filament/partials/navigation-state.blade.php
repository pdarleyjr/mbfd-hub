@if(auth('web')->check() && filament()->getCurrentPanel())
    @php
        $fixedGroups = collect(filament()->getNavigation())
            ->reject(fn ($group) => $group->isCollapsible())
            ->map(fn ($group) => $group->getLabel())
            ->filter()
            ->values();
    @endphp
    <script>
        // Filament shares this persisted key across panels. A group that cannot
        // collapse must not inherit another panel's collapsed state or its
        // initial sidebar script tries to access a nonexistent collapse button.
        (() => {
            try {
                const fixedGroups = @js($fixedGroups);
                const saved = JSON.parse(localStorage.getItem('collapsedGroups') || '[]');
                if (Array.isArray(saved) && saved.some(label => fixedGroups.includes(label))) {
                    localStorage.setItem('collapsedGroups', JSON.stringify(saved.filter(label => !fixedGroups.includes(label))));
                }
            } catch {
                // Storage can be unavailable; Filament retains its defaults.
            }
        })();

        // Keep the native Filament toggle preference within each viewport band, for every panel.
        // A phone starts with the drawer closed; moving to a wide desktop starts with an open sidebar.
        // (Filament's own default is open, which covered most of a phone on a first visit.)
        (() => {
            const viewportKey = 'mbfd-sidebar-viewport';
            const updateViewport = () => {
                try {
                    const viewport = window.innerWidth >= 1280 ? 'wide' : window.innerWidth >= 1024 ? 'rail' : 'drawer';
                    if (localStorage.getItem(viewportKey) === viewport) return;
                    const isOpen = viewport === 'wide';
                    localStorage.setItem('isOpen', JSON.stringify(isOpen));
                    localStorage.setItem(viewportKey, viewport);
                    const sidebar = window.Alpine?.store('sidebar');
                    if (sidebar) sidebar.isOpen = isOpen;
                } catch {
                    // Storage can be unavailable; Filament retains its defaults.
                }
            };
            updateViewport();
            window.matchMedia('(min-width: 1024px)').addEventListener('change', updateViewport);
            window.matchMedia('(min-width: 1280px)').addEventListener('change', updateViewport);
        })();
    </script>
@endif
