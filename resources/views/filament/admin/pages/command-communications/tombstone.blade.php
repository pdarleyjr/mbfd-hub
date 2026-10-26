<p class="mt-2 text-sm italic text-gray-600" role="status">
    Message removed by {{ $item->deletedBy?->name ?? 'an administrator' }}
    @if ($item->deleted_at)
        <time datetime="{{ $item->deleted_at->toIso8601String() }}" title="{{ $item->deleted_at->copy()->timezone(config('hub.timezone'))->format('M j, Y g:i A') }}">{{ $item->deleted_at->diffForHumans() }}</time>
    @endif
    @if ($item->deletion_reason)
        <span class="not-italic text-gray-900"> &middot; Reason: {{ $item->deletion_reason }}</span>
    @endif
</p>
