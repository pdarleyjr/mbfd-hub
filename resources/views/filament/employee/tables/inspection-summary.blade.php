@php
    /** @var array{apparatus: string, meta: ?string, submitted: ?string, status: array{label: string, color: string, note: ?string, tooltip: string}, findings: array{label: string, color: string, tooltip: string, follow_up: string}} $row */
    $row = $getState();
@endphp
{{-- Phone / small-tablet card for one inspection. Desktop columns replace it from md up. --}}
<div class="hub-insp-row">
    <div class="hub-insp-row__head">
        <span class="hub-insp-row__title">{{ $row['apparatus'] }}</span>
        <x-filament::badge :color="$row['status']['color']" :tooltip="$row['status']['tooltip']">{{ $row['status']['label'] }}</x-filament::badge>
    </div>
    @if ($row['meta'])
        <p class="hub-insp-row__meta">{{ $row['meta'] }}</p>
    @endif
    @if ($row['submitted'])
        <p class="hub-insp-row__meta">{{ $row['submitted'] }} ET</p>
    @endif
    @if ($row['status']['note'])
        <p class="hub-insp-row__meta">{{ $row['status']['note'] }}</p>
    @endif
    <p class="hub-insp-row__findings">
        <x-filament::badge :color="$row['findings']['color']" :tooltip="$row['findings']['tooltip']">{{ $row['findings']['label'] }}</x-filament::badge>
        <span>{{ $row['findings']['follow_up'] }}</span>
    </p>
</div>
