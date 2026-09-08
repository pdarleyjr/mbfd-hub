<x-filament-panels::page>
    @php($budget = $this->getBudget())
    @if ($budget === null)
        <x-filament::section>
            <p class="text-sm text-danger-700">No reconciled Cloudflare billing-cycle snapshot exists. Outbound email is blocked.</p>
        </x-filament::section>
    @else
        <x-filament::section heading="Monthly billing cycle">
            <p>{{ $budget->cycle_start->utc()->format('M j, Y H:i') }} – {{ $budget->cycle_end->utc()->format('M j, Y H:i') }} UTC</p>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">The monthly allowance resets automatically with Cloudflare's next billing cycle. Usage refreshes every five minutes. Uncertain deliveries remain reserved across a cycle boundary until resolved.</p>
        </x-filament::section>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section heading="Provider usage">
                {{ number_format($budget->provider_chargeable_used) }} chargeable destinations
            </x-filament::section>
            <x-filament::section heading="Current-cycle Hub reservations">
                {{ number_format($this->getReservedUnits()) }} destinations
            </x-filament::section>
            <x-filament::section heading="Safe ceiling">
                {{ number_format(min(2850, $budget->hub_safe_ceiling, (int) config('communications.cloudflare.safe_email_ceiling', 2850))) }} destinations
            </x-filament::section>
            <x-filament::section heading="Last reconciled">
                {{ $budget->reconciled_at?->toDayDateTimeString() ?? 'Never - sending blocked' }}
            </x-filament::section>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">Provider and Hub counts are added conservatively; some usage may appear in both. Daily quotas reset separately, and the Hub also limits sending to five recipient units per minute. Worker statistics below are informational: outbound email uses the direct REST API, not a Worker.</p>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <x-filament::section heading="Provider daily quota">
                {{ $budget->provider_daily_used === null ? 'Unknown' : number_format($budget->provider_daily_used) }} /
                {{ $budget->provider_daily_quota === null ? 'Unknown - sending blocked' : number_format($budget->provider_daily_quota) }}
            </x-filament::section>
            <x-filament::section heading="Worker requests">
                {{ $budget->worker_requests_used === null ? 'Unknown' : number_format($budget->worker_requests_used) }} /
                {{ number_format($budget->worker_request_threshold) }}
            </x-filament::section>
            <x-filament::section heading="Worker CPU time">
                {{ $budget->worker_cpu_ms_used === null ? 'Unknown' : number_format($budget->worker_cpu_ms_used) }} ms /
                {{ number_format($budget->worker_cpu_ms_threshold) }} ms
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
