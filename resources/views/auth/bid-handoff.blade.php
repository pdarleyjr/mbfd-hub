<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="refresh" content="0;url={{ $destination }}">
    <title>Opening MBFD Bid</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main style="max-width: 26rem">
    <div class="identity-strip">Miami Beach Fire Department</div>
    <h1>Opening MBFD Bid</h1>
    <p>If you are not redirected automatically, use the link below.</p>
    <a href="{{ $destination }}" rel="noreferrer">Continue to Bid</a>
</main>
</body>
</html>
