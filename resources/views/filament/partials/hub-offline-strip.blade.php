@if(filament()->getCurrentPanel()?->getId() !== 'policy-library' && auth('web')->check())
    <x-hub.offline-strip />
@endif
