{{-- Rendered by the global PAGE_START hook, so it is the first child of .fi-page. On the Employee panel the page-frame CSS
     (hub-admin-v2.css, "Employee page frame") lays it out on the same row as the page title instead of a band above it. --}}
@if ($nav)
    <div @class(['hub-page-nav', 'employee-global-back' => $nav['panel'] === 'employee']) role="navigation" aria-label="Page navigation" data-hub-page-nav>
        @include('filament.partials.contextual-back', ['destination' => $nav['back']])
        @if ($nav['adminHome'])
            <x-filament::button tag="a" :href="$nav['adminHome']" color="gray" icon="heroicon-o-squares-2x2" class="min-h-11 hub-page-nav__home" aria-label="Admin Home" data-hub-admin-home>
                <span class="hub-page-nav__label">Admin Home</span>
            </x-filament::button>
        @endif
        <x-filament::button tag="a" :href="$nav['hubHome']" color="gray" icon="heroicon-o-home" class="min-h-11 hub-page-nav__home" aria-label="Hub Home" data-hub-home>
            <span class="hub-page-nav__label">Hub Home</span>
        </x-filament::button>
    </div>
@endif
