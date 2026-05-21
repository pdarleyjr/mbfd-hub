<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="mb-2 text-sm font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Current PIN
            </h2>

            @if ($fetchError)
                <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                    {{ $fetchError }}
                </p>
            @else
                <p class="font-mono text-3xl tabular-nums text-gray-900 dark:text-gray-100" data-testid="current-bid-pin">
                    {{ $currentPin ?? '—' }}
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    @if ($isDefault)
                        Using the built-in default (2300) — change it any time below.
                    @else
                        Last changed {{ $updatedAt ?? '—' }} by {{ $updatedBy ?? 'unknown' }}.
                    @endif
                </p>
            @endif

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button
                    color="gray"
                    size="sm"
                    wire:click="refresh"
                    icon="heroicon-o-arrow-path"
                >
                    Re-read from bid app
                </x-filament::button>
                <x-filament::button
                    color="gray"
                    size="sm"
                    wire:click="resetToDefault"
                    wire:confirm="Reset the bid PIN back to the built-in default (2300)?"
                >
                    Reset to default (2300)
                </x-filament::button>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="mb-4 text-sm font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Set a new PIN
            </h2>

            <form wire:submit="save" class="space-y-4">
                {{ $this->form }}

                <div class="flex items-center gap-3">
                    <x-filament::button type="submit" icon="heroicon-o-key">
                        Save PIN
                    </x-filament::button>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        The change applies immediately to every device.
                    </p>
                </div>
            </form>
        </section>

        <section class="rounded-xl border border-gray-200 bg-gray-50 p-6 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-300">
            <h2 class="mb-2 font-semibold text-gray-900 dark:text-gray-100">How this works</h2>
            <ul class="list-disc space-y-1 pl-5">
                <li>The PIN is stored in the bid app, not here, so all surfaces show the same value.</li>
                <li>An admin can also change it from the staging bid site → <em>Bid Access PIN</em>.</li>
                <li>Members enter the PIN once on the bid site, then sign in with their employee credentials.</li>
            </ul>
        </section>
    </div>
</x-filament-panels::page>
