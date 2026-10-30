<x-filament-panels::page>
<div data-notification-settings>
    @vite('resources/js/pwa/push-enrollment.ts')

    @php
        $devices = $this->getDevices();
    @endphp

    <div class="space-y-6">
        <section class="rounded-xl border border-hub-border bg-hub-canvas p-6 shadow-sm ring-1 ring-white/60 sm:p-8">
            <div class="max-w-3xl space-y-3">
                <h2 class="text-2xl font-semibold tracking-tight text-hub-ink sm:text-3xl">
                    Choose what you want to hear about.
                </h2>
                <p class="max-w-2xl text-sm leading-6 text-hub-muted sm:text-[0.95rem]">
                    In-app alerts always arrive in the bell at the top of the page. Push alerts to your phone or computer, and city email, are extras you can turn on for each kind of alert. You only see the alerts you are allowed to receive, and this list changes by itself if your duties change.
                </p>
            </div>
        </section>

        <section class="rounded-xl border border-hub-border bg-white p-6 shadow-sm sm:p-8" aria-labelledby="this-device-heading">
            <h3 id="this-device-heading" class="text-lg font-semibold text-hub-ink">This device</h3>

            <div
                wire:ignore
                data-push-enrollment
                data-vapid-key="{{ $this->getVapidPublicKey() }}"
                data-endpoint-hashes="{{ json_encode($this->getEndpointHashes()) }}"
                class="mt-4 space-y-4"
            >
                <p data-role="summary" class="text-sm leading-6 text-hub-ink">Checking this device…</p>

                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-5">
                    <div><dt class="text-hub-muted">Supported</dt><dd data-state="supported" class="font-semibold text-hub-ink">…</dd></div>
                    <div><dt class="text-hub-muted">Installed</dt><dd data-state="installed" class="font-semibold text-hub-ink">…</dd></div>
                    <div><dt class="text-hub-muted">Permission</dt><dd data-state="permission" class="font-semibold text-hub-ink">…</dd></div>
                    <div><dt class="text-hub-muted">Subscribed</dt><dd data-state="subscribed" class="font-semibold text-hub-ink">…</dd></div>
                    <div><dt class="text-hub-muted">Enabled</dt><dd data-state="enabled" class="font-semibold text-hub-ink">…</dd></div>
                </dl>

                <div class="flex flex-wrap gap-3">
                    <button type="button" data-role="enable" hidden class="inline-flex min-h-11 items-center rounded-lg bg-hub-blue px-4 py-2 text-sm font-bold text-white hover:bg-hub-blue-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">Turn on alerts for this device</button>
                    <button type="button" data-role="test" hidden class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Send a test</button>
                    <button type="button" data-role="disable" hidden class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Turn off on this device</button>
                </div>

                <p data-role="message" role="status" aria-live="polite" class="min-h-5 text-sm text-hub-muted"></p>
            </div>

            {{-- A device preference kept in this browser (localStorage), mounted by resources/js/pwa/alert-sound.ts. --}}
            <div wire:ignore data-alert-sound-settings class="mt-6 space-y-3 border-t border-hub-border pt-5">
                <h4 class="text-base font-semibold text-hub-ink">While the Hub is open</h4>
                <p class="text-sm leading-6 text-hub-muted">
                    A new alert pops up for a few seconds on the screen you are using. It stays in the bell until you read it.
                </p>

                <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold text-hub-ink">
                    <input
                        type="checkbox"
                        role="switch"
                        data-role="sound-toggle"
                        class="h-5 w-5 rounded border-hub-border-strong text-hub-blue focus:ring-2 focus:ring-hub-focus"
                    >
                    <span>Play a sound for new alerts while the Hub is open</span>
                </label>
                <p class="text-sm text-hub-muted">This device only. Off unless you turn it on.</p>

                <button
                    type="button"
                    data-role="sound-test"
                    class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus"
                >Play test sound</button>

                <p data-role="sound-message" role="status" aria-live="polite" class="min-h-5 text-sm text-hub-muted"></p>

                <p class="text-sm text-hub-muted">
                    Alerts on your lock screen use your phone's own notification sound and Focus settings; the Hub can't change those.
                </p>
            </div>
        </section>

        <section class="rounded-xl border border-hub-border bg-white p-6 shadow-sm sm:p-8" aria-labelledby="your-devices-heading">
            <h3 id="your-devices-heading" class="text-lg font-semibold text-hub-ink">Your devices</h3>
            <p class="mt-1 text-sm text-hub-muted">Devices that can currently receive push alerts for your account.</p>

            @if ($devices->isEmpty())
                <p class="mt-4 text-sm text-hub-muted">No devices yet. In-app alerts still arrive in the bell.</p>
            @else
                <ul class="mt-4 divide-y divide-hub-border">
                    @foreach ($devices as $device)
                        <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="push-device-{{ $device->id }}">
                            <div class="text-sm">
                                <p class="font-semibold text-hub-ink">{{ $device->device_label ?: 'Unknown device' }}</p>
                                <p class="text-hub-muted">
                                    Last alert delivered: {{ $device->last_success_at?->diffForHumans() ?? 'none yet' }}
                                    @if ($device->backoff_until?->isFuture())
                                        &middot; Having trouble reaching this device. The Hub will retry on its own.
                                        @if ($device->failure_count >= \App\Services\Notifications\PushDeviceHealth::SUGGEST_REPLACEMENT_AFTER)
                                            If this keeps happening, remove it and turn alerts on again on that device.
                                        @endif
                                    @endif
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="removeDevice({{ $device->id }})"
                                wire:confirm="Remove this device? It will stop receiving push alerts."
                                class="inline-flex min-h-11 items-center rounded-lg border border-hub-border-strong px-4 py-2 text-sm font-bold hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus"
                            >Remove</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <x-filament-panels::form wire:submit="save">
            {{ $this->form }}

            <div class="flex flex-col gap-3 border-t border-hub-border pt-5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-hub-muted">
                    Push needs alerts turned on for the device you use. City email needs a verified city email on your member record.
                </p>

                <x-filament::button type="submit" size="lg">
                    Save my choices
                </x-filament::button>
            </div>
        </x-filament-panels::form>
    </div>

    <x-filament-actions::modals />
</div>
</x-filament-panels::page>
