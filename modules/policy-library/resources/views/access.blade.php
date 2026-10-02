<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Library access · MBFD</title>
    <link rel="icon" type="image/png" href="{{ asset('vendor/policy-library/images/mbfd-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('vendor/policy-library/viewer.css') }}">
</head>
<body class="access-body">
    <main class="access-card">
        <img src="{{ asset('vendor/policy-library/images/mbfd-logo.png') }}" alt="Miami Beach Fire Rescue" width="88" height="88">
        <p class="eyebrow">MIAMI BEACH FIRE RESCUE</p>
        <h1>Policy &amp; Protocol Library</h1>
        <p class="access-intro">Enter the department access PIN to open your manuals.</p>
        <form method="post" action="{{ route('policy-library.access.store') }}">
            @csrf
            <label for="pin">Library access PIN</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required autofocus maxlength="12" aria-describedby="access-error">
            <div id="access-error" class="form-error" role="alert">@error('pin') {{ $message }} @enderror</div>
            <button type="submit" class="primary-button">Open library <span aria-hidden="true">→</span></button>
        </form>
        <p class="access-note">Secure member access · Department documents</p>
    </main>
</body>
</html>
