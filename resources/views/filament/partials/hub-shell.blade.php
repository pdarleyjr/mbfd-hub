@if(auth('web')->check())
    @php
        $navigation = \App\Support\HubShellNavigation::forUser(auth('web')->user());
        $module = match (filament()->getCurrentPanel()?->getId()) {
            'admin' => 'Admin', 'employee' => 'Employee', 'workgroups' => 'Workgroups', 'training' => 'Training', default => 'Apps',
        };
    @endphp
    <div class="hub-panel-shell" data-hub-shell>
        <x-hub.app-switcher :navigation="$navigation" :module="$module" id="hub-panel-apps" />
        <span class="hub-shell-connection" data-hub-connection role="status">Connected</span>
    </div>
@endif
@vite('resources/js/hub-shell.js')
