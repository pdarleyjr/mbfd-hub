<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    @if (! $enabled)
        <section class="hub-panel p-4 sm:p-6" aria-labelledby="conference-unavailable-title">
            <div class="flex items-start gap-3">
                <x-heroicon-o-video-camera-slash class="mt-0.5 h-6 w-6 shrink-0 text-hub-ink-secondary" aria-hidden="true" />
                <div>
                    <h2 id="conference-unavailable-title" class="hub-h3">Video conferencing is not available yet</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-hub-ink-secondary">The secure MBFD conference service is installed but has not been enabled. No camera or microphone access will be requested.</p>
                </div>
            </div>
        </section>
    @else
        <div
            id="video-conferencing-root"
            data-bootstrap='@json($conferenceBootstrap)'
            data-embedded="true"
            aria-label="MBFD video conferencing workspace"
            x-data
            x-init="window.matchMedia('(max-width: 1023px)').matches && $store.sidebar.close()"
        ></div>

        @vite('resources/js/video-conferencing/main.tsx')
    @endif
</x-filament-panels::page>
