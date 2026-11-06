<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>My account · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header back-label="Hub home" max-width="max-w-5xl" />
    <main id="main" class="hub-page__main mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="My account" description="Manage your Hub identity, security, and application access." />
        @php($chevron = 'm8.25 4.5 7.5 7.5-7.5 7.5')
        <section class="overflow-hidden hub-panel" aria-labelledby="identity-heading">
            <div class="hub-panel__header-block px-4 py-3 sm:px-5"><h2 id="identity-heading" class="text-lg font-bold">Identity & security</h2><p class="mt-1 text-sm text-hub-ink-secondary">Your department identity and current security readiness.</p></div>
            {{-- Label and value share one row, settings-list style; two columns from tablet width. --}}
            <dl class="hub-facts">
                <div><dt>Name</dt><dd>{{ $user->name }}</dd></div>
                <div><dt>Employee ID</dt><dd class="tabular-nums">{{ $user->employee_id ?: 'Not linked' }}</dd></div>
                <div><dt>Password</dt><dd>{{ $localCredentials ? 'Hub local credential' : 'MBFD Identity credential' }}</dd></div>
                <div><dt>Recovery readiness</dt><dd>{{ $maskedRecoveryEmail ? 'Ready · '.$maskedRecoveryEmail : 'Needs administrator review' }}</dd></div>
                <div><dt>Active Hub sessions</dt><dd class="tabular-nums">{{ $activeSessionCount }}</dd></div>
                <div><dt>Account status</dt><dd>{{ ucfirst(str_replace('_', ' ', $user->getRawOriginal('account_status'))) }}</dd></div>
            </dl>
        </section>

        <section class="overflow-hidden hub-panel" aria-labelledby="account-actions-heading">
            <h2 id="account-actions-heading" class="hub-panel__header-block px-4 py-3 text-lg font-bold sm:px-5">Settings</h2>
            <ul class="hub-rowlist">
                <li><a href="{{ $changePasswordUrl }}" class="hub-rowlist__item"><x-heroicon-o-key class="hub-rowlist__icon" aria-hidden="true" /><span class="hub-rowlist__label">Change password</span><svg class="hub-rowlist__chevron" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg></a></li>
                <li><a href="{{ route('city-email.show') }}" class="hub-rowlist__item"><x-heroicon-o-envelope class="hub-rowlist__icon" aria-hidden="true" /><span class="hub-rowlist__label">City email & recovery</span><svg class="hub-rowlist__chevron" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg></a></li>
                @if($notificationSettingsUrl)
                    <li><a href="{{ $notificationSettingsUrl }}" class="hub-rowlist__item"><x-heroicon-o-bell class="hub-rowlist__icon" aria-hidden="true" /><span class="hub-rowlist__label">Notification settings</span><svg class="hub-rowlist__chevron" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg></a></li>
                @endif
                @if($enrollmentUrls)
                    <li><a href="{{ $enrollmentUrls['passkey'] }}" class="hub-rowlist__item"><x-heroicon-o-finger-print class="hub-rowlist__icon" aria-hidden="true" /><span class="hub-rowlist__label">Add a passkey</span><svg class="hub-rowlist__chevron" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg></a></li>
                    <li><a href="{{ $enrollmentUrls['totp'] }}" class="hub-rowlist__item"><x-heroicon-o-device-phone-mobile class="hub-rowlist__icon" aria-hidden="true" /><span class="hub-rowlist__label">Add authenticator app</span><svg class="hub-rowlist__chevron" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg></a></li>
                @endif
                <li><a href="/employee" class="hub-rowlist__item"><x-heroicon-o-user-circle class="hub-rowlist__icon" aria-hidden="true" /><span class="hub-rowlist__label">Employee Portal</span><svg class="hub-rowlist__chevron" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg></a></li>
                @if($user->hasCurrentAdminPanelEntitlement())
                    <li><a href="/admin" class="hub-rowlist__item"><x-heroicon-o-squares-2x2 class="hub-rowlist__icon" aria-hidden="true" /><span class="hub-rowlist__label">Admin Panel</span><svg class="hub-rowlist__chevron" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg></a></li>
                @endif
                <li><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="hub-rowlist__item hub-rowlist__item--danger"><x-heroicon-o-arrow-right-on-rectangle class="hub-rowlist__icon" aria-hidden="true" /><span class="hub-rowlist__label">Sign out</span></button></form></li>
            </ul>
        </section>

        <section class="overflow-hidden hub-panel" aria-labelledby="access-heading">
            <div class="hub-panel__header-block px-4 py-3 sm:px-5"><h2 id="access-heading" class="text-lg font-bold">Application access</h2><p class="mt-1 text-sm text-hub-ink-secondary">Availability reflects your Hub account and each application's access requirements.</p></div>
            <ul class="hub-rowlist">
                @foreach($applications as $key => $application)
                    @php($state = $applicationStates[$key])
                    <li class="hub-rowlist__item hub-rowlist__item--static">
                        <div class="min-w-0 flex-1"><h3 class="font-semibold">{{ $application['label'] }}</h3><p class="text-sm text-hub-ink-secondary">{{ $state['status'] }}</p></div>
                        <span class="hub-tag shrink-0 {{ $state['allowed'] ? 'hub-tag--success' : '' }}">{{ $state['allowed'] ? 'Available' : 'Unavailable' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
