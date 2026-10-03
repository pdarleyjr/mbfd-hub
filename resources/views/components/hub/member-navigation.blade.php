@props(['navigation', 'id' => 'hub-member-more'])
@if(count($navigation['memberNavigation']) > 1)
<nav class="hub-member-nav" aria-label="Member navigation">
    @php
        $activeItem = collect($navigation['memberNavigation'])
            ->filter(fn ($item) => $item['key'] === 'home' ? request()->is('/') : request()->is(ltrim($item['href'], '/'), ltrim($item['href'], '/').'/*'))
            ->sortByDesc(fn ($item) => strlen($item['href']))
            ->first();
    @endphp
    @foreach($navigation['memberNavigation'] as $item)
        @php($active = ($activeItem['key'] ?? null) === $item['key'])
        <a href="{{ $item['href'] }}" class="hub-member-nav__item" @if($active) aria-current="page" @endif>
            @switch($item['key'])
                @case('home') <x-heroicon-o-home class="hub-shell-icon" aria-hidden="true" /> @break
                @case('checkout') <span class="hub-shell-icon hub-checkout-icon" aria-hidden="true"></span> @break
                @case('employee') <x-heroicon-o-user-circle class="hub-shell-icon" aria-hidden="true" /> @break
                @case('requests') <x-heroicon-o-inbox-stack class="hub-shell-icon" aria-hidden="true" /> @break
            @endswitch
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
    <details class="hub-shell-menu hub-member-nav__more" data-hub-menu>
        <summary class="hub-member-nav__item" aria-haspopup="menu" aria-controls="{{ $id }}">
            <x-heroicon-o-ellipsis-horizontal class="hub-shell-icon" aria-hidden="true" /><span>More</span>
        </summary>
        <div id="{{ $id }}" class="hub-shell-popover" role="menu" aria-label="More Hub destinations">
            @foreach($navigation['applications'] as $application)
                <a role="menuitem" href="{{ $application['href'] }}" class="hub-shell-menuitem" @if(str_starts_with($application['href'], 'https://')) target="_blank" rel="noopener" @endif>{{ $application['label'] }}@if(str_starts_with($application['href'], 'https://'))<span class="sr-only"> (opens in a new tab)</span>@endif</a>
            @endforeach
            <a role="menuitem" href="{{ route('updates.index') }}" class="hub-shell-menuitem">Department Updates</a>
            <a role="menuitem" href="{{ route('hub-support.index') }}" class="hub-shell-menuitem">Support</a>
            <a role="menuitem" href="{{ route('account.show') }}" class="hub-shell-menuitem">My account</a>
        </div>
    </details>
</nav>
@endif
