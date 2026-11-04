@php
    /** @var \App\Models\AiAutomation\AiKnowledgeArticle $article */
    $today = \Carbon\CarbonImmutable::now(config('ai_automation.timezone', 'America/New_York'))->toDateString();
    $stale = $live && $live->review_date && $live->review_date->toDateString() < $today;
@endphp
<div class="space-y-1 text-sm text-gray-800 dark:text-gray-200">
    @if ($live)
        <p>
            <strong>Live now.</strong> Published {{ $live->published_at?->timezone($timezone)->format('M j, Y g:i A') }}
            by {{ $live->published_by_name }}. Changes you save become a draft and do not affect answers until you press Publish.
        </p>
        @if ($stale)
            <p class="font-medium text-danger-600">The "check again by" date has passed, so this is not being used. Look it over, set a new date and publish again.</p>
        @elseif (! $article->member_safe)
            <p class="font-medium text-warning-600">Marked as not OK to share with members, so automated answers do not use it.</p>
        @endif
    @else
        <p><strong>Not live.</strong> Automated answers do not use this until you press Publish.</p>
    @endif
    @if ($draft)
        <p><strong>Draft waiting.</strong> Started by {{ $draft->created_by_name }}. It is not live yet.</p>
    @endif
</div>
