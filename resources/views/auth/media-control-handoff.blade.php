<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="refresh" content="0;url={{ $destination }}">
    <title>Opening Media Control</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main style="max-width: 26rem">
    <div class="identity-strip">Miami Beach Fire Department</div>
    <h1>Opening Media Control</h1>
    <p>If you are not redirected automatically, use the link below.</p>
    <a href="{{ $destination }}" rel="noreferrer">Continue to Media Control</a>
</main>
</body>
</html>
