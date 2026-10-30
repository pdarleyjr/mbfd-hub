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
        data-login-url="{{ url('/login') }}"
    >
        {{-- Stays in place across a swap so "Roster updated" is announced. --}}
        <p class="sr-only" role="status" data-roster-announce></p>
        @include('daily-roster._body', ['roster' => $roster])
    </main>
    @include('components.hub-support-widget')
</body>
</html>
