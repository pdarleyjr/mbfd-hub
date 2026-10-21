<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="referrer" content="no-referrer">
    <title>Reset your password - MBFD Hub</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main class="identity-narrow">
    <div class="identity-strip"><img src="/images/mbfd_logo-256.png" alt="" width="32" height="32">Miami Beach Fire Department</div>
    <h1>Get your sign-in link</h1>
    <p>Use your Employee ID to request a private link for first-time setup or a forgotten password. It goes only to the email on file.</p>
    @if (session('status'))
        <h2>Request received</h2>
        <div class="notice" role="status">{{ session('status') }}</div>
    @endif
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" autocapitalize="none" spellcheck="false" value="{{ old('employee_id') }}" required autofocus @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')<div id="employee_id-error" class="error" role="alert">{{ $message }}</div>@enderror
        <button type="submit">Email my link</button>
    </form>
    <p class="small">Check Inbox and Junk in your City email. If the link expires, request another here. If no email arrives after retrying, contact MBFD Hub support to check the email on file. Do not create another account.</p>
    <a class="identity-secondary" href="{{ route('login') }}">Already have a password? Sign in</a>
</main>
</body>
</html>
