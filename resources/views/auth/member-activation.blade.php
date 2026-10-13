<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="referrer" content="no-referrer">
    <title>Activate your MBFD Hub account</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main class="identity-narrow">
    <div class="identity-strip"><img src="/images/mbfd_logo-256.png" alt="" width="32" height="32">Miami Beach Fire Department</div>
    <h1>Activate your account</h1>
    <p>First time using MBFD Hub? Confirm who you are and choose a password. It takes about a minute.</p>

    @error('activation')
        <div class="notice error" role="alert">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('member-activation.store', array_filter(['login_attempt' => $loginAttempt])) }}">
        @csrf
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" autocapitalize="characters" spellcheck="false" value="{{ old('employee_id') }}" required autofocus @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')<div id="employee_id-error" class="error" role="alert">{{ $message }}</div>@enderror

        <label for="email">City email</label>
        <input id="email" name="email" type="email" inputmode="email" maxlength="254" autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="name@miamibeachfl.gov" value="{{ old('email') }}" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email')<div id="email-error" class="error" role="alert">{{ $message }}</div>@enderror

        <label for="shift">Shift you bid</label>
        <select id="shift" name="shift" required aria-describedby="bid-hint" @error('shift') aria-invalid="true" @enderror>
            <option value="" @selected(old('shift') === null)>Select your shift</option>
            @foreach ($bidChoices['shifts'] as $shift)
                <option value="{{ $shift }}" @selected(old('shift') === $shift)>{{ $shift }}</option>
            @endforeach
        </select>
        @error('shift')<div class="error" role="alert">{{ $message }}</div>@enderror

        <label for="unit">Unit you bid</label>
        <select id="unit" name="unit" required aria-describedby="bid-hint" @error('unit') aria-invalid="true" @enderror>
            <option value="" @selected(old('unit') === null)>Select your unit</option>
            @foreach ($bidChoices['units'] as $unit)
                <option value="{{ $unit }}" @selected(old('unit') === $unit)>{{ $unit }}</option>
            @endforeach
        </select>
        <div id="bid-hint" class="hint">Use your most recent bid results, even if you have not moved yet.</div>
        @error('unit')<div class="error" role="alert">{{ $message }}</div>@enderror

        <label for="password">Create a password</label>
        <input id="password" name="password" type="password" maxlength="72" autocomplete="new-password" aria-describedby="password-hint" required @error('password') aria-invalid="true" @enderror>
        <div id="password-hint" class="hint">At least 8 characters.</div>
        @error('password')<div class="error" role="alert">{{ $message }}</div>@enderror

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" maxlength="72" autocomplete="new-password" required>

        <button type="submit">Activate account</button>
    </form>

    @if ($emailEnabled)
        <a class="help" href="{{ route('member-activation.email', array_filter(['login_attempt' => $loginAttempt])) }}">Use email activation instead</a>
    @else
        <p class="help small">Having trouble? Contact MBFD Hub support for an email setup link.</p>
    @endif
    <a class="help" href="{{ $loginAttempt ? '/login?login_attempt='.$loginAttempt : route('login') }}">Already activated? Sign in</a>
</main>
</body>
</html>
