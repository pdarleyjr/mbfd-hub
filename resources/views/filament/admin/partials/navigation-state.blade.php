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

    })();
</script>
