<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="referrer" content="no-referrer">
    <title>Get your sign-in link - MBFD Hub</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main class="identity-narrow">
    <div class="identity-strip"><img src="/images/mbfd_logo-256.png" alt="" width="32" height="32">Miami Beach Fire Department</div>
    <h1>Get your sign-in link</h1>
    @if (session('status'))
        <h2>Request received</h2>
        <div class="notice" role="status">{{ session('status') }}</div>
        <p>Open the private link to choose your password, then sign in with your Employee ID.</p>
    @else
        <p>One Employee ID works for first-time setup and forgotten passwords. Request a private link to choose your password using the email on file.</p>
    @endif
    <p class="small">No email after a few minutes? Check Inbox and Junk, then contact MBFD Hub support to check your account and email on file. Do not create another account.</p>

    <form method="POST" action="{{ route('member-activation.email.request', array_filter(['login_attempt' => $loginAttempt])) }}">
        @csrf
        <label for="employee_id">Employee ID</label>
        @if ($loginAttempt)
            <input type="hidden" name="login_attempt" value="{{ $loginAttempt }}">
        @endif
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" autocapitalize="none" spellcheck="false" value="{{ old('employee_id') }}" required @unless (session('status')) autofocus @endunless @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')<div id="employee_id-error" class="error" role="alert">{{ $message }}</div>@enderror
        <button type="submit" @if (session('status')) class="secondary" @endif>{{ session('status') ? 'Send another link' : 'Email my link' }}</button>
    </form>

    <a class="identity-secondary" href="{{ $loginAttempt ? '/login?login_attempt='.$loginAttempt : route('login') }}">Already have a password? Sign in</a>
    @if ($claimEnabled)
        <details>
            <summary>Another way to complete first-time setup</summary>
            <p class="small">If your account is waiting for first-time setup, you can verify your City email and bid assignment or non-bid position. Existing passwords cannot be changed this way.</p>
            <a class="help" href="{{ route('member-activation.show', array_filter(['method' => 'details', 'login_attempt' => $loginAttempt])) }}">Use employee details instead</a>
        </details>
    @endif
</main>
</body>
</html>
