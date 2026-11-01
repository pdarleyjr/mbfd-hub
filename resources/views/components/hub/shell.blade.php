@props(['module' => null, 'backHref' => null, 'backLabel' => 'Hub home', 'navigation' => null])
@php
    $navigation ??= \App\Support\HubShellNavigation::forUser(auth('web')->user());
    $module ??= match (true) {
        request()->is('account*') => 'Account',
        request()->is('updates*') => 'Updates',
        request()->is('support/*') => 'Support',
        request()->is('take-home-vehicle-inspections', 'take-home-vehicle-inspections/*') => 'Take Home Inspection',
        request()->is('employee/*') => 'Employee',
        request()->is('workgroups/*', 'workgroup/*') => 'Workgroups',
        default => 'Home',
    };
    $destination = $backHref === null ? null : \App\Support\HubNavigation::backUrl($backHref);
    // Standard labels: "Back" (long form "Back to X"), "Hub Home". Going home is the Hub Home control's job.
    $backLabel = in_array(strtolower($backLabel), ['hub home', 'back to hub home'], true) ? 'Back' : $backLabel;
    $resolvedBackLabel = $destination !== null && parse_url($destination, PHP_URL_PATH) !== parse_url($backHref, PHP_URL_PATH) ? 'Back to previous page' : $backLabel;
    $showHubHome = $module !== 'Home';
@endphp
<header class="hub-shell-bar" data-hub-shell>
    <div class="hub-shell-bar__inner">
        <x-hub.brand />
        <x-hub.app-switcher :navigation="$navigation" :module="$module" />
        <span class="hub-shell-connection" data-hub-connection role="status">Connected</span>
        <span class="hub-shell-spacer"></span>
        @if($destination !== null)
            <a href="{{ $destination }}" class="hub-shell-control" data-hub-back aria-label="{{ $resolvedBackLabel }}">
                <x-heroicon-o-arrow-left class="hub-shell-icon" aria-hidden="true" /><span class="hub-back-short" aria-hidden="true">Back</span><span class="hub-back-label">{{ $resolvedBackLabel }}</span>
            </a>
        @endif
        @if($showHubHome)
            <a href="{{ url('/') }}" class="hub-shell-control" data-hub-home aria-label="Hub Home">
                <x-heroicon-o-home class="hub-shell-icon" aria-hidden="true" /><span class="hub-shell-home-label">Hub Home</span>
            </a>
        @endif
        @if($navigation['account'] !== null)
            <details class="hub-shell-menu hub-shell-account" data-hub-menu>
                <summary class="hub-shell-control" aria-haspopup="menu" aria-controls="hub-account-menu" aria-label="Account menu for {{ $navigation['account']['name'] }}">
                    <x-heroicon-o-user-circle class="hub-shell-icon" aria-hidden="true" /><span class="hub-shell-account__name">{{ $navigation['account']['name'] }}</span>
                </summary>
                <div id="hub-account-menu" class="hub-shell-popover" role="menu" aria-label="Account">
                    <a role="menuitem" href="{{ $navigation['account']['href'] }}" class="hub-shell-menuitem">My account</a>
                    <a role="menuitem" href="{{ route('install') }}" class="hub-shell-menuitem" data-hub-install-link>Install MBFD Hub</a>
                    <a role="menuitem" href="{{ route('hub-support.index') }}" class="hub-shell-menuitem">My issue reports</a>
                    <a role="menuitem" href="{{ route('hub-support.create') }}" class="hub-shell-menuitem">Report an issue</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button role="menuitem" type="submit" class="hub-shell-menuitem hub-shell-menuitem--danger">Sign out</button></form>
                </div>
            </details>
        @else
            <a href="{{ url('/login') }}" class="hub-shell-control" aria-label="Sign In">Sign in</a>
        @endif
    </div>
</header>
<x-hub.offline-strip />
<x-hub.member-navigation :navigation="$navigation" />
@vite('resources/js/hub-shell.js')
{{ $slot }}
