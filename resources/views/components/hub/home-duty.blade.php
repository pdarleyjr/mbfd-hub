{{--
    The Home greeting block: greeting, the civil date with the shift on duty, and the 300. Every line is text
    the server composed (App\Services\DailyRoster\HomeDutyStatus); resources/js/home-duty.js swaps only that
    text on a page left open, so nothing a member is typing elsewhere is touched. Slots with nothing to say are
    hidden, not removed, so a refresh can fill them. Person-specific lines reach this markup only for a member
    DailyRosterAccess admits, and the page is served uncached to a signed-in session (see HomeController).
--}}
@props(['lines'])
<div
    class="home-duty"
    data-home-duty
    data-url="{{ route('home.duty', absolute: false) }}"
    data-version="{{ $lines->version() }}"
    data-next-change="{{ $lines->nextChangeAt }}"
>
    <h1 id="home-greeting" class="hub-h1 home-duty__greeting" data-home-greeting>{{ $lines->greeting }}</h1>
    <p class="home-duty__line" data-home-date>
        Today is <time datetime="{{ $lines->dateIso }}" data-home-date-label>{{ $lines->dateLabel }}</time><span data-home-shift-wrap @if($lines->shift === null) hidden @endif><span aria-hidden="true"> · </span><span data-home-shift data-state="{{ $lines->shift['state'] ?? '' }}">{{ $lines->shift['text'] ?? '' }}</span></span>
    </p>
    <p class="home-duty__line home-duty__command" data-home-command data-state="{{ $lines->command['state'] ?? '' }}" @if($lines->command === null) hidden @endif>{{ $lines->command['text'] ?? '' }}</p>
    <p class="home-duty__meta" data-home-meta @if($lines->asOf === null && $lines->rosterUrl === null) hidden @endif><span data-home-as-of @if($lines->asOf === null) hidden @endif>{{ $lines->asOf }}</span><span data-home-meta-separator aria-hidden="true" @if($lines->asOf === null || $lines->rosterUrl === null) hidden @endif> · </span><a @if($lines->rosterUrl !== null) href="{{ $lines->rosterUrl }}" @endif class="hub-link" data-home-roster-link @if($lines->rosterUrl === null) hidden @endif>{{ $lines->rosterUrl !== null ? 'Daily Roster' : '' }}</a></p>
    {{-- Announces a change that arrives while the page is open, without moving focus. --}}
    <p class="hub-visually-hidden" role="status" aria-live="polite" data-home-announce></p>
</div>
