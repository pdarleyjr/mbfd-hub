@if(auth('web')->check() && ! in_array(filament()->getCurrentPanel()?->getId(), ['admin', 'policy-library'], true))
    <x-hub.member-navigation :navigation="\App\Support\HubShellNavigation::forUser(auth('web')->user())" id="hub-panel-more" />
@endif
