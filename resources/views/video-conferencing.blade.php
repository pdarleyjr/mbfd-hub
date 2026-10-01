<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>MBFD Video Conferencing</title>
    @if (! $enabled)
        @vite('resources/css/app.css')
    @endif
</head>
<body data-hub-ui="2" class="hub-page">
    @if (! $enabled)
        <x-hub-header back-href="/" back-label="Back to Hub home" />
        <main class="conference-unavailable mx-auto max-w-3xl px-4 py-8">
            <h1 class="text-2xl font-bold text-hub-ink">Video conferencing is not available yet</h1>
            <p class="mt-4 text-hub-ink-secondary">The MBFD conference service is not enabled. No camera or microphone access will be requested.</p>
        </main>
    @else
        <main
            id="video-conferencing-root"
            data-bootstrap='@json($conferenceBootstrap)'
            aria-label="MBFD video conferencing workspace"
        ></main>
        @vite('resources/js/video-conferencing/main.tsx')
    @endif
    @if (request()->routeIs('employee.video-conferencing.command'))
        @include('components.hub-support-widget')
    @endif
</body>
</html>
