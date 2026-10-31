<x-filament-panels::page data-hub-ui="2" data-hub-portal="training">
    <div class="training-settings-grid">
        {{-- Push Notifications Section --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-bell class="w-5 h-5" />
                    Push Notifications
                </div>
            </x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                Push alerts for this device, and which alerts you get, are in one place.
            </p>
            <a href="{{ \App\Filament\Pages\NotificationSettings::getUrl() }}" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-700">
                <x-heroicon-o-bell class="h-5 w-5" />
                Open Notification settings
            </a>
        </x-filament::section>

        {{-- In-App Notifications Info --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-inbox class="w-5 h-5" />
                    In-App Notifications
                </div>
            </x-slot>

            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    In-app notifications appear in the bell icon at the top of the page. They automatically refresh every 30 seconds.
                </p>
                
                <div class="flex items-center gap-3 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                    <x-heroicon-o-information-circle class="w-5 h-5 text-blue-500" />
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        Click the bell icon in the top navigation to view and manage your notifications.
                    </p>
                </div>
            </div>
        </x-filament::section>

        {{-- Profile Section --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-user-circle class="w-5 h-5" />
                    Profile
                </div>
            </x-slot>

            <div class="space-y-4">
                <div class="training-profile-facts">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Name:</span>
                        <p class="font-medium">{{ auth()->user()->name }}</p>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Email:</span>
                        <p class="font-medium">{{ auth()->user()->email }}</p>
                    </div>
                    @if(auth()->user()->display_name)
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Display Name:</span>
                        <p class="font-medium">{{ auth()->user()->display_name }}</p>
                    </div>
                    @endif
                    @if(auth()->user()->rank)
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Rank:</span>
                        <p class="font-medium">{{ auth()->user()->rank }}</p>
                    </div>
                    @endif
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
