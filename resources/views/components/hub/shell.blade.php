@props(['module' => null, 'backHref' => null, 'backLabel' => 'Hub home', 'navigation' => null])
@php
    $navigation ??= \App\Support\HubShellNavigation::forUser(auth('web')->user());
    $module ??= match (true) {
        request()->is('account*') => 'Account',
        request()->is('updates*') => 'Updates',
        request()->is('support/*') => 'Support',
        request()->is('employee/*') => 'Employee',
        request()->is('workgroups/*', 'workgroup/*') => 'Workgroups',
        default => 'Home',
    };
    $destination = $backHref === null ? null : \App\Support\HubNavigation::backUrl($backHref);
    $resolvedBackLabel = $destination !== null && parse_url($destination, PHP_URL_PATH) !== parse_url($backHref, PHP_URL_PATH) ? 'Back to previous page' : $backLabel;
@endphp
<header class="hub-shell-bar" data-hub-shell>
    <div class="hub-shell-bar__inner">
        <a href="/" class="hub-shell-brand" aria-label="MBFD Hub home">
            <img src="/images/mbfd-official-seal-256.png" alt="" width="32" height="32"><span>Hub</span>
        </a>
        <x-hub.app-switcher :navigation="$navigation" :module="$module" />
        <span class="hub-shell-connection" data-hub-connection role="status">Connected</span>
        <span class="hub-shell-spacer"></span>
        @if($destination !== null)
            <a href="{{ $destination }}" class="hub-shell-control" data-hub-back aria-label="{{ $resolvedBackLabel }}">
                <x-heroicon-o-arrow-left class="hub-shell-icon" aria-hidden="true" /><span class="hub-back-short" aria-hidden="true">Back</span><span class="hub-back-label">{{ $resolvedBackLabel }}</span>
            </a>
        @endif
        @if($navigation['account'] !== null)
            <details class="hub-shell-menu hub-shell-account" data-hub-menu>
                <summary class="hub-shell-control" aria-haspopup="menu" aria-controls="hub-account-menu" aria-label="Account menu for {{ $navigation['account']['name'] }}">
                    <x-heroicon-o-user-circle class="hub-shell-icon" aria-hidden="true" /><span class="hub-shell-account__name">{{ $navigation['account']['name'] }}</span>
                </summary>
                <div id="hub-account-menu" class="hub-shell-popover" role="menu" aria-label="Account">
                    <a role="menuitem" href="{{ $navigation['account']['href'] }}" class="hub-shell-menuitem">My account</a>
                    <a role="menuitem" href="{{ route('hub-support.index') }}" class="hub-shell-menuitem">My issue reports</a>
                    <a role="menuitem" href="{{ route('hub-support.create') }}" class="hub-shell-menuitem">Report an issue</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button role="menuitem" type="submit" class="hub-shell-menuitem hub-shell-menuitem--danger">Sign out</button></form>
                </div>
            </details>
        @endif
    </div>
</header>
<x-hub.member-navigation :navigation="$navigation" />
@vite('resources/js/hub-shell.js')
{{ $slot }}
