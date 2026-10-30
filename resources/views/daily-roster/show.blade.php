<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>Daily Roster · MBFD Hub</title>
    @vite(['resources/css/app.css', 'resources/css/daily-roster.css', 'resources/js/daily-roster.js'])
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub.shell module="Daily Roster" :back-href="url('/')" />
    <main
        id="main"
        class="hub-page__main roster-main"
        data-roster-page
        data-version-url="{{ url('/api/daily-roster/version') }}"
        data-sections-url="{{ route('daily-roster.sections', array_filter(['date' => $roster['requestedDate']])) }}"
        data-changes-url="{{ route('daily-roster.changes') }}"
        data-login-url="{{ url('/login') }}"
    >
        {{-- Stays in place across a swap so "Roster updated" is announced. --}}
        <p class="sr-only" role="status" data-roster-announce></p>
        @include('daily-roster._body', ['roster' => $roster])
        {{-- Replaces the roster when the session ends or access is withdrawn, so no personnel data stays on screen. --}}
        <template data-roster-ended-template>
            <div data-roster-ended>
                <header class="hub-page-header roster-header">
                    <div class="roster-header__text"><h1 class="hub-h1" tabindex="-1">Daily Roster</h1></div>
                </header>
                <section class="hub-panel hub-empty">
                    <x-heroicon-o-lock-closed class="hub-empty__icon" aria-hidden="true" />
                    <h2 class="hub-empty__title" data-roster-ended-title></h2>
                    <p class="hub-empty__body" data-roster-ended-detail></p>
                    <a class="hub-btn hub-btn--secondary roster-empty__action" href="{{ url('/') }}">Hub Home</a>
                </section>
            </div>
        </template>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
