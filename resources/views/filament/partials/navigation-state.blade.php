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
    </script>
@endif
