<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Verify your city email · MBFD Hub</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main>
    <p class="small">MBFD Hub · Mailbox verification</p>
    @if ($verification)
        <h1>Verify your city email</h1>
        <p>Connect <strong class="detail">{{ $verification->email }}</strong> to the Hub account you are currently signed in to.</p>
        <p>Opening this page has not changed your account. Confirm below to complete verification.</p>
        <form method="POST" action="{{ route('city-email.verify.store', ['token' => $token]) }}">
            @csrf
            <label class="checkbox"><input type="checkbox" name="confirm_verification" value="1" required><span>This is my city mailbox, and I want to connect it to my Hub account.</span></label>
            <button type="submit">Verify and connect my city email</button>
        </form>
    @else
        <h1>This link cannot be used</h1>
        <p>The link is invalid, expired, already used, or belongs to a different account. Sign in with the Employee ID that requested this link, or request a new one.</p>
    @endif
    <p><a href="{{ route('city-email.show') }}">Return to city email settings</a></p>
</main>
</body>
</html>
