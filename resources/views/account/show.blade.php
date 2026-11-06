<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>My account · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
@php
    // One settings row: a 48px touch target with its icon, label and chevron.
    $row = 'flex min-h-12 w-full items-center gap-3 px-4 py-2 text-left text-[15px] font-semibold text-hub-ink transition-colors hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-hub-focus';
    $icon = 'h-5 w-5 flex-none text-hub-ink-secondary';
    $chevron = 'ml-auto h-4 w-4 flex-none text-hub-ink-secondary';
    $facts = [
        'Name' => $user->name,
        'Employee ID' => $user->employee_id ?: 'Not linked',
        'Password' => $localCredentials ? 'Hub local credential' : 'MBFD Identity credential',
        'Recovery readiness' => $maskedRecoveryEmail ? 'Ready · '.$maskedRecoveryEmail : 'Needs administrator review',
        'Active Hub sessions' => $activeSessionCount,
        'Account status' => ucfirst(str_replace('_', ' ', $user->getRawOriginal('account_status'))),
    ];
@endphp
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header back-label="Hub home" max-width="max-w-5xl" />
    <main id="main" class="hub-page__main mx-auto max-w-3xl space-y-4 px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="My account" class="!mb-2" />

        <section class="hub-panel overflow-hidden" aria-labelledby="identity-heading">
            <div class="hub-panel__header"><h2 id="identity-heading" class="hub-h3">Identity & security</h2></div>
            <dl class="hub-list">
                @foreach($facts as $label => $value)
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-4 py-2.5">
                        <dt class="text-sm text-hub-ink-secondary">{{ $label }}</dt>
                        <dd @class(['text-right text-sm font-semibold text-hub-ink', 'tabular-nums' => in_array($label, ['Employee ID', 'Active Hub sessions'], true)])>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <nav class="hub-panel overflow-hidden" aria-label="Account settings">
            <ul class="hub-list">
                <li><a href="{{ $changePasswordUrl }}" class="{{ $row }} !text-hub-blue"><x-heroicon-o-key class="{{ $icon }} !text-hub-blue" aria-hidden="true" />Change password<x-heroicon-m-chevron-right class="{{ $chevron }}" aria-hidden="true" /></a></li>
                <li><a href="{{ route('city-email.show') }}" class="{{ $row }}"><x-heroicon-o-envelope class="{{ $icon }}" aria-hidden="true" />City email & recovery<x-heroicon-m-chevron-right class="{{ $chevron }}" aria-hidden="true" /></a></li>
                @if($enrollmentUrls)
                    <li><a href="{{ $enrollmentUrls['passkey'] }}" class="{{ $row }}"><x-heroicon-o-finger-print class="{{ $icon }}" aria-hidden="true" />Add a passkey<x-heroicon-m-chevron-right class="{{ $chevron }}" aria-hidden="true" /></a></li>
                    <li><a href="{{ $enrollmentUrls['totp'] }}" class="{{ $row }}"><x-heroicon-o-device-phone-mobile class="{{ $icon }}" aria-hidden="true" />Add authenticator app<x-heroicon-m-chevron-right class="{{ $chevron }}" aria-hidden="true" /></a></li>
                @endif
                @if($notificationSettingsUrl)
                    <li><a href="{{ $notificationSettingsUrl }}" class="{{ $row }}"><x-heroicon-o-bell class="{{ $icon }}" aria-hidden="true" />Notification settings<x-heroicon-m-chevron-right class="{{ $chevron }}" aria-hidden="true" /></a></li>
                @endif
                <li><a href="/employee" class="{{ $row }}"><x-heroicon-o-identification class="{{ $icon }}" aria-hidden="true" />Employee Portal<x-heroicon-m-chevron-right class="{{ $chevron }}" aria-hidden="true" /></a></li>
                @if($user->hasCurrentAdminPanelEntitlement())
                    <li><a href="/admin" class="{{ $row }}"><x-heroicon-o-cog-6-tooth class="{{ $icon }}" aria-hidden="true" />Admin Panel<x-heroicon-m-chevron-right class="{{ $chevron }}" aria-hidden="true" /></a></li>
                @endif
                <li>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="{{ $row }}"><x-heroicon-o-arrow-right-on-rectangle class="{{ $icon }}" aria-hidden="true" />Sign out</button></form>
                </li>
            </ul>
        </nav>

        <section class="hub-panel overflow-hidden" aria-labelledby="access-heading">
            <div class="hub-panel__header"><h2 id="access-heading" class="hub-h3">Application access</h2></div>
            <ul class="hub-list">
                @foreach($applications as $key => $application)
                    @php($state = $applicationStates[$key])
                    <li class="flex items-start justify-between gap-3 px-4 py-3">
                        <div class="min-w-0"><h3 class="text-[15px] font-semibold leading-snug">{{ $application['label'] }}</h3><p class="{{ $state['allowed'] ? 'mt-0.5 text-sm leading-snug text-hub-ink-secondary' : 'sr-only' }}">{{ $state['status'] }}</p></div>
                        <span class="hub-tag flex-none {{ $state['allowed'] ? 'hub-tag--success' : '' }}">{{ $state['allowed'] ? 'Available' : 'Unavailable' }}</span>
                    </li>
                @endforeach
            </ul>
            @if(collect($applicationStates)->contains(fn ($state) => ! $state['allowed']))
                <p class="border-t border-hub-border-soft px-4 py-3 text-sm text-hub-ink-secondary">Unavailable applications are not set up for your account. Ask an administrator if you need access.</p>
            @endif
        </section>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
