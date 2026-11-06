@if(filament()->getCurrentPanel()?->getId() !== 'policy-library')
@if(auth('web')->check())
    @php
        $navigation = \App\Support\HubShellNavigation::forUser(auth('web')->user());
        $module = match (filament()->getCurrentPanel()?->getId()) {
            'admin' => 'Admin', 'employee' => 'Employee', 'workgroups' => 'Workgroups', 'training' => 'Training', default => 'Apps',
        };
    @endphp
    <div class="hub-panel-shell" data-hub-shell>
        {{-- Below 1024px the sidebar (and the brand in its header) is a closed drawer: the bar carries the seal itself. --}}
        <span class="hub-panel-shell__brand"><x-hub.brand /></span>
        <x-hub.app-switcher :navigation="$navigation" :module="$module" id="hub-panel-apps" />
        <span class="hub-shell-connection" data-hub-connection role="status">Connected</span>
    </div>
@endif
@vite('resources/js/hub-shell.js')
@endif
