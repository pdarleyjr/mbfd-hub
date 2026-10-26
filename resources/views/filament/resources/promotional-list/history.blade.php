<div class="space-y-3">
    @forelse($events as $event)
        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <span class="font-medium text-gray-950 dark:text-white">{{ $event->actor?->name ?? 'System' }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $event->created_at?->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') }}</span>
            </div>
            <p class="mt-1 break-words text-gray-700 dark:text-gray-300">{{ \App\Services\Personnel\Promotions\PromotionalHistoryPresenter::describe($event) }}</p>
            @if (filled($event->reason))
                <p class="mt-1 break-words text-gray-600 dark:text-gray-400">Reason: {{ $event->reason }}</p>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-600 dark:text-gray-400">No history recorded yet.</p>
    @endforelse
</div>
