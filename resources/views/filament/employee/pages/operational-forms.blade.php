<x-filament-panels::page>
    <div
        id="operational-forms-root"
        data-bootstrap='@json($operationalFormsBootstrap)'
        aria-label="MBFD Operational Forms workspace"
        x-data
        x-init="window.matchMedia('(max-width: 1023px)').matches && $store.sidebar.close()"
    ></div>

    @vite('resources/js/operational-forms/main.tsx')
</x-filament-panels::page>
