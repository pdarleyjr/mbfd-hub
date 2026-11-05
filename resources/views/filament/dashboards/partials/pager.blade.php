{{--
    ‹ n/4 › pager between the four linked dashboards, plus a strip of direct links to every board this person can
    open (so a board two steps away, or past one they cannot open, is still one click). Real links (Back/Forward
    and direct URLs work); the ends, and boards this person cannot open, are aria-disabled with no href.
--}}
@php
    $views = \App\Support\Dashboards\DashboardView::cases();
    $total = count($views);
    $user = auth()->user();
    $urlFor = static function (\App\Support\Dashboards\DashboardView $view) use ($user): ?string {
        if (! \App\Services\Dashboards\DashboardAccess::canView($user, $view)) {
            return null;
        }

        return match ($view) {
            \App\Support\Dashboards\DashboardView::Overview => \App\Filament\Pages\Dashboard::getUrl(panel: 'admin'),
            \App\Support\Dashboards\DashboardView::VehicleCheckout => \App\Filament\Pages\VehicleCheckoutDashboard::getUrl(panel: 'admin'),
            \App\Support\Dashboards\DashboardView::StationOperations => \App\Filament\Pages\StationOperationsDashboard::getUrl(panel: 'admin'),
            \App\Support\Dashboards\DashboardView::MemberRequests => \App\Filament\Pages\MemberRequestsDashboard::getUrl(panel: 'admin'),
        };
    };
    $previous = $current->previous();
    $next = $current->next();
    $previousUrl = $previous ? $urlFor($previous) : null;
    $nextUrl = $next ? $urlFor($next) : null;
    $tabs = [];
    foreach ($views as $tabView) {
        $tabUrl = $tabView === $current ? null : $urlFor($tabView);
        if ($tabView === $current || $tabUrl !== null) {
            $tabs[] = ['view' => $tabView, 'url' => $tabUrl];
        }
    }
@endphp
<nav class="mbfd-dash-pager" aria-label="Dashboards" data-dashboard-pager>
    @if (count($tabs) > 1)
        <ul class="mbfd-dash-pager__tabs" role="list" data-dashboard-tabs>
            @foreach ($tabs as $tab)
                <li>
                    @if ($tab['url'] === null)
                        <span class="mbfd-dash-pager__tab is-current" data-dashboard-tab="{{ $tab['view']->value }}">{{ $tab['view']->label() }}</span>
                    @else
                        <a href="{{ $tab['url'] }}" class="mbfd-dash-pager__tab" data-dashboard-tab="{{ $tab['view']->value }}">{{ $tab['view']->label() }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($previousUrl)
        <a href="{{ $previousUrl }}" class="mbfd-dash-pager__step" rel="prev"
           aria-label="Previous dashboard: {{ $previous->label() }} ({{ $previous->position() }} of {{ $total }})">
            <x-filament::icon icon="heroicon-m-chevron-left" class="mbfd-dash-pager__icon" />
        </a>
    @else
        <a role="link" aria-disabled="true" class="mbfd-dash-pager__step is-disabled"
           aria-label="{{ $previous ? 'Previous dashboard: '.$previous->label().' (not available)' : 'No previous dashboard' }}">
            <x-filament::icon icon="heroicon-m-chevron-left" class="mbfd-dash-pager__icon" />
        </a>
    @endif

    <p class="mbfd-dash-pager__label" aria-current="page">
        <span class="mbfd-dash-pager__name">{{ $current->label() }}</span>
        <span class="mbfd-dash-pager__count">{{ $current->position() }} of {{ $total }}</span>
    </p>

    @if ($nextUrl)
        <a href="{{ $nextUrl }}" class="mbfd-dash-pager__step mbfd-dash-pager__step--next" rel="next"
           aria-label="Next dashboard: {{ $next->label() }} ({{ $next->position() }} of {{ $total }})">
            <x-filament::icon icon="heroicon-m-chevron-right" class="mbfd-dash-pager__icon" />
        </a>
    @else
        <a role="link" aria-disabled="true" class="mbfd-dash-pager__step mbfd-dash-pager__step--next is-disabled"
           aria-label="{{ $next ? 'Next dashboard: '.$next->label().' (not available)' : 'No next dashboard' }}">
            <x-filament::icon icon="heroicon-m-chevron-right" class="mbfd-dash-pager__icon" />
        </a>
    @endif
</nav>
