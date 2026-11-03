@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\AiAutomation\AiAutomationPlaybookRevision> $revisions */
@endphp
<div class="space-y-3">
    @forelse ($revisions as $revision)
        <details class="rounded-lg border border-gray-200 px-4 py-3">
            <summary class="cursor-pointer text-sm font-medium text-gray-950">
                Version {{ $revision->version }}
                <span class="font-normal text-gray-600">
                    &middot; {{ $revision->created_at?->timezone($timezone)->format('M j, Y g:i A') }} &middot; {{ $revision->edited_by_name }}
                </span>
            </summary>
            <pre class="mt-3 whitespace-pre-wrap break-words text-sm text-gray-800">{{ $revision->body }}</pre>
        </details>
    @empty
        <p class="text-sm text-gray-600">No earlier versions yet.</p>
    @endforelse
</div>
