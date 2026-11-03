@php
    // The chip's dot and border take the board color for this status (BoardTone, contract 11); the text is always the label.
    $boardTone = \App\Support\UnitStatus\BoardTone::for($availability);
    $toneClass = match ($availability['tone'] ?? 'neutral') {
        'success' => 'hub-tag--success',
        'warning' => 'hub-tag--warning',
        default => '',
    };
    $text = $availability['label'] ?? 'Not confirmed';
@endphp
<span class="hub-tag us-chip us-tone--{{ $boardTone }} {{ $toneClass }}">
    <span class="us-dot" aria-hidden="true"></span>
    <span>{{ $text }}</span>
</span>
