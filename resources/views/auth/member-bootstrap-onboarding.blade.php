<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete your MBFD Hub account</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main>
    <div class="identity-strip">Miami Beach Fire Department</div>
    <h1>Complete your MBFD Hub account</h1>
    <p>Confirm this is your account, then create the private password you will use from now on.</p>
    <p class="identity"><strong>{{ $user->employeeProfile?->name ?? $user->name }}</strong><br>Employee ID {{ $user->employee_id }}</p>

    <form method="POST" action="{{ route('member-onboarding.store') }}">
        @csrf
        <label for="password">New Password</label>
        <input id="password" name="password" type="password" maxlength="72" autocomplete="new-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
        @error('password')<div id="password-error" class="error" role="alert">{{ $message }}</div>@enderror

        <label for="password_confirmation">Confirm New Password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" maxlength="72" autocomplete="new-password" required>

        <button type="submit">Complete account setup</button>
    </form>
    <form method="POST" action="{{ route('member-onboarding.cancel') }}">
        @csrf
        <button class="cancel" type="submit">Cancel and sign out</button>
    </form>
</main>
</body>
</html>
