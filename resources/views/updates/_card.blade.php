@php
    $compact = $compact ?? false;
    $headingTag = $headingTag ?? 'h3';
    $ctaUrl = $update->safeCtaUrl();
    // Severity is carried by the priority tag, never by a coloured edge (.impeccable.md).
    $priorityTag = match ($update->priority) {
        \App\Enums\DepartmentUpdatePriority::Critical => 'hub-tag--danger',
        \App\Enums\DepartmentUpdatePriority::Important => 'hub-tag--warning',
        default => 'hub-tag--info',
    };
@endphp

<article data-department-update data-priority="{{ $update->priority->value }}" class="hub-panel overflow-hidden">
    @if($update->image_path && ! $compact)
        <img src="{{ route('updates.image', $update) }}" alt="" class="h-52 w-full object-cover">
    @endif
    <div class="p-4 sm:p-5">
        <div class="flex flex-wrap items-center gap-2">
            <span class="hub-tag {{ $priorityTag }}">{{ $update->priority->label() }}</span>
            <span class="hub-tag">{{ $update->category->label() }}</span>
            @if($update->is_pinned)
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-hub-muted">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 4 5 5-3 1-4 4-1 5-2-2-2-2 5-1 4-4 1-3-5-5Z"></path></svg>
                    Pinned
                </span>
            @endif
        </div>
        <{{ $headingTag }} class="mt-3 font-heading text-base font-bold leading-snug text-hub-ink sm:text-lg">
            <a href="{{ route('updates.show', $update) }}" class="rounded-sm hover:text-hub-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">
                {{ $update->title }}
            </a>
        </{{ $headingTag }}>
        @if($compact)
            <div class="mt-2 flex items-start gap-3">
                @if($update->image_path)
                    <img src="{{ route('updates.image', $update) }}" alt="" loading="lazy" class="hidden h-16 w-16 flex-none rounded-lg object-cover sm:block" data-update-thumb>
                @endif
                <p class="min-w-0 text-[15px] leading-relaxed text-hub-ink-secondary">{{ $update->excerpt(165) }}</p>
            </div>
        @else
            <div data-update-body class="prose prose-base mt-4 max-w-[70ch] text-base leading-[1.6] text-hub-ink-secondary prose-p:my-3 prose-headings:font-heading prose-a:text-hub-blue prose-a:underline prose-li:my-1 [overflow-wrap:anywhere]">
                {!! \App\Support\Security\SafeHtml::report($update->body) !!}
            </div>
        @endif
        @if($update->expires_at || $update->attachment_path || $ctaUrl)
            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-semibold" data-update-chips>
                @if($update->expires_at)
                    <span class="hub-tag hub-tag--warning">Stays up until {{ $update->expires_at->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y') }}</span>
                @endif
                @if($update->attachment_path)
                    <span class="hub-tag">File attached</span>
                @endif
                @if($ctaUrl)
                    <span class="hub-tag">Has a link</span>
                @endif
            </div>
        @endif
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-hub-border-soft pt-3">
            <p class="text-xs text-hub-muted">
                <time datetime="{{ $update->publish_at?->toIso8601String() }}">{{ $update->publish_at?->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y · g:i A') }}</time>
                @if($update->attribution())
                    <span aria-hidden="true"> · </span>Posted by {{ $update->attribution() }}
                @endif
            </p>
            <div class="flex flex-wrap items-center gap-2">
                @if($update->attachment_path)
                    <a href="{{ route('updates.attachment', $update) }}" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-hub-ink-secondary hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">
                        Download attachment
                    </a>
                @endif
                @if($ctaUrl)
                    <a href="{{ $ctaUrl }}" @if($update->hasExternalCta()) target="_blank" rel="noopener noreferrer" @endif class="inline-flex min-h-11 items-center rounded-lg bg-hub-blue px-4 py-2 text-sm font-semibold text-white hover:bg-hub-blue-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">
                        {{ $update->cta_label ?: 'Open' }}
                    </a>
                @elseif($compact)
                    <a href="{{ route('updates.show', $update) }}" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-hub-blue hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">
                        Read update
                    </a>
                @endif
            </div>
        </div>
    </div>
</article>
