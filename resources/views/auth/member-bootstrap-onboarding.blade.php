<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete your MBFD Hub account</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; }
        body { box-sizing: border-box; margin: 0; min-height: 100vh; padding: 1rem; display: grid; place-items: center; background: #f1f5f9; color: #172033; }
        main { box-sizing: border-box; width: min(100%, 31rem); padding: clamp(1.25rem, 6vw, 2rem); border-radius: .75rem; background: white; box-shadow: 0 1rem 3rem rgba(15, 23, 42, .12); }
        h1 { margin: 0 0 .5rem; font-size: clamp(1.5rem, 6vw, 1.9rem); }
        p { margin: 0 0 1rem; color: #475569; line-height: 1.45; }
        .identity { padding: .8rem; border-radius: .5rem; background: #f8fafc; border: 1px solid #cbd5e1; }
        label { display: block; margin-top: 1rem; font-weight: 700; }
        input { box-sizing: border-box; width: 100%; min-height: 44px; margin-top: .4rem; padding: .75rem; border: 1px solid #94a3b8; border-radius: .4rem; font: inherit; }
        input:focus { outline: 3px solid #bfdbfe; border-color: #1d4ed8; }
        .check { display: grid; grid-template-columns: 44px 1fr; align-items: center; gap: .25rem; font-weight: 400; }
        .check input { width: 22px; min-height: 22px; margin: 0 auto; }
        button { box-sizing: border-box; width: 100%; min-height: 44px; margin-top: 1.5rem; padding: .8rem; border: 0; border-radius: .4rem; background: #b91c1c; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        .cancel { background: transparent; color: #334155; border: 1px solid #94a3b8; margin-top: .75rem; }
        .error { margin-top: .4rem; color: #b91c1c; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <h1>Complete your MBFD Hub account</h1>
    <p>Review your City email, then create the private password you will use from now on.</p>
    <p class="identity"><strong>{{ $user->employeeProfile?->name ?? $user->name }}</strong><br>Employee ID {{ $user->employee_id }}</p>

    <form method="POST" action="{{ route('member-onboarding.store') }}">
        @csrf
        <label for="city_email">City Email</label>
        <input id="city_email" name="city_email" type="email" maxlength="254" autocomplete="email" value="{{ old('city_email', $cityEmail) }}" required @error('city_email') aria-invalid="true" aria-describedby="city_email-error" @enderror>
        @error('city_email')<div id="city_email-error" class="error" role="alert">{{ $message }}</div>@enderror

        <label class="check" for="city_email_confirmed">
            <input id="city_email_confirmed" name="city_email_confirmed" type="checkbox" value="1" {{ old('city_email_confirmed') ? 'checked' : '' }} required>
            <span>I confirm this is my current City email address.</span>
        </label>
        @error('city_email_confirmed')<div class="error" role="alert">{{ $message }}</div>@enderror

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
