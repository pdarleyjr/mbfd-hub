@php
    $zone = \App\Support\HubTimezone::NAME;
    $tone = $item->needsResponse ? 'red' : ($item->isOpen ? 'amber' : 'green');
    $update = $item->latestUpdate;
@endphp
<li class="act-row" wire:key="act-{{ $item->key }}" data-activity-item="{{ $item->key }}" @if($item->needsResponse) data-needs-response @endif @if($item->unread) data-unread @endif>
    <a class="act-row__link" href="{{ route('employee.activity.open', ['key' => $item->key]) }}">
        <span class="ep-list-dot ep-dot-{{ $tone }}" aria-hidden="true"></span>
        <span class="act-row__body">
            <span class="act-row__top">
                <span class="act-row__category">{{ $item->category }}</span>
                <span class="act-row__subject">{{ $item->subject }}</span>
                @if($item->ownerLabel)
                    <span class="act-row__owner">With {{ $item->ownerLabel }}</span>
                @endif
            </span>
            <span class="act-row__desc">{{ $item->description }}</span>
            @if($update)
                <span class="act-row__update">
                    <span class="act-row__author">{{ $update->authorLabel }}:</span>
                    {{ $update->excerpt }}
                    <time datetime="{{ $update->at->toIso8601String() }}" title="{{ $update->at->setTimezone($zone)->format('M j, Y g:i A T') }}">{{ $update->at->setTimezone($zone)->diffForHumans() }}</time>
                </span>
            @endif
        </span>
        <span class="act-row__flags">
            @if($item->needsResponse)
                <span class="act-flag act-flag--reply">Needs your reply</span>
            @endif
            @if($item->unread)
                <span class="act-unread" aria-hidden="true"></span><span class="sr-only">New update</span>
            @endif
            <span class="ep-status-badge">{{ $item->statusLabel }}</span>
        </span>
    </a>
    @if($item->children !== [])
        <details class="act-row__children">
            <summary>{{ count($item->children) }} {{ \Illuminate\Support\Str::plural('issue', count($item->children)) }} from this checkout</summary>
            <ul class="act-children">
                @foreach($item->children as $child)
                    @include('filament.employee.partials.activity-row', ['item' => $child])
                @endforeach
            </ul>
        </details>
    @endif
</li>
