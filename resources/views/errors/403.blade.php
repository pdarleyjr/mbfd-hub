{{-- Show the refusal reason only when a route deliberately wrote a human sentence for it. Framework and policy defaults stay generic so internals never leak. --}}
@php
    $reason = trim((string) (isset($exception) ? $exception->getMessage() : ''));
    $isHumanReason = $reason !== ''
        && $reason !== 'This action is unauthorized.'
        && preg_match('/^[A-Z][A-Za-z0-9 ,\'’\-\/&()]{19,239}\.$/u', $reason) === 1
        && substr_count($reason, ' ') >= 3;
@endphp
@include('errors.hub', ['code' => 403, 'title' => 'You do not have access to this page', 'message' => $isHumanReason ? $reason.' Nothing was changed.' : 'Your account is not set up to open this page, so nothing was changed. If you think you should have access, ask your administrator. Return to Hub home to continue.'])
