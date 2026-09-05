<x-filament-panels::page>
    @php($budget = $this->getBudget())
    @if ($budget === null)
        <x-filament::section>
            <p class="text-sm text-danger-700">No reconciled Cloudflare billing-cycle snapshot exists. Outbound email is blocked.</p>
        </x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section heading="Provider usage">
                {{ number_format($budget->provider_chargeable_used) }} chargeable destinations
            </x-filament::section>
            <x-filament::section heading="Hub reservations">
                {{ number_format($this->getReservedUnits()) }} destinations
            </x-filament::section>
            <x-filament::section heading="Safe ceiling">
                {{ number_format($budget->hub_safe_ceiling) }} destinations
            </x-filament::section>
            <x-filament::section heading="Last reconciled">
                {{ $budget->reconciled_at?->toDayDateTimeString() ?? 'Never - sending blocked' }}
            </x-filament::section>
        </div>
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
