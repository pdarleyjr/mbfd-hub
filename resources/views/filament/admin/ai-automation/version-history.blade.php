@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\AiAutomation\AiKnowledgeArticleVersion> $versions */
    $stamp = static fn ($value): string => $value ? $value->timezone($timezone)->format('M j, Y g:i A') : '';
    $labels = [
        'draft' => 'Draft',
        'in_review' => 'In review',
        'published' => 'Published (live)',
        'superseded' => 'Replaced by a newer version',
        'withdrawn' => 'Withdrawn',
    ];
@endphp
<div class="space-y-3">
    @forelse ($versions as $version)
        <details class="rounded-lg border border-gray-200 px-4 py-3">
            <summary class="cursor-pointer text-sm font-medium text-gray-950">
                Version {{ $version->version }} &middot; {{ $labels[$version->status] ?? $version->status }}
                <span class="font-normal text-gray-600">&middot; {{ $version->title }}</span>
            </summary>
            <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm text-gray-700 sm:grid-cols-2">
                <div><dt class="inline font-medium">Started by:</dt> <dd class="inline">{{ $version->created_by_name }}, {{ $stamp($version->created_at) }}</dd></div>
                @if ($version->published_at)
                    <div><dt class="inline font-medium">Published by:</dt> <dd class="inline">{{ $version->published_by_name }}, {{ $stamp($version->published_at) }}</dd></div>
                @endif
                @if ($version->withdrawn_at)
                    <div><dt class="inline font-medium">Withdrawn:</dt> <dd class="inline">{{ $stamp($version->withdrawn_at) }}</dd></div>
                @endif
                @if ($version->effective_date)
                    <div><dt class="inline font-medium">Effective:</dt> <dd class="inline">{{ $version->effective_date->format('M j, Y') }}</dd></div>
                @endif
                @if ($version->review_date)
                    <div><dt class="inline font-medium">Review by:</dt> <dd class="inline">{{ $version->review_date->format('M j, Y') }}</dd></div>
                @endif
                @if ($version->change_summary)
                    <div class="sm:col-span-2"><dt class="inline font-medium">What changed:</dt> <dd class="inline">{{ $version->change_summary }}</dd></div>
                @endif
                @if ($version->source_note)
                    <div class="sm:col-span-2"><dt class="inline font-medium">Internal source note:</dt> <dd class="inline">{{ $version->source_note }}</dd></div>
                @endif
            </dl>
            <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-3 text-sm text-gray-800">{{ $version->body }}</pre>
        </details>
    @empty
        <p class="text-sm text-gray-600">No versions yet.</p>
    @endforelse
</div>
