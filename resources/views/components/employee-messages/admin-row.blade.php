@props([
    'row',
    'initials',
    'details' => '',
    'when' => '',
])
{{--
    The inside of one Support Services conversation row, shared by the inbox list and the dashboard
    card: who sent it, what it is about (subject / reason), when, and its status. The wrapper
    (button or link) belongs to the caller.
--}}
@php
    /** @var \App\Models\EmployeeMessaging\EmployeeLogisticsThread $row */
    $zone = \App\Support\HubTimezone::NAME;
    $status = $row->supportStatus();
    $local = $row->last_message_at?->copy()->timezone($zone);
@endphp
<span class="emsg-avatar" aria-hidden="true">{{ $initials }}</span>
<span class="emsg-row__main">
    <span class="emsg-row__title">{{ $row->member_name }}</span>
    @if ($details !== '')
        <span class="emsg-row__sub">{{ $details }}</span>
    @endif
    <span class="emsg-row__subject">{{ $row->title() }}</span>
    @if (filled($row->last_message_preview))
        <span class="emsg-row__preview">{{ $row->last_message_preview }}</span>
    @endif
    @if ($row->isBunkerGear())
        <span class="emsg-bunker" data-emsg-bunker data-emsg-bunker-source="{{ $row->isBunkerGearByReason() ? 'reason' : 'keyword' }}">
            <svg class="emsg-bunker__icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
            <span class="emsg-bunker__label">{{ $row->isBunkerGearByReason() ? $row->reasonTag() : 'Bunker gear cleaning' }}</span>
            @unless ($row->isBunkerGearByReason())
                <span class="emsg-bunker__note">(detected)</span>
            @endunless
        </span>
    @elseif ($row->reasonTag() !== null)
        <span class="emsg-tag emsg-tag--reason mt-1">{{ $row->reasonTag() }}</span>
    @endif
</span>
<span class="emsg-row__aside">
    @if ($local)
        <time datetime="{{ $local->toIso8601String() }}" title="{{ $local->format('l, F j, Y g:i A') }}">{{ $when }}</time>
    @endif
    <span @class(['emsg-status', 'emsg-status--attention' => $status !== 'Replied']) data-emsg-status>{{ $status }}</span>
    @if ($row->isUnreadForLogistics())
        <span class="emsg-unread-dot">Unread</span>
    @endif
</span>
