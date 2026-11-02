{{-- The day TeleStaff is sending and the next six (7-day outlook on). Drawn above and below the roster. --}}
<nav class="roster-days" aria-label="{{ $label }}" data-roster-days>
    <ol class="roster-days__list">
        @foreach($days as $day)
            <li>
                @if($day['href'] !== null)
                    <a class="roster-days__day" href="{{ $day['href'] }}" @if($day['isCurrent']) aria-current="date" @endif data-roster-day="{{ $day['date'] }}">{{ $day['label'] }}<span class="sr-only">: {{ $day['spokenDate'] }}</span></a>
                @else
                    <span class="roster-days__day roster-days__day--missing" data-roster-day="{{ $day['date'] }}">{{ $day['label'] }}<span class="sr-only">: {{ $day['spokenDate'] }}</span></span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
