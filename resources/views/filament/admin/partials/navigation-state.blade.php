<script>
    (() => {
        const adminGroups = @js(
            collect(filament()->getCurrentPanel()->getNavigationGroups())
                ->filter(fn ($group) => $group->isCollapsed())
                ->map(fn ($group) => $group->getLabel())
                ->values()
                ->all()
        );
        const savedGroups = JSON.parse(localStorage.getItem('collapsedGroups') || '[]') || [];
        const collapsedGroups = [...new Set([...savedGroups, ...adminGroups])];
        localStorage.setItem('collapsedGroups', JSON.stringify(collapsedGroups));
        const sidebar = window.Alpine?.store('sidebar');
        if (sidebar) sidebar.collapsedGroups = collapsedGroups;

        // Keep the native Filament toggle preference within each viewport band.
        // Moving from a phone drawer to a wide desktop starts with an open sidebar.
        const viewportKey = 'mbfd-admin-sidebar-viewport';
        const updateViewport = () => {
            const viewport = window.innerWidth >= 1280 ? 'wide' : window.innerWidth >= 1024 ? 'rail' : 'drawer';
            if (localStorage.getItem(viewportKey) === viewport) return;

            const isOpen = viewport === 'wide';
            localStorage.setItem('isOpen', JSON.stringify(isOpen));
            localStorage.setItem(viewportKey, viewport);
            const sidebar = window.Alpine?.store('sidebar');
            if (sidebar) sidebar.isOpen = isOpen;
        };
        updateViewport();
        window.matchMedia('(min-width: 1024px)').addEventListener('change', updateViewport);
        window.matchMedia('(min-width: 1280px)').addEventListener('change', updateViewport);
    })();
</script>
