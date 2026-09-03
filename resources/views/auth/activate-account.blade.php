<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate MBFD Hub Account</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; }
        body { box-sizing: border-box; margin: 0; min-height: 100vh; padding: 1rem; display: grid; place-items: center; background: #f1f5f9; color: #172033; }
        main { box-sizing: border-box; width: min(100%, 38rem); padding: clamp(1.25rem, 6vw, 2rem); border-radius: .75rem; background: white; box-shadow: 0 1rem 3rem rgba(15, 23, 42, .12); }
        h1, h2 { margin: 0 0 .5rem; }
        h2 { margin-top: 2rem; font-size: 1.2rem; }
        p { margin: 0 0 1rem; color: #475569; }
        label { display: block; margin-top: 1rem; font-weight: 700; }
        input[type="email"], input[type="password"] { box-sizing: border-box; width: 100%; min-height: 44px; margin-top: .4rem; padding: .75rem; border: 1px solid #94a3b8; border-radius: .4rem; font: inherit; }
        input:focus { outline: 3px solid #bfdbfe; border-color: #1d4ed8; }
        button { width: 100%; min-height: 44px; margin-top: 1rem; padding: .8rem; border: 0; border-radius: .4rem; background: #b91c1c; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        .secondary { background: #334155; }
        .warning { padding: .8rem; border-left: .25rem solid #b91c1c; background: #fef2f2; color: #7f1d1d; }
        .error { margin-top: .5rem; color: #b91c1c; font-weight: 700; }
        .check { display: flex; gap: .6rem; align-items: flex-start; font-weight: 400; }
    </style>
</head>
<body>
<main>
    <h1>Complete first-time Hub activation</h1>
    <p>Your Employee credential was verified. Choose the path that accurately describes your previous Hub access.</p>
    @error('legacy_email')<div class="error" role="alert">{{ $message }}</div>@enderror

    <h2>I previously had separate Hub access</h2>
    <p>Choose this for any prior Admin, Training, Workgroups, or other MBFD Hub account. Verifying that account preserves its access and history.</p>
    <form method="POST" action="{{ route('activate-account.store') }}">
        @csrf
        <input type="hidden" name="nonce" value="{{ $nonce }}">
        <input type="hidden" name="path" value="existing_user">
        <label for="legacy_email">Existing Hub email</label>
        <input id="legacy_email" name="legacy_email" type="email" maxlength="255" autocomplete="email" required>
        <label for="legacy_password">Existing Hub password</label>
        <input id="legacy_password" name="legacy_password" type="password" maxlength="4096" autocomplete="current-password" required>
        <button type="submit">Verify and preserve my existing Hub account</button>
    </form>

    <h2>I never had a separate Hub account</h2>
    <p class="warning">If you previously had Admin, Training, Workgroups, or other separate Hub access, use the existing-account path above or those privileges will not be attached.</p>
    <form method="POST" action="{{ route('activate-account.store') }}">
        @csrf
        <input type="hidden" name="nonce" value="{{ $nonce }}">
        <input type="hidden" name="path" value="no_existing_user">
        <label class="check"><input name="no_legacy_account_assertion" type="checkbox" value="1" required> I confirm that I have never had a separate MBFD Hub Admin, Training, Workgroups, or other Hub account.</label>
        <button class="secondary" type="submit">Create my normal Employee account</button>
    </form>
</main>
</body>
</html>
