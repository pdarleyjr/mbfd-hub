{{-- Maintenance mode pre-renders this view with no exception. A real 503 always has one, so an outage never claims to be an update. --}}
@isset($exception)
    @include('errors.hub', ['code' => 503, 'title' => 'Hub temporarily unavailable', 'message' => 'The Hub is temporarily unavailable. Please try again shortly.'])
@else
    @include('errors.hub', ['code' => 503, 'eyebrow' => 'SCHEDULED UPDATE', 'refreshSeconds' => 30, 'title' => 'MBFD Hub is being updated', 'message' => 'The Hub is down for an update for approximately 2 to 8 minutes. This page reloads on its own and you do not need to do anything.'])
@endisset
