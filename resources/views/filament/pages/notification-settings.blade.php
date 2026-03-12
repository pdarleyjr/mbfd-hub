<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-[1.75rem] border border-stone-200/80 bg-stone-50/90 p-6 shadow-sm ring-1 ring-white/60 sm:p-8">
            <div class="max-w-3xl space-y-4">
                <div class="inline-flex items-center gap-2 rounded-full border border-stone-200 bg-white/80 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-stone-600">
                    <x-heroicon-o-bell class="h-4 w-4 text-amber-600" />
                    Alert Routing
                </div>

                <div class="space-y-2">
                    <h2 class="text-2xl font-semibold tracking-tight text-stone-900 sm:text-3xl">
                        Choose which submissions should notify you.
                    </h2>
                    <p class="max-w-2xl text-sm leading-6 text-stone-600 sm:text-[0.95rem]">
                        These preferences control whether your account is included when new submissions are broadcast. In-app alerts and browser push both honor the toggles below.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 border-t border-stone-200/80 pt-5 text-sm text-stone-600 sm:grid-cols-3 sm:gap-6">
                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-stone-500">Default behavior</p>
                    <p>All categories start enabled until you save a custom preference profile.</p>
                </div>
                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-stone-500">Device subscriptions</p>
                    <p>{{ $this->getPushSubscriptionCount() }} browser push subscription{{ $this->getPushSubscriptionCount() === 1 ? '' : 's' }} connected to this account.</p>
                </div>
                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-stone-500">Important note</p>
                    <p>Turning a category off skips your user entirely, even if your browser is subscribed to push notifications.</p>
                </div>
            </div>
        </section>

        <x-filament-panels::form wire:submit="save">
            <x-filament::section
                heading="Submission alert categories"
                description="Use these toggles to control which operational submissions generate alerts for this account."
            >
                <div class="space-y-6">
                    {{ $this->form }}

                    <div class="flex flex-col gap-3 border-t border-stone-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-stone-600">
                            Browser push requires a valid subscription and notification permission on each device.
                        </p>

                        <x-filament::button type="submit" size="lg">
                            Save preferences
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        </x-filament-panels::form>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
