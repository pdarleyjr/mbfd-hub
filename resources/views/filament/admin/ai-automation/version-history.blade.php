@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\AiAutomation\AiKnowledgeArticleVersion> $versions */
    $stamp = static fn ($value): string => $value ? $value->timezone($timezone)->format('M j, Y g:i A') : '';
    $labels = [
        'draft' => 'Draft, not live',
        'in_review' => 'Draft, not live',
        'published' => 'Live now',
        'superseded' => 'Was live, replaced later',
        'withdrawn' => 'Was turned off',
    ];
@endphp
<div class="space-y-3">
    <p class="text-sm text-gray-600 dark:text-gray-300">To go back to one of these, close this window and press "Restore previous".</p>
    @forelse ($versions as $version)
        <details class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
            <summary class="cursor-pointer text-sm font-medium text-gray-950 dark:text-white">
                Saved {{ $stamp($version->created_at) }} by {{ $version->created_by_name }}
                <span class="font-normal text-gray-600 dark:text-gray-300">&middot; {{ $labels[$version->status] ?? 'Earlier version' }}</span>
            </summary>
            <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm text-gray-700 dark:text-gray-200 sm:grid-cols-2">
                @if ($version->published_at)
                    <div><dt class="inline font-medium">Made live by:</dt> <dd class="inline">{{ $version->published_by_name }}, {{ $stamp($version->published_at) }}</dd></div>
                @endif
                @if ($version->withdrawn_at)
                    <div><dt class="inline font-medium">Turned off:</dt> <dd class="inline">{{ $stamp($version->withdrawn_at) }}</dd></div>
                @endif
                @if ($version->effective_date)
                    <div><dt class="inline font-medium">Start using on:</dt> <dd class="inline">{{ $version->effective_date->format('M j, Y') }}</dd></div>
                @endif
                @if ($version->review_date)
                    <div><dt class="inline font-medium">Check again by:</dt> <dd class="inline">{{ $version->review_date->format('M j, Y') }}</dd></div>
                @endif
                @if ($version->change_summary)
                    <div class="sm:col-span-2"><dt class="inline font-medium">What changed:</dt> <dd class="inline">{{ $version->change_summary }}</dd></div>
                @endif
                @if ($version->source_note)
                    <div class="sm:col-span-2"><dt class="inline font-medium">Where it came from:</dt> <dd class="inline">{{ $version->source_note }}</dd></div>
                @endif
            </dl>
            <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-3 text-sm text-gray-800 dark:bg-white/5 dark:text-gray-200">{{ $version->body }}</pre>
        </details>
    @empty
        <p class="text-sm text-gray-600 dark:text-gray-300">Nothing saved yet.</p>
    @endforelse
</div>
