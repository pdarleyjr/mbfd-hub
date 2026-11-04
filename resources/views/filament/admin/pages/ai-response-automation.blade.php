<x-filament-panels::page>
    {{-- The switch: the first and largest thing on the page. --}}
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
                <h2 id="ai-switch-heading" class="text-lg font-bold tracking-wide text-gray-950 dark:text-white">AUTOMATIC REPLIES</h2>
                <p class="mt-1 text-4xl font-black {{ $isOn ? 'text-success-700 dark:text-success-400' : 'text-gray-700 dark:text-gray-200' }}" role="status" aria-live="polite">
                    {{ $isOn ? 'ON' : 'OFF' }}
                </p>
                <p class="mt-2 max-w-xl text-sm text-gray-600 dark:text-gray-300">
                    @if ($isOn)
                        New vehicle checkouts, station requests and Support Services messages get a short reply. Staff still see every original submission.
                    @else
                        Nothing is being answered automatically. Turning it OFF also stops replies that were waiting to go out and any backlog in progress. Normal Hub work is not affected.
                    @endif
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

    {{-- How it is going right now. --}}
    <section aria-labelledby="ai-today-heading">
        <h2 id="ai-today-heading" class="mb-3 text-base font-semibold text-gray-950 dark:text-white">Right now</h2>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <dt class="text-sm font-medium text-gray-600 dark:text-gray-300">Replies sent today</dt>
                <dd class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ number_format($today['sent_today']) }}</dd>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($today['queued']) }} waiting to go out</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <dt class="text-sm font-medium text-gray-600 dark:text-gray-300">Issues waiting for staff</dt>
                <dd class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ number_format($today['waiting_for_staff']) }}</dd>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Replies from the last 7 days that were marked for a person to follow up</p>
            </div>
            <div class="rounded-lg border p-4 {{ $today['errors'] > 0 ? 'border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/10' : 'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' }}">
                <dt class="text-sm font-medium text-gray-600 dark:text-gray-300">Errors needing attention</dt>
                <dd class="mt-1 text-3xl font-bold {{ $today['errors'] > 0 ? 'text-danger-700 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">{{ number_format($today['errors']) }}</dd>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($today['errors'] > 0)
                        Replies that could not be sent in the last 7 days. See Activity &amp; History.
                    @else
                        Nothing has failed in the last 7 days.
                    @endif
                </p>
            </div>
        </dl>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            @if ($modelEnabled)
                AI assistant today: {{ $usage['today_calls'] }} of {{ $usage['daily_limit'] }} uses · This month: ${{ number_format($usage['month_cost_usd'], 2) }} of ${{ number_format($usage['monthly_budget_usd'], 2) }}.
            @else
                Replies use the standard Response Messages. No AI assistant is switched on.
            @endif
        </p>
    </section>

    {{-- Open issues by priority. --}}
    <section aria-labelledby="ai-levels-heading">
        <h2 id="ai-levels-heading" class="mb-1 text-base font-semibold text-gray-950 dark:text-white">Open issues by priority</h2>
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">Issues that are still open, sorted by how soon they need attention. Change what counts as Low, Medium or High in Equipment Priorities.</p>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            @foreach ([
                'low' => ['Low', 'border-success-300 bg-success-50 dark:border-success-500/40 dark:bg-success-500/10', 'text-success-700 dark:text-success-400'],
                'medium' => ['Medium', 'border-warning-300 bg-warning-50 dark:border-warning-500/40 dark:bg-warning-500/10', 'text-warning-700 dark:text-warning-400'],
                'high' => ['High', 'border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/10', 'text-danger-700 dark:text-danger-400'],
            ] as $key => [$label, $box, $text])
                <div class="rounded-lg border-2 p-4 {{ $box }}">
                    <dt class="text-sm font-bold uppercase tracking-wide {{ $text }}">{{ $label }}</dt>
                    <dd class="mt-1 text-4xl font-black {{ $text }}">{{ number_format($levels[$key]) }}</dd>
                    <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">{{ \App\Services\AiAutomation\Admin\AdminLabels::levelMeaning($key) }}</p>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- Historical backlog. --}}
    <x-filament::section heading="Older submissions" description="Submissions from before automatic replies were on, which never got a reply. Preview first; nothing is sent until you press Process.">
        @if ($run && $run->state === 'previewing')
            <p class="text-sm text-gray-600 dark:text-gray-300" role="status" wire:poll.5s>Counting older submissions… nothing is sent.</p>
        @elseif ($run && $run->state === 'running')
            <div wire:poll.10s class="hidden"></div>
        @endif
        @if ($run && is_array($run->preview))
            @php($p = $run->preview)
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                <p>
                    <span class="font-semibold">{{ number_format($p['would_send'] ?? 0) }}</span> would get a reply ·
                    {{ number_format($p['excluded'] ?? 0) }} left out ·
                    {{ number_format($p['already_in_ledger'] ?? 0) }} already handled
                    @if (! empty($p['oldest'])) · oldest {{ \Carbon\Carbon::parse($p['oldest'])->format('M j, Y') }} @endif
                    · counted {{ $run->previewed_at?->diffForHumans() }}
                    @if (! empty($p['capped'])) · large backlog: counts show the first {{ number_format((int) config('ai_automation.backlog.preview_cap_per_type')) }} per type @endif
                </p>
                <div class="grid gap-3 md:grid-cols-3">
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-gray-500">By area</h3>
                        <ul class="mt-1 space-y-0.5">
                            @foreach (($p['by_domain'] ?? []) as $domain => $row)
                                <li>{{ \App\Services\AiAutomation\Admin\AdminLabels::area($domain) }}: {{ $row['would_send'] }} to answer, {{ $row['excluded'] }} left out</li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-gray-500">Left out because</h3>
                        <ul class="mt-1 space-y-0.5">
                            @forelse (($p['excluded_reasons'] ?? []) as $reason => $count)
                                <li>{{ \App\Services\AiAutomation\Admin\AdminLabels::headline($reason) }}: {{ $count }}</li>
                            @empty
                                <li>None</li>
                            @endforelse
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-gray-500">Replies that would go out</h3>
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
                    Status: {{ ['previewing' => 'Counting', 'previewed' => 'Ready to process', 'running' => 'Running', 'paused' => 'Paused', 'completed' => 'Finished', 'cancelled' => 'Stopped'][$run->state] ?? ucfirst($run->state) }}
                    @if ($run->state !== 'previewed')
                        · {{ $run->sent_count }} sent · {{ $run->skipped_count }} skipped · {{ $run->failed_count }} failed
                    @endif
                </p>
            </div>
        @elseif (! $run || $run->state !== 'previewing')
            <p class="text-sm text-gray-600 dark:text-gray-300">Not counted yet. Press Preview to see how many there are.</p>
        @endif
        <div class="mt-4 flex flex-wrap gap-2">
            {{ $this->previewBacklogAction }}
            {{ $this->processBacklogAction }}
            {{ $this->pauseBacklogAction }}
        </div>
    </x-filament::section>

    {{-- A simple look at the last week. --}}
    <x-filament::section heading="The last 7 days" description="Replies sent each day, and how they were written.">
        @php($peak = max(1, collect($week['days'])->max('count')))
        <ul class="space-y-1.5" aria-label="Replies sent per day">
            @foreach ($week['days'] as $day)
                <li class="flex items-center gap-3 text-sm">
                    <span class="w-24 shrink-0 text-gray-600 dark:text-gray-300">{{ $day['label'] }}</span>
                    <span class="h-3 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-white/10" aria-hidden="true">
                        <span class="block h-3 rounded bg-primary-600" style="width: {{ round($day['count'] / $peak * 100) }}%"></span>
                    </span>
                    <span class="w-10 shrink-0 text-right font-semibold text-gray-950 dark:text-white">{{ $day['count'] }}</span>
                </li>
            @endforeach
        </ul>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Standard messages</dt>
                <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($week['from_messages']) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Answered from Knowledge &amp; Instructions</dt>
                <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($week['from_guidance'] + $week['from_model']) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Sent in total</dt>
                <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($week['total']) }}</dd>
            </div>
        </dl>
        @if ($week['by_area'] !== [])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                By area:
                @foreach ($week['by_area'] as $area => $count){{ $area }} {{ $count }}@if (! $loop->last), @endif @endforeach
            </p>
        @endif
        <p class="mt-3 text-sm">
            <a href="{{ \App\Filament\Admin\Pages\AiActivityHistory::getUrl() }}" class="font-medium text-primary-600 underline dark:text-primary-400">See every reply in Activity &amp; History</a>
        </p>
    </x-filament::section>

    {{-- Try a message: never touches real records. --}}
    <x-filament::section heading="Try a message" description="Type a made-up message to see its priority, who handles it, and the reply. Nothing is saved or sent." collapsible collapsed>
        <form wire:submit="runTestResponse" class="grid gap-3 md:grid-cols-4">
            <label class="text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">What kind of message?</span>
                <select wire:model.live="testArea" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="vehicle">A vehicle checkout problem</option>
                    <option value="station">A station request</option>
                    <option value="support">A message to Support Services</option>
                </select>
            </label>
            @if ($testArea === 'vehicle')
                <label class="text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Which item?</span>
                    <input type="text" wire:model="testItem" maxlength="120" placeholder="for example, kitty litter" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                </label>
                <label class="text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">What is wrong with it?</span>
                    <select wire:model="testCondition" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="missing">It is missing</option>
                        <option value="damaged">It is damaged</option>
                        <option value="other">Something else</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Which vehicle? (optional)</span>
                    <select wire:model="testApparatus" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="">Any vehicle</option>
                        @foreach (\App\Services\AiAutomation\Admin\AdminLabels::trucks() as $truckKey => $truckLabel)
                            <option value="{{ $truckKey }}">{{ $truckLabel }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="text-sm md:col-span-4">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $testArea === 'vehicle' ? 'Note from the member (optional)' : 'What the member wrote' }}</span>
                <textarea wire:model="testText" rows="2" maxlength="600" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
            </label>
            <div class="md:col-span-4">
                <x-filament::button type="submit" color="gray">Show me the result</x-filament::button>
            </div>
        </form>
        @if ($testResult !== null)
            <div class="mt-4 grid gap-3 md:grid-cols-2" role="status">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <p class="text-xs font-semibold text-gray-500">What the member would read</p>
                    <p class="mt-1 text-xs text-gray-500">{{ config('ai_automation.sender_label') }}</p>
                    <p class="mt-1 text-sm text-gray-950 dark:text-white">{{ $testResult }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                    <p class="text-xs font-semibold text-gray-500">What staff would see</p>
                    @if ($testLevel !== null)
                        <p class="mt-1">
                            Priority:
                            <x-filament::badge :color="\App\Services\AiAutomation\Admin\AdminLabels::levelColor($testLevel)" class="inline-flex">{{ \App\Services\AiAutomation\Admin\AdminLabels::level($testLevel) }}</x-filament::badge>
                        </p>
                        <p class="mt-1">Handled by: <span class="font-semibold">{{ $testHandler }}</span></p>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $testAlerts }}</p>
                    @endif
                    <p class="mt-2 text-gray-600 dark:text-gray-300">{{ $testWhy }}. {{ $testReason }}</p>
                    @foreach ($testGuidance as $line)
                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $line }}.</p>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>

    {{-- Who changed the switch. --}}
    <x-filament::section heading="Who turned it ON or OFF" collapsible collapsed>
        <ul class="space-y-1 text-sm text-gray-700 dark:text-gray-200">
            @forelse ($switchEvents as $event)
                <li>
                    {{ $event->created_at?->timezone(config('ai_automation.timezone'))->format('M j, Y g:i A') }} ·
                    {{ $event->actor_name }} turned it <span class="font-semibold">{{ $event->to_enabled ? 'ON' : 'OFF' }}</span>
                    @if (! $event->to_enabled && $event->suppressed_count > 0) · stopped {{ $event->suppressed_count }} waiting replies @endif
                    @if ($event->source === 'console') · from the server @endif
                </li>
            @empty
                <li>Never changed. Automatic replies start OFF.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
