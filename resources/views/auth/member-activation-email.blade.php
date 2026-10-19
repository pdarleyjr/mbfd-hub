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
        <div class="notice" role="status">{{ session('status') }}</div>
        <p>Open the link in that email to choose your password, then sign in with your Employee ID. No email after a few minutes? Contact MBFD Hub support.</p>
    @else
        <p>Enter your Employee ID. We will email a private link to the address on file so you can choose your password. It works for first-time setup and for a forgotten password.</p>
    @endif

    <form method="POST" action="{{ route('member-activation.email.request', array_filter(['login_attempt' => $loginAttempt])) }}">
        @csrf
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" autocapitalize="characters" spellcheck="false" value="{{ old('employee_id') }}" required @unless (session('status')) autofocus @endunless @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')<div id="employee_id-error" class="error" role="alert">{{ $message }}</div>@enderror
        <button type="submit" @if (session('status')) class="secondary" @endif>{{ session('status') ? 'Send another link' : 'Email my link' }}</button>
    </form>

    @if ($claimEnabled)
        <a class="help" href="{{ route('member-activation.show', array_filter(['login_attempt' => $loginAttempt])) }}">Activate with your bid shift and unit instead</a>
    @endif
    <a class="help" href="{{ $loginAttempt ? '/login?login_attempt='.$loginAttempt : route('login') }}">Already activated? Sign in</a>
</main>
</body>
</html>
