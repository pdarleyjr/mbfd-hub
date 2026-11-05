@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\SupportTriage\SupportEquipmentRuleRevision> $revisions */
    use App\Services\AiAutomation\Admin\AdminLabels;
@endphp
<div class="space-y-3">
    @forelse ($revisions as $revision)
        @php($s = $revision->snapshot)
        <details class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
            <summary class="cursor-pointer text-sm font-medium text-gray-950 dark:text-white">
                Saved {{ $revision->created_at?->timezone($timezone)->format('M j, Y g:i A') }}
                <span class="font-normal text-gray-600 dark:text-gray-300">&middot; {{ $revision->edited_by_name }} &middot; Priority {{ AdminLabels::level($s['level'] ?? null) }}</span>
            </summary>
            <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm text-gray-700 dark:text-gray-200 sm:grid-cols-2">
                <div><dt class="inline font-medium">Name:</dt> <dd class="inline">{{ $s['name'] ?? '' }}</dd></div>
                <div><dt class="inline font-medium">Kind:</dt> <dd class="inline">{{ AdminLabels::category($s['category'] ?? null) }}</dd></div>
                <div><dt class="inline font-medium">Handled by:</dt> <dd class="inline">{{ AdminLabels::handler($s['handler'] ?? null) }}</dd></div>
                <div><dt class="inline font-medium">Contact 300:</dt> <dd class="inline">{{ AdminLabels::contact300($s['contact_300'] ?? null) }}</dd></div>
                <div class="sm:col-span-2"><dt class="inline font-medium">Reply:</dt> <dd class="inline">{{ filled($s['member_response'] ?? null) ? $s['member_response'] : 'Standard message' }}</dd></div>
                @if (! empty($s['aliases']))
                    <div class="sm:col-span-2"><dt class="inline font-medium">Other names:</dt> <dd class="inline">{{ implode(', ', $s['aliases']) }}</dd></div>
                @endif
            </dl>
        </details>
    @empty
        <p class="text-sm text-gray-600 dark:text-gray-300">No earlier versions yet.</p>
    @endforelse
</div>
