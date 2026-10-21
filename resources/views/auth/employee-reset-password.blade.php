<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="referrer" content="no-referrer">
    <title>Choose MBFD Hub Password</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main class="identity-narrow">
    <div class="identity-strip"><img src="/images/mbfd_logo-256.png" alt="" width="32" height="32">Miami Beach Fire Department</div>
    <h1>Choose your password</h1>
    <p>Save a private password, then sign in with your Employee ID. This updates your existing account.</p>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" autocapitalize="none" spellcheck="false" value="{{ old('employee_id', $employeeId) }}" required @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')<div id="employee_id-error" class="error" role="alert">{{ $message }}</div>@enderror
        @error('token')<div class="error" role="alert">{{ $message }}</div>@enderror

        <label for="password">New password</label>
        <input id="password" name="password" type="password" maxlength="72" autocomplete="new-password" aria-describedby="password-hint{{ $errors->has('password') ? ' password-error' : '' }}" required autofocus @error('password') aria-invalid="true" @enderror>
        <div id="password-hint" class="hint">At least 8 characters. Use a password you do not share.</div>
        @error('password')<div id="password-error" class="error" role="alert">{{ $message }}</div>@enderror

        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" maxlength="72" autocomplete="new-password" required>
        <button type="submit">Save password</button>
    </form>
    <a class="help" href="{{ route('password.request') }}">Link expired? Request another private link</a>
    <p class="small">The link goes to your email on file. No email after a few minutes? Contact MBFD Hub support.</p>
    <a class="identity-secondary" href="{{ route('login') }}">Sign in</a>
</main>
</body>
</html>
