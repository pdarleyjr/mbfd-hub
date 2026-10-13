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

        <fieldset class="field-group" @if ($errors->hasAny(['dob_month', 'dob_day', 'dob_year'])) aria-describedby="dob-error" @endif>
            <legend>Date of birth</legend>
            <div class="field-row">
                <div>
                    <label class="field-sub" for="dob_month">Month</label>
                    <input id="dob_month" name="dob_month" type="text" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" placeholder="MM" autocomplete="bday-month" value="{{ old('dob_month') }}" required>
                </div>
                <div>
                    <label class="field-sub" for="dob_day">Day</label>
                    <input id="dob_day" name="dob_day" type="text" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" placeholder="DD" autocomplete="bday-day" value="{{ old('dob_day') }}" required>
                </div>
                <div class="field-wide">
                    <label class="field-sub" for="dob_year">Year</label>
                    <input id="dob_year" name="dob_year" type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="YYYY" autocomplete="bday-year" value="{{ old('dob_year') }}" required>
                </div>
            </div>
        </fieldset>
        @if ($errors->hasAny(['dob_month', 'dob_day', 'dob_year']))
            <div id="dob-error" class="error" role="alert">Enter your date of birth as month, day, and four-digit year.</div>
        @endif

        <label for="phone">Personal phone number</label>
        <input id="phone" name="phone" type="tel" inputmode="tel" maxlength="32" autocomplete="tel-national" aria-describedby="phone-hint" value="{{ old('phone') }}" required @error('phone') aria-invalid="true" @enderror>
        <div id="phone-hint" class="hint">The number the department has on file for you.</div>
        @error('phone')<div class="error" role="alert">{{ $message }}</div>@enderror

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
