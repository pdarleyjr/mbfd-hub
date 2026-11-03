@php
    /** @var \App\Models\AiAutomation\AiKnowledgeArticle $article */
    $today = \Carbon\CarbonImmutable::now(config('ai_automation.timezone', 'America/New_York'))->toDateString();
    $stale = $live && $live->review_date && $live->review_date->toDateString() < $today;
@endphp
<div class="space-y-1 text-sm text-gray-800">
    @if ($live)
        <p>
            <strong>Live:</strong> version {{ $live->version }}, published {{ $live->published_at?->timezone($timezone)->format('M j, Y g:i A') }}
            by {{ $live->published_by_name }}. Read-only: saving changes starts a new draft.
        </p>
        @if ($stale)
            <p class="font-medium text-danger-600">The review date has passed, so this article is not being used in replies. Review it, set a new review date and publish again.</p>
        @elseif (! $article->member_safe)
            <p class="font-medium text-warning-600">Not marked safe for members, so replies do not use it.</p>
        @endif
    @else
        <p><strong>Not live.</strong> Replies do not use this article until a version is published.</p>
    @endif
    @if ($draft)
        <p><strong>{{ $draft->status === 'in_review' ? 'In review' : 'Draft' }}:</strong> version {{ $draft->version }}, started by {{ $draft->created_by_name }}.</p>
    @endif
</div>
