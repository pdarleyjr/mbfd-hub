<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>Profile not opened · MBFD Hub</title>
    @vite(['resources/css/app.css', 'resources/css/daily-roster.css'])
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub.shell module="Daily Roster" :back-href="route('daily-roster.show')" />
    <main id="main" class="hub-page__main roster-main" data-roster-profile-unresolved="{{ $reason }}">
        <section class="hub-panel hub-empty">
            <x-heroicon-o-user-circle class="hub-empty__icon" aria-hidden="true" />
            @if ($reason === 'ambiguous')
                <h1 class="hub-empty__title">More than one profile has this Employee ID</h1>
                <p class="hub-empty__body">Employee ID {{ $employeeId }} on the roster matches more than one personnel profile, so no profile was opened. Find the member in the Personnel Directory and correct the duplicate Employee ID.</p>
            @else
                <h1 class="hub-empty__title">No profile has this Employee ID</h1>
                <p class="hub-empty__body">Employee ID {{ $employeeId }} on the roster does not match a personnel profile. The roster comes from TeleStaff; the profile may use a different Employee ID or may not exist yet.</p>
            @endif
            <a class="hub-btn hub-btn--secondary roster-empty__action" href="{{ route('daily-roster.show') }}">Back to the Daily Roster</a>
            <a class="hub-btn hub-btn--secondary roster-empty__action" href="{{ url('/admin/master-personnel-directory') }}">Open the Personnel Directory</a>
        </section>
    </main>
</body>
</html>
