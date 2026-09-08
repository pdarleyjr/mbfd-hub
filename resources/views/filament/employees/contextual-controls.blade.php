@php
    $page = $getLivewire();
    $visibleActions = [];
    foreach ($actions as $name => $label) {
        $action = $page->getAction($name);
        if ($action !== null && $action->isVisible()) {
            if (in_array($name, ['correctEmployeeId', 'changeEmploymentStatus', 'changeCityEmail'], true)) {
                $action->color('gray');
            }
            // Presentation only: retain the same action instance, mount, form,
            // visibility and backend authorization used by the original menu.
            $visibleActions[] = $action->button()->label($label);
        }
    }
    $account = \App\Filament\Support\EmployeeAccessSchema::account($page->getRecord());
    $emptyReason = 'Read-only with your current access.';
    if (in_array($context, ['hub-roles', 'hub-capabilities', 'ecosystem', 'workgroups'], true)) {
        if ($account === null) {
            $emptyReason = 'Create a login account in Login & recovery before assigning roles, access or workgroups.';
        } elseif (auth()->user()?->is($account)) {
            $emptyReason = 'Your own access cannot be changed here.';
        } elseif (in_array($context, ['hub-capabilities', 'ecosystem'], true) && $account->hasRole('super_admin')) {
            $emptyReason = 'Super Administrator privileges are inherited; manage Hub roles separately.';
        }
    } elseif ($context === 'profile-identity' && $account !== null && auth()->user()?->is($account)) {
        $emptyReason = 'Your own identity and employment status cannot be changed here.';
    }
@endphp
<section data-employee-controls="{{ $context }}" aria-label="{{ $title }}" class="space-y-3 border-b border-gray-200 pb-4 dark:border-gray-700">
    <div class="space-y-1">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $title }}</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $description }}</p>
    </div>
    @if (count($visibleActions))
        <div class="flex flex-wrap items-center gap-3">
            @foreach ($visibleActions as $action)
                {{ $action }}
            @endforeach
        </div>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyReason }}</p>
    @endif
</section>
