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
        button { box-sizing: border-box; width: 100%; min-height: 44px; margin-top: 1.5rem; padding: .8rem; border: 0; border-radius: .4rem; background: #b91c1c; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        .cancel { background: transparent; color: #334155; border: 1px solid #94a3b8; margin-top: .75rem; }
        .error { margin-top: .4rem; color: #b91c1c; font-weight: 700; }
    </style>
</head>
<body>
<main>
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
