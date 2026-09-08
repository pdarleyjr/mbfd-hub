<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>City email & verification · MBFD Hub</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main>
    <p class="small">MBFD Hub · Account settings</p>
    <h1>{{ $requiresReview ? 'Confirm your city email' : 'City email & verification' }}</h1>
    <p>{{ $user->name }}, your Employee ID and Hub password remain your sign-in credentials.</p>
    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="error" role="alert">
            <p class="error">Please check the following:</p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($connectedEmail && ! $verification?->verified_at)
        <span class="status">Email already connected</span>
        <p class="detail"><strong>{{ $connectedEmail }}</strong></p>
        <p>Your existing email connection is preserved. You can continue using the Hub without the one-time email confirmation. Optional city-email verification or changes are available below.</p>
    @endif
    @if ($verification)
        <span class="status {{ $verification->verified_at ? 'verified' : '' }}">{{ $verification->verified_at ? 'Verified' : 'Verification pending' }}</span>
        <p class="detail"><strong>{{ $verification->email }}</strong></p>
        @if ($verification->verified_at)
            <p>This mailbox is verified and connected to your Hub account.</p>
        @elseif ($verification->delivery_status === 'failed')
            <p class="notice">The last verification email could not be sent. Your proposed address is saved, but is not verified. You can continue using the Hub and retry below.</p>
        @else
            <p>Open the verification email in this city mailbox and confirm the link while signed in to this Hub account.
                @if ($connectedEmail === $verification->email)
                    Your existing address stays connected while optional mailbox verification is pending.
                @else
                    Until then, the proposed address is not used for password recovery or account email.
                @endif
            </p>
        @endif
    @elseif (! $connectedEmail)
        <p>We suggest <strong>firstnamelastname@miamibeachfl.gov</strong> from the personnel roster. Names alone cannot prove a mailbox exists. Check the spelling and use your actual city-assigned address if it differs.</p>
    @endif

    @if ($requiresReview)
        @include('auth.partials.city-email-form', ['emailValue' => $candidate])
    @else
        <form method="POST" action="{{ route('city-email.continue') }}">
            @csrf
            <button type="submit">Continue to the Hub</button>
        </form>
        @if ($verification && ! $verification->verified_at)
            <details @if ($errors->has('current_password') || $errors->has('ownership_confirmed')) open @endif>
                <summary>Send a new verification link</summary>
                <p>For your security, confirm your Hub password. A new link replaces earlier links.</p>
                <form method="POST" action="{{ route('city-email.resend') }}">
                    @csrf
                    <input type="hidden" name="username" autocomplete="username" value="{{ $user->employee_id }}">
                    <label for="resend-password">Current Hub password</label>
                    <input id="resend-password" name="current_password" type="password" autocomplete="current-password" maxlength="4096" required>
                    <label class="checkbox"><input type="checkbox" name="ownership_confirmed" value="1" required><span>I confirm this is my city-assigned email address and I can access this mailbox.</span></label>
                    <button class="secondary" type="submit">Send a new link</button>
                </form>
            </details>
        @endif
        <details @if ($errors->has('email')) open @endif>
            <summary>{{ $connectedEmail && ! $verification ? 'Manage city email (optional)' : 'Correct or change my city email' }}</summary>
            <p>Your existing connected address stays connected until you verify a replacement.</p>
            @include('auth.partials.city-email-form', ['emailValue' => $verification?->email ?? $candidate])
        </details>
    @endif
    <p class="small">If you cannot access your city mailbox or your name is incorrect, contact your Hub administrator or City IT. Do not use someone else's mailbox.</p>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="secondary" type="submit">Sign out</button>
    </form>
</main>
</body>
</html>
