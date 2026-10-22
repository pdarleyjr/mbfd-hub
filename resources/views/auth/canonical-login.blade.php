<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>MBFD Sign In</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main class="identity-narrow">
    <div class="identity-strip"><img src="/images/mbfd_logo-256.png" alt="" width="32" height="32">Miami Beach Fire Department</div>
    <h1>MBFD Sign In</h1>
    <p>Sign in with your Employee ID or email and password.</p>
    @if (request()->query('session_expired') === '1')
        <p role="status">Your session has ended. Please sign in again. Unsaved changes were not submitted. Review the record after signing in.</p>
    @endif
    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif
    @if ($applicationLabel ?? null)
        <p>Sign in to continue to {{ $applicationLabel }}.</p>
    @endif
    <form method="POST" action="{{ $loginAction ?? route('login.store') }}">
        @csrf
        @if ($loginAttempt ?? null)
            <input type="hidden" name="login_attempt" value="{{ $loginAttempt }}">
        @endif
        <label for="employee_id">Employee ID or email</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="254" autocomplete="username" autocapitalize="none" spellcheck="false" value="{{ old('employee_id') }}" required autofocus @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')
            <div id="employee_id-error" class="error" role="alert">{{ $message }}</div>
        @enderror
        @if ($errors->has('employee_id'))
            <p class="small">If you already have a password, use it here. Need to choose or reset a password? Request a private email link below. If no email arrives, contact MBFD Hub support to check your account and email on file.</p>
        @endif

        <label for="password">Password</label>
        <input id="password" name="password" type="password" maxlength="4096" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
        @error('password')
            <div id="password-error" class="error" role="alert">{{ $message }}</div>
        @enderror

        <button type="submit">Sign in</button>
    </form>
    <a class="help" href="{{ route('password.request') }}">Forgot your password?</a>
    @if ($activationUrl ?? null)
        <a class="identity-secondary" href="{{ $activationUrl }}">First time using MBFD Hub? Activate Your Account</a>
    @else
        <p class="help">First time signing in? Open the personal setup link sent to your City email. If you did not receive one, contact MBFD Hub support.</p>
    @endif
</main>
</body>
</html>
