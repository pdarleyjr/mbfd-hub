<x-filament-panels::page>
    @if (! $enabled)
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="conference-unavailable-title">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                    <x-heroicon-o-video-camera-slash class="h-6 w-6" />
                </div>
                <div>
                    <h2 id="conference-unavailable-title" class="text-xl font-semibold text-slate-900">Video conferencing is not available yet</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">The secure MBFD conference service is installed but has not been enabled. No camera or microphone access will be requested.</p>
                </div>
            </div>
        </section>
    @else
        <div
            id="video-conferencing-root"
            data-bootstrap='@json($conferenceBootstrap)'
            aria-label="MBFD video conferencing workspace"
            x-data
            x-init="window.matchMedia('(max-width: 1023px)').matches && $store.sidebar.close()"
        ></div>

        @vite('resources/js/video-conferencing/main.tsx')
    @endif
</x-filament-panels::page>
