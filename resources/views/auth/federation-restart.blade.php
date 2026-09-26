<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Restart application sign-in</title>@include('auth.partials.city-email-style')</head>
<body>
<main style="max-width: 30rem">
    <div class="identity-strip">Miami Beach Fire Department</div>
    <h1>Restart application sign-in</h1>
    @if (isset($retryAfter))
        <p>Too many sign-in attempts from this connection. Wait {{ $retryAfter }} seconds before trying again.</p>
    @else
    <p>This sign-in attempt has expired, was already used, or cannot be verified in this browser.</p>
    @endif
    <p>Return to the application and choose MBFD Sign In again. Close unused sign-in tabs; if you have opened many attempts, wait five minutes before retrying.</p>
    <p>Your password and unsaved form contents were not retained or submitted again.</p>
    @if ($restartUrl ?? null)
        <a href="{{ $restartUrl }}">Return to MBFD Bid sign-in</a>
    @else
        <p>Use your browser Back button to return to the application.</p>
    @endif
</main>
</body>
</html>
