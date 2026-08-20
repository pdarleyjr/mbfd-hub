<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>MBFD Video Conferencing</title>
</head>
<body>
    @if (! $enabled)
        <main class="conference-unavailable">
            <h1>Video conferencing is not available yet</h1>
            <p>The MBFD conference service is not enabled. No camera or microphone access will be requested.</p>
        </main>
    @else
        <main
            id="video-conferencing-root"
            data-bootstrap='@json($conferenceBootstrap)'
            aria-label="MBFD video conferencing workspace"
        ></main>
        @vite('resources/js/video-conferencing/main.tsx')
    @endif
</body>
</html>
