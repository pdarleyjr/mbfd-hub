<x-filament-panels::page>
    <div class="grid gap-6 md:grid-cols-2">
        {{-- Push Notifications Section --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-bell class="w-5 h-5" />
                    Push Notifications
                </div>
            </x-slot>

            <div id="push-notification-manager" class="space-y-4" data-vapid-key="{{ $this->getVapidPublicKey() }}" x-data="pushNotificationWidget()" x-init="initWidget()">
                <!-- Loading State -->
                <div id="push-loading" class="flex items-center gap-2 text-gray-500">
                    <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Checking notification status...
                </div>

                <!-- iOS Add to Home Screen Prompt -->
                <div id="ios-prompt" class="hidden p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                    <div class="flex items-start gap-3">
                        <x-heroicon-o-device-phone-mobile class="w-6 h-6 text-amber-600 flex-shrink-0 mt-0.5" />
                        <div>
                            <h4 class="font-semibold text-amber-800 dark:text-amber-200">Enable Push Notifications on iOS</h4>
                            <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">
                                To receive critical alerts, you need to add this app to your Home Screen:
                            </p>
                            <ol class="text-sm text-amber-700 dark:text-amber-300 mt-2 list-decimal ml-4 space-y-1">
                                <li>Tap the <strong>Share</strong> icon (square with arrow) at the bottom of Safari</li>
                                <li>Scroll down and tap <strong>"Add to Home Screen"</strong></li>
                                <li>Tap <strong>"Add"</strong> in the top right corner</li>
                                <li>Open the app from your Home Screen and return here</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- Not Supported -->
                <div id="not-supported" class="hidden p-4 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg">
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-gray-500" />
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Push notifications are not supported in this browser.
                        </p>
                    </div>
                </div>

                <!-- Permission Denied -->
                <div id="permission-denied" class="hidden p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-x-circle class="w-6 h-6 text-red-500" />
                        <div>
                            <p class="text-sm text-red-700 dark:text-red-300">
                                Notification permission was denied. Please enable notifications in your browser settings.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Subscribe Button -->
                <div id="subscribe-section" class="hidden">
                    <button
                        id="subscribe-btn"
                        type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg font-medium text-sm transition-colors"
                    >
                        <x-heroicon-o-bell-alert class="w-5 h-5" />
                        Enable Push Notifications
                    </button>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        Receive instant alerts for equipment out of service, low stock, and critical updates.
                    </p>
                </div>

                <!-- Subscribed State -->
                <div id="subscribed-section" class="hidden p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <x-heroicon-o-check-circle class="w-6 h-6 text-green-500" />
                            <div>
                                <p class="font-medium text-green-800 dark:text-green-200">Push Notifications Enabled</p>
                                <p class="text-sm text-green-700 dark:text-green-300">You will receive critical alerts on this device.</p>
                            </div>
                        </div>
                        <button
                            id="unsubscribe-btn"
                            type="button"
                            class="text-sm text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                        >
                            Disable
                        </button>
                    </div>
                    <button
                        id="test-notification-btn"
                        type="button"
                        onclick="sendTestNotification()"
                        class="inline-flex items-center gap-2 px-3 py-1.5 bg-primary-600 hover:bg-primary-700 text-white rounded-lg font-medium text-sm transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        Send Test Notification
                    </button>
                </div>

                <!-- Error State -->
                <div id="error-section" class="hidden p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                    <p id="error-message" class="text-sm text-red-700 dark:text-red-300"></p>
                </div>
            </div>
        </x-filament::section>

        {{-- User Management Section --}}
        @if($this->canManageUsers())
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-users class="w-5 h-5" />
                    User Management
                </div>
            </x-slot>

            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Manage user accounts, roles, and permissions for the MBFD Support Hub.
                </p>
                
                <a 
                    href="{{ route('filament.admin.resources.users.index') }}"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg font-medium text-sm transition-colors"
                >
                    <x-heroicon-o-user-group class="w-5 h-5" />
                    Manage Users
                </a>
            </div>
        </x-filament::section>
        @endif

        {{-- Database Notifications Info --}}
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
                <div class="grid grid-cols-2 gap-4 text-sm">
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
