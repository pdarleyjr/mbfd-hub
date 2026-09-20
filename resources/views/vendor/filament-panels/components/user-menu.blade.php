@php
    $user = filament()->auth()->user();
    $items = filament()->getUserMenuItems();

    $profileItem = $items['profile'] ?? $items['account'] ?? null;
    $profileItemUrl = $profileItem?->getUrl();
    $profilePage = filament()->getProfilePage();
    $hasProfileItem = filament()->hasProfile() || filled($profileItemUrl);
    $logoutItem = $items['logout'] ?? null;
    $items = \Illuminate\Support\Arr::except($items, ['account', 'logout', 'profile']);
@endphp

{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_BEFORE) }}

<x-filament::dropdown placement="bottom-end" teleport :attributes="\Filament\Support\prepare_inherited_attributes($attributes)->class(['fi-user-menu'])">
    <x-slot name="trigger">
        <button aria-label="Open account menu for {{ filament()->getUserName($user) }}" type="button" class="fi-user-menu-trigger flex shrink-0 items-center gap-x-2 rounded-lg px-1.5 py-1 text-start transition hover:bg-gray-100 focus-visible:bg-gray-100 dark:hover:bg-white/5 dark:focus-visible:bg-white/5">
            <x-filament-panels::avatar.user :user="$user" />
            <span class="hidden max-w-40 truncate text-sm font-semibold text-gray-700 lg:block dark:text-gray-200">{{ filament()->getUserName($user) }}</span>
            <x-filament::icon icon="heroicon-m-chevron-down" class="hidden h-4 w-4 text-gray-500 lg:block dark:text-gray-400" />
        </button>
    </x-slot>

    @if ($profileItem?->isVisible() ?? true)
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_PROFILE_BEFORE) }}
        @if ($hasProfileItem)
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item :color="$profileItem?->getColor()" :icon="$profileItem?->getIcon() ?? \Filament\Support\Facades\FilamentIcon::resolve('panels::user-menu.profile-item') ?? 'heroicon-m-user-circle'" :href="$profileItemUrl ?? filament()->getProfileUrl()" :target="($profileItem?->shouldOpenUrlInNewTab() ?? false) ? '_blank' : null" tag="a">
                    {{ $profileItem?->getLabel() ?? ($profilePage ? $profilePage::getLabel() : null) ?? filament()->getUserName($user) }}
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        @else
            <x-filament::dropdown.header :color="$profileItem?->getColor()" :icon="$profileItem?->getIcon() ?? \Filament\Support\Facades\FilamentIcon::resolve('panels::user-menu.profile-item') ?? 'heroicon-m-user-circle'">
                {{ $profileItem?->getLabel() ?? filament()->getUserName($user) }}
            </x-filament::dropdown.header>
        @endif
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_PROFILE_AFTER) }}
    @endif

    <x-filament::dropdown.list>
        @foreach ($items as $item)
            @php($itemPostAction = $item->getPostAction())
            <x-filament::dropdown.list.item :action="$itemPostAction" :color="$item->getColor()" :href="$item->getUrl()" :icon="$item->getIcon()" :method="filled($itemPostAction) ? 'post' : null" :tag="filled($itemPostAction) ? 'form' : 'a'" :target="$item->shouldOpenUrlInNewTab() ? '_blank' : null">
                {{ $item->getLabel() }}
            </x-filament::dropdown.list.item>
        @endforeach
        <x-filament::dropdown.list.item :action="$logoutItem?->getUrl() ?? filament()->getLogoutUrl()" :color="$logoutItem?->getColor()" :icon="$logoutItem?->getIcon() ?? \Filament\Support\Facades\FilamentIcon::resolve('panels::user-menu.logout-button') ?? 'heroicon-m-arrow-left-on-rectangle'" method="post" tag="form">
            {{ $logoutItem?->getLabel() ?? __('filament-panels::layout.actions.logout.label') }}
        </x-filament::dropdown.list.item>
    </x-filament::dropdown.list>
</x-filament::dropdown>

{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::USER_MENU_AFTER) }}
