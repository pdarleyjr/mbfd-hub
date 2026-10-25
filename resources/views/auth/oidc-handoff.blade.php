<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="refresh" content="0;url={{ $destination }}">
    <title>Opening your MBFD application</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main style="max-width: 26rem">
    <div class="identity-strip">Miami Beach Fire Department</div>
    <h1>Opening your MBFD application</h1>
    <p>If you are not redirected automatically, use the link below.</p>
    <a href="{{ $destination }}" rel="noreferrer">Continue</a>
</main>
</body>
</html>