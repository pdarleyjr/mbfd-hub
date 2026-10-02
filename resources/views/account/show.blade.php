<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102A43">
    <title>My account · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header back-label="Hub home" max-width="max-w-5xl" />
    <main id="main" class="hub-page__main mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="My account" eyebrow="MBFD Identity" description="Manage your Hub identity, security, and granted application access." />
        <section class="overflow-hidden hub-panel" aria-labelledby="identity-heading">
            <div class="border-b border-hub-border px-5 py-4"><h2 id="identity-heading" class="text-lg font-bold">Identity & security</h2><p class="mt-1 text-sm text-hub-muted">Your department identity and current security readiness.</p></div>
            <dl class="grid gap-px bg-hub-border sm:grid-cols-2">
                <div class="bg-hub-surface p-5"><dt class="text-xs font-bold uppercase tracking-wide text-hub-muted">Name</dt><dd class="mt-1 font-semibold">{{ $user->name }}</dd></div>
                <div class="bg-hub-surface p-5"><dt class="text-xs font-bold uppercase tracking-wide text-hub-muted">Employee ID</dt><dd class="mt-1 font-mono font-semibold">{{ $user->employee_id ?: 'Not linked' }}</dd></div>
                <div class="bg-hub-surface p-5"><dt class="text-xs font-bold uppercase tracking-wide text-hub-muted">Password</dt><dd class="mt-1 font-semibold">{{ $localCredentials ? 'Hub local credential' : 'MBFD Identity credential' }}</dd></div>
                <div class="bg-hub-surface p-5"><dt class="text-xs font-bold uppercase tracking-wide text-hub-muted">Recovery readiness</dt><dd class="mt-1 font-semibold">{{ $maskedRecoveryEmail ? 'Ready · '.$maskedRecoveryEmail : 'Needs administrator review' }}</dd></div>
                <div class="bg-hub-surface p-5"><dt class="text-xs font-bold uppercase tracking-wide text-hub-muted">Active Hub sessions</dt><dd class="mt-1 font-semibold">{{ $activeSessionCount }}</dd></div>
                <div class="bg-hub-surface p-5"><dt class="text-xs font-bold uppercase tracking-wide text-hub-muted">Account status</dt><dd class="mt-1 font-semibold">{{ ucfirst(str_replace('_', ' ', $user->getRawOriginal('account_status'))) }}</dd></div>
            </dl>
            <div class="flex flex-wrap gap-3 border-t border-hub-border px-5 py-4">
                <a href="{{ $changePasswordUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-hub-blue px-4 py-2 text-sm font-bold text-white hover:bg-hub-blue-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">Change password</a>
                <a href="{{ route('city-email.show') }}" class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">City email & recovery</a>
                <a href="/employee" class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Employee Portal</a>
                @if($user->hasCurrentAdminPanelEntitlement())
                    <a href="/admin" class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Admin Panel</a>
                @endif
                @if($enrollmentUrls)
                    <a href="{{ $enrollmentUrls['passkey'] }}" class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Add a passkey</a>
                    <a href="{{ $enrollmentUrls['totp'] }}" class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Add authenticator app</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Sign out</button></form>
            </div>
        </section>

        <section class="hub-panel" aria-labelledby="access-heading">
            <div class="border-b border-hub-border px-5 py-4"><h2 id="access-heading" class="text-lg font-bold">Application access</h2><p class="mt-1 text-sm text-hub-muted">Only applications currently granted by the Hub are shown as available.</p></div>
            <ul class="divide-y divide-hub-border-soft">
                @foreach($applications as $key => $application)
                    @php($state = $applicationStates[$key])
                    <li class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h3 class="font-semibold">{{ $application['label'] }}</h3><p class="text-sm text-hub-muted">{{ $state['status'] }}</p></div>
                        <span class="w-fit rounded-full px-3 py-1 text-xs font-bold {{ $state['allowed'] ? 'bg-emerald-100 text-emerald-800' : 'bg-hub-surface-muted text-hub-ink-secondary' }}">{{ $state['allowed'] ? 'Available' : 'Unavailable' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
