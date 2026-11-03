<x-filament-panels::page>
    {{-- Master switch: the first and largest thing on the page. --}}
    <section
        aria-labelledby="ai-switch-heading"
        @class([
            'rounded-xl border-2 p-5 sm:p-6',
            'border-success-500 bg-success-50 dark:bg-success-500/10' => $isOn,
            'border-gray-300 bg-white dark:border-white/20 dark:bg-white/5' => ! $isOn,
        ])
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="ai-switch-heading" class="text-lg font-bold tracking-wide text-gray-950 dark:text-white">AI AUTOMATIONS</h2>
                <p class="mt-1 text-3xl font-black {{ $isOn ? 'text-success-700 dark:text-success-400' : 'text-gray-700 dark:text-gray-200' }}" role="status" aria-live="polite">
                    {{ $isOn ? 'ON' : 'OFF' }}
                </p>
                <p class="mt-2 max-w-xl text-sm text-gray-600 dark:text-gray-300">
                    OFF stops all automated acknowledgments, replies, routing and backlog processing. Manual Hub workflows keep working.
                </p>
                @if ($hardDisabled)
                    <p class="mt-2 text-sm font-semibold text-danger-600 dark:text-danger-400">Turned off on the server. It cannot be turned on from here.</p>
                @endif
            </div>
            <div class="shrink-0">
                @if ($isOn)
                    {{ $this->turnOffAction }}
                @else
                    {{ $this->turnOnAction }}
                @endif
            </div>
        </div>
    </section>

    {{-- Today at a glance. --}}
    <section aria-labelledby="ai-today-heading">
        <h2 id="ai-today-heading" class="mb-3 text-base font-semibold text-gray-950 dark:text-white">Today</h2>
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                ['Responses sent', $stats['sent']],
                ['Flagged for staff', $stats['flagged']],
                ['Skipped', $stats['skipped']],
                ['Stopped by OFF', $stats['stopped']],
                ['Failed', $stats['failed']],
                ['Waiting', $stats['queued']],
            ] as [$label, $value])
                <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-white/5">
                    <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($value) }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            @if ($modelEnabled)
                Model calls today: {{ $usage['today_calls'] }} of {{ $usage['daily_limit'] }} · This month: ${{ number_format($usage['month_cost_usd'], 2) }} of ${{ number_format($usage['monthly_budget_usd'], 2) }}.
            @else
                Replies come from the Response Playbooks only; no AI model is in use.
            @endif
            Every submission stays in its normal staff list.
        </p>
    </section>

    {{-- Historical backlog. --}}
    <x-filament::section heading="Pending backlog" description="Older submissions that never got a response. Preview first; nothing is sent until you press Process.">
        @if ($run && $run->state === 'previewing')
            <p class="text-sm text-gray-600 dark:text-gray-300" role="status" wire:poll.5s>Counting older submissions… nothing is sent.</p>
        @elseif ($run && $run->state === 'running')
            <div wire:poll.10s class="hidden"></div>
        @endif
        @if ($run && is_array($run->preview))
            @php($p = $run->preview)
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                <p>
                    <span class="font-semibold">{{ number_format($p['would_send'] ?? 0) }}</span> would get a response ·
                    {{ number_format($p['excluded'] ?? 0) }} excluded ·
                    {{ number_format($p['already_in_ledger'] ?? 0) }} already handled
                    @if (! empty($p['oldest'])) · oldest {{ \Carbon\Carbon::parse($p['oldest'])->format('M j, Y') }} @endif
                    · previewed {{ $run->previewed_at?->diffForHumans() }}
                    @if (! empty($p['capped'])) · large backlog: counts show the first {{ number_format((int) config('ai_automation.backlog.preview_cap_per_type')) }} per type @endif
                </p>
                <div class="grid gap-3 md:grid-cols-3">
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-gray-500">By area</h3>
                        <ul class="mt-1 space-y-0.5">
                            @foreach (($p['by_domain'] ?? []) as $domain => $row)
                                <li>{{ ['vehicle' => 'Vehicle', 'station' => 'Station requests', 'support' => 'Support Services'][$domain] ?? $domain }}: {{ $row['would_send'] }} to send, {{ $row['excluded'] }} excluded</li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-gray-500">Excluded because</h3>
                        <ul class="mt-1 space-y-0.5">
                            @forelse (($p['excluded_reasons'] ?? []) as $reason => $count)
                                <li>{{ \Illuminate\Support\Str::of($reason)->replace('_', ' ')->ucfirst() }}: {{ $count }}</li>
                            @empty
                                <li>None</li>
                            @endforelse
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-gray-500">Replies that would be sent</h3>
                        <ul class="mt-1 space-y-1">
                            @forelse (($p['replies'] ?? []) as $text => $count)
                                <li><span class="font-semibold">{{ $count }}×</span> “{{ $text }}”</li>
                            @empty
                                <li>None</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
                <p class="font-medium">
                    Status: {{ ucfirst($run->state) }}
                    @if ($run->state !== 'previewed')
                        · {{ $run->sent_count }} sent · {{ $run->skipped_count }} skipped · {{ $run->failed_count }} failed
                    @endif
                </p>
            </div>
        @elseif (! $run || $run->state !== 'previewing')
            <p class="text-sm text-gray-600 dark:text-gray-300">No preview yet.</p>
        @endif
        <div class="mt-4 flex flex-wrap gap-2">
            {{ $this->previewBacklogAction }}
            {{ $this->processBacklogAction }}
            {{ $this->pauseBacklogAction }}
        </div>
    </x-filament::section>

    {{-- Activity log. --}}
    <x-filament::section heading="Activity" description="Every automated response, what it said and why. The original submission always stays with staff.">
        {{ $this->table }}
    </x-filament::section>

    {{-- Synthetic test: never touches real records. --}}
    <x-filament::section heading="Test a response" description="Try a made-up submission to see the reply. Nothing is saved or sent." collapsible collapsed>
        <form wire:submit="runTestResponse" class="grid gap-3 md:grid-cols-4">
            <label class="text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">Area</span>
                <select wire:model.live="testArea" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="vehicle">Vehicle checkout finding</option>
                    <option value="station">Station request</option>
                    <option value="support">Support Services message</option>
                </select>
            </label>
            @if ($testArea === 'vehicle')
                <label class="text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Item</span>
                    <input type="text" wire:model="testItem" maxlength="120" placeholder="e.g. Kitty Litter" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                </label>
                <label class="text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Condition</span>
                    <select wire:model="testCondition" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="missing">Missing</option>
                        <option value="damaged">Damaged</option>
                        <option value="other">Other</option>
                    </select>
                </label>
            @endif
            <label class="text-sm md:col-span-4">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $testArea === 'vehicle' ? 'Member note (optional)' : 'Member text' }}</span>
                <textarea wire:model="testText" rows="2" maxlength="600" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
            </label>
            <div class="md:col-span-4">
                <x-filament::button type="submit" color="gray">Preview reply</x-filament::button>
            </div>
        </form>
        @if ($testResult !== null)
            <div class="mt-4 rounded-lg border border-gray-200 p-3 dark:border-white/10" role="status">
                <p class="text-xs font-semibold text-gray-500">{{ config('ai_automation.sender_label') }}</p>
                <p class="mt-1 text-sm text-gray-950 dark:text-white">{{ $testResult }}</p>
                <p class="mt-2 text-xs text-gray-500">{{ $testReason }}</p>
            </div>
        @endif
    </x-filament::section>

    {{-- Who changed the switch. --}}
    <x-filament::section heading="Switch history" collapsible collapsed>
        <ul class="space-y-1 text-sm text-gray-700 dark:text-gray-200">
            @forelse ($switchEvents as $event)
                <li>
                    {{ $event->created_at?->timezone(config('ai_automation.timezone'))->format('M j, Y g:i A') }} ·
                    {{ $event->actor_name }} turned it <span class="font-semibold">{{ $event->to_enabled ? 'ON' : 'OFF' }}</span>
                    @if (! $event->to_enabled && $event->suppressed_count > 0) · stopped {{ $event->suppressed_count }} queued @endif
                    @if ($event->source === 'console') · from the server @endif
                </li>
            @empty
                <li>Never changed. AI automations start OFF.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
