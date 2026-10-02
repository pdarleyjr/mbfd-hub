@if(auth('web')->check() && filament()->getCurrentPanel()?->getId() !== 'admin')
    <x-hub.member-navigation :navigation="\App\Support\HubShellNavigation::forUser(auth('web')->user())" id="hub-panel-more" />
@endif
