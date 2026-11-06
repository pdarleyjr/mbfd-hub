{{-- Maintenance mode pre-renders this view with no exception. A real 503 always has one, so an outage never claims to be an update. --}}
@isset($exception)
    @include('errors.hub', ['code' => 503, 'title' => 'Hub temporarily unavailable', 'message' => 'The Hub is temporarily unavailable. Please try again shortly.'])
@else
    @include('errors.hub', ['code' => 503, 'eyebrow' => 'SCHEDULED UPDATE', 'refreshSeconds' => 30, 'retry' => true, 'title' => 'Updating MBFD Hub', 'message' => "We're applying an update to improve MBFD Hub. Please try again shortly.", 'note' => 'If you have a form or other work in progress, keep your current page open.'])
@endisset
