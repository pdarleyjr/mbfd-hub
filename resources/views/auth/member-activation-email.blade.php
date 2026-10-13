<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="referrer" content="no-referrer">
    <title>Activate by email - MBFD Hub</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main class="identity-narrow">
    <div class="identity-strip"><img src="/images/mbfd_logo-256.png" alt="" width="32" height="32">Miami Beach Fire Department</div>
    <h1>Activate by email</h1>
    @if (session('status'))
        <div class="notice" role="status">{{ session('status') }}</div>
        <p>Open the link in that email to choose your password. No email after a few minutes? Contact MBFD Hub support.</p>
    @else
        <p>Enter your Employee ID and we will send a private setup link to your City email address.</p>
    @endif

    <form method="POST" action="{{ route('member-activation.email.request', array_filter(['login_attempt' => $loginAttempt])) }}">
        @csrf
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" autocapitalize="characters" spellcheck="false" value="{{ old('employee_id') }}" required @unless (session('status')) autofocus @endunless @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')<div id="employee_id-error" class="error" role="alert">{{ $message }}</div>@enderror
        <button type="submit" @if (session('status')) class="secondary" @endif>{{ session('status') ? 'Send another link' : 'Email my setup link' }}</button>
    </form>

    @if ($claimEnabled)
        <a class="help" href="{{ route('member-activation.show', array_filter(['login_attempt' => $loginAttempt])) }}">Activate with date of birth and phone instead</a>
    @endif
    <a class="help" href="{{ $loginAttempt ? '/login?login_attempt='.$loginAttempt : route('login') }}">Already activated? Sign in</a>
</main>
</body>
</html>
