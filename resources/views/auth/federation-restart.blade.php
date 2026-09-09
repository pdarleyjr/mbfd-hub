<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Restart application sign-in</title></head>
<body style="font-family:Arial,sans-serif;background:#f1f5f9;color:#172033;padding:2rem">
<main style="max-width:30rem;margin:auto;background:white;padding:2rem;border-radius:.75rem">
    <h1>Restart application sign-in</h1>
    @if (isset($retryAfter))
        <p>Too many sign-in attempts from this connection. Wait {{ $retryAfter }} seconds before trying again.</p>
    @else
    <p>This sign-in attempt has expired, was already used, or cannot be verified in this browser.</p>
    @endif
    <p>Return to the application and choose Sign in with MBFD Hub again. Close unused sign-in tabs; if you have opened many attempts, wait five minutes before retrying.</p>
    <p>Your password and unsaved form contents were not retained or submitted again.</p>
    <a href="/login">Open MBFD Hub sign-in</a>
</main>
</body>
</html>
