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
    @if ($row->reasonTag() !== null)
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
