@php
    // The PulsePoint color is an indicator only; it is applied through one custom property and only when it is a plain rgb().
    $indicator = $availability['indicatorColor'] ?? null;
    $indicator = is_string($indicator) && preg_match('/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/', $indicator) === 1 ? $indicator : null;
    $tone = $availability['tone'] ?? 'neutral';
    $toneClass = match ($tone) {
        'success' => 'hub-tag--success',
        'warning' => 'hub-tag--warning',
        default => '',
    };
    $text = ($availability['label'] ?? 'Not confirmed') . (($availability['confirmed'] ?? true) ? '' : ' (unconfirmed)');
@endphp
<span class="hub-tag us-chip us-chip--{{ $tone }} {{ $toneClass }}" @if($tone === 'source' && $indicator) style="--us-indicator: {{ $indicator }}" @endif>
    @if($tone === 'source')<span class="us-dot" aria-hidden="true"></span>@endif
    <span>{{ $text }}</span>
</span>
