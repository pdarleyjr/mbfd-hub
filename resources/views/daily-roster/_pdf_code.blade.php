{{-- TeleStaff's exchange mark is not in the PDF font, and would print as an empty box. This arrow is. --}}
@php($colors = \App\Services\DailyRoster\RosterStatusPresenter::TONE_COLORS[$pill->tone] ?? null)
@php($label = str_replace("\u{2927}", "\u{21C4}", $pill->label))
@if($colors === null)
<span class="code code--dashed">{{ $label }}</span>
@else
<span class="code" style="background-color: {{ $colors['bg'] }}; color: {{ $colors['ink'] }};">{{ $label }}</span>
@endif
