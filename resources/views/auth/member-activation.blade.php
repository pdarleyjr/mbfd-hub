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
    <h1>Set up with employee details</h1>
    <p>This option completes first-time setup for eligible accounts. If you already have a password, sign in. To choose or reset a password by email, request a private link.</p>
    <a class="help" href="{{ $loginAttempt ? '/login?login_attempt='.$loginAttempt : route('login') }}">Already have a password? Sign in</a>
    @if ($emailEnabled)
        <a class="identity-secondary" href="{{ route('member-activation.email', array_filter(['login_attempt' => $loginAttempt])) }}">Get a private email link</a>
    @endif

    @error('activation')
        <div class="notice error" role="alert">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('member-activation.store', array_filter(['method' => 'details', 'login_attempt' => $loginAttempt])) }}">
        @csrf
        <input type="hidden" name="method" value="details">
        @if ($loginAttempt)
            <input type="hidden" name="login_attempt" value="{{ $loginAttempt }}">
        @endif
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" autocapitalize="none" spellcheck="false" value="{{ old('employee_id') }}" required autofocus @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')<div id="employee_id-error" class="error" role="alert">{{ $message }}</div>@enderror

        <label for="email">City email</label>
        <input id="email" name="email" type="email" inputmode="email" maxlength="254" autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="name@miamibeachfl.gov" value="{{ old('email') }}" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email')<div id="email-error" class="error" role="alert">{{ $message }}</div>@enderror

        @php($bidCycle = (string) config('identity.self_service_activation.bid_cycle_label', '2026–27'))
        <div id="bid-note" class="notice">
            <strong>Use your new {{ $bidCycle }} bid.</strong>
            Select the shift and unit you picked in the {{ $bidCycle }} bid, not the assignment you held under the previous bid.
            Use the {{ $bidCycle }} results even if you have not moved to that shift or unit yet.
        </div>

        <label for="shift">Shift you bid ({{ $bidCycle }} bid)</label>
        <select id="shift" name="shift" aria-describedby="bid-hint" @error('shift') aria-invalid="true" @enderror>
            <option value="" @selected(old('shift') === null)>Select your shift</option>
            @foreach ($bidChoices['shifts'] as $shift)
                <option value="{{ $shift }}" @selected(old('shift') === $shift)>{{ $shift }}</option>
            @endforeach
        </select>
        @error('shift')<div class="error" role="alert">{{ $message }}</div>@enderror

        <label for="unit">Unit you bid ({{ $bidCycle }} bid)</label>
        <select id="unit" name="unit" aria-describedby="bid-hint" @error('unit') aria-invalid="true" @enderror>
            <option value="" @selected(old('unit') === null)>Select your unit</option>
            @foreach ($bidChoices['units'] as $unit)
                <option value="{{ $unit }}" @selected(old('unit') === $unit)>{{ $unit }}</option>
            @endforeach
        </select>
        <div id="bid-hint" class="hint">Use your officially recorded {{ $bidCycle }} bid, rather than a temporary assignment, overtime, or shift exchange. If your record is outdated, use an email link instead.</div>
        @error('unit')<div class="error" role="alert">{{ $message }}</div>@enderror

        @if ($bidChoices['positions'] !== [])
            <details class="field-alternative" @if (old('position')) open @endif>
                <summary>My position does not bid</summary>
                <label for="position">Your position</label>
                <select id="position" name="position" aria-describedby="position-hint">
                    <option value="" @selected(old('position') === null)>Select your position</option>
                    @foreach ($bidChoices['positions'] as $position)
                        <option value="{{ $position }}" @selected(old('position') === $position)>{{ $position }}</option>
                    @endforeach
                </select>
                <div id="position-hint" class="hint">For administrative and staff positions. Leave shift and unit blank.</div>
            </details>
        @endif

        <label for="password">Create a password</label>
        <input id="password" name="password" type="password" maxlength="72" autocomplete="new-password" aria-describedby="password-hint" required @error('password') aria-invalid="true" @enderror>
        <div id="password-hint" class="hint">At least 8 characters.</div>
        @error('password')<div class="error" role="alert">{{ $message }}</div>@enderror

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" maxlength="72" autocomplete="new-password" required>

        <button type="submit">Activate account</button>
    </form>

    <p class="help small">Having trouble or missing your position? Contact MBFD Hub support to check your account and email on file. Do not create another account.</p>
</main>
</body>
</html>
