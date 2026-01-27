<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-bell class="w-5 h-5" />
                Push Notifications
            </div>
        </x-slot>

        <div id="push-notification-manager" class="space-y-4" data-vapid-key="{{ $this->getVapidPublicKey() }}">
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
                <div class="flex items-center justify-between">
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
            </div>

            <!-- Error State -->
            <div id="error-section" class="hidden p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                <p id="error-message" class="text-sm text-red-700 dark:text-red-300"></p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
