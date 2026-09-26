@php
    $compact = $compact ?? false;
    $ctaUrl = $update->safeCtaUrl();
    $priorityClasses = match ($update->priority) {
        \App\Enums\DepartmentUpdatePriority::Critical => 'border-l-4 border-l-red-600',
        \App\Enums\DepartmentUpdatePriority::Important => 'border-l-4 border-l-amber-500',
        default => 'border-l-4 border-l-blue-500',
    };
    $priorityBadge = match ($update->priority) {
        \App\Enums\DepartmentUpdatePriority::Critical => 'bg-red-50 text-red-700 ring-red-600/20',
        \App\Enums\DepartmentUpdatePriority::Important => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        default => 'bg-blue-50 text-blue-700 ring-blue-600/20',
    };
@endphp

<article data-department-update class="overflow-hidden rounded-xl border border-hub-border bg-hub-surface shadow-card {{ $priorityClasses }}">
    @if($update->image_path && ! $compact)
        <img src="{{ route('updates.image', $update) }}" alt="" class="h-52 w-full object-cover">
    @endif
    <div class="p-4 sm:p-5">
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $priorityBadge }}">
                {{ $update->priority->label() }}
            </span>
            <span class="inline-flex rounded-full bg-hub-surface-muted px-2.5 py-1 text-xs font-semibold text-hub-ink-secondary ring-1 ring-inset ring-hub-border">
                {{ $update->category->label() }}
            </span>
            @if($update->is_pinned)
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-hub-muted">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 4 5 5-3 1-4 4-1 5-2-2-2-2 5-1 4-4 1-3-5-5Z"></path></svg>
                    Pinned
                </span>
            @endif
        </div>
        <h3 class="mt-3 font-heading text-base font-bold leading-snug text-hub-ink sm:text-lg">
            <a href="{{ route('updates.show', $update) }}" class="rounded-sm hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600">
                {{ $update->title }}
            </a>
        </h3>
        @if($compact)
            <p class="mt-2 text-sm leading-relaxed text-hub-muted">{{ $update->excerpt(165) }}</p>
        @else
            <div class="prose prose-sm mt-3 max-w-none text-hub-ink-secondary">
                {!! \App\Support\Security\SafeHtml::report($update->body) !!}
            </div>
        @endif
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-hub-border-soft pt-3">
            <p class="text-xs text-hub-muted">
                <time datetime="{{ $update->publish_at?->toIso8601String() }}">{{ $update->publish_at?->timezone('America/New_York')->format('M j, Y · g:i A') }}</time>
                @if($update->author?->name)
                    <span aria-hidden="true"> · </span>{{ $update->author->name }}
                @endif
            </p>
            <div class="flex flex-wrap items-center gap-2">
                @if($update->attachment_path)
                    <a href="{{ route('updates.attachment', $update) }}" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-hub-ink-secondary hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">
                        Download attachment
                    </a>
                @endif
                @if($ctaUrl)
                    <a href="{{ $ctaUrl }}" @if($update->hasExternalCta()) target="_blank" rel="noopener noreferrer" @endif class="inline-flex min-h-11 items-center rounded-lg bg-hub-red px-4 py-2 text-sm font-semibold text-white hover:bg-hub-red-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">
                        {{ $update->cta_label ?: 'Open' }}
                    </a>
                @elseif($compact)
                    <a href="{{ route('updates.show', $update) }}" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-hub-blue hover:bg-blue-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">
                        Read update
                    </a>
                @endif
            </div>
        </div>
    </div>
</article>
