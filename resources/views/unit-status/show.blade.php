<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>Unit Status · MBFD Hub</title>
    @vite(['resources/css/app.css', 'resources/css/unit-status.css', 'resources/js/unit-status.js'])
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub.shell module="Unit Status" :back-href="url('/')" />
    <main
        id="main"
        class="hub-page__main us-main"
        data-us-page
        data-board-url="{{ url('/unit-status/board') }}"
        data-login-url="{{ url('/login') }}"
    >
        <h1 class="hub-h1 us-title" tabindex="-1">Unit Status</h1>
        {{-- Stays in place across a refresh, so a real change is announced once. --}}
        <p class="sr-only" role="status" aria-live="polite" data-us-announce></p>
        @include('unit-status.partials.board', ['board' => $board])
        <p class="us-refresh-note" role="status" data-us-refresh-note hidden></p>
        {{-- Outside the refreshed board, so a refresh leaves an open profile alone. Emptied when it closes. --}}
        <dialog class="us-dialog" data-us-dialog aria-label="Unit profile">
            <div class="us-dialog__inner">
                <div class="us-dialog__bar">
                    <button type="button" class="hub-btn hub-btn--secondary" data-us-dialog-close data-focus-key="close">Close</button>
                </div>
                <div class="us-dialog__body" data-us-dialog-content></div>
            </div>
        </dialog>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
