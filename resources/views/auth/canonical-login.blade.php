<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MBFD Hub Login</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; }
        body { box-sizing: border-box; margin: 0; min-height: 100vh; padding: 1rem; display: grid; place-items: center; background: #f1f5f9; color: #172033; }
        main { box-sizing: border-box; width: min(100%, 26rem); padding: clamp(1.25rem, 6vw, 2rem); border-radius: .75rem; background: white; box-shadow: 0 1rem 3rem rgba(15, 23, 42, .12); }
        h1 { margin: 0 0 .5rem; font-size: 1.75rem; }
        p { margin: 0 0 1.5rem; color: #475569; }
        label { display: block; margin-top: 1rem; font-weight: 700; }
        input { box-sizing: border-box; width: 100%; min-height: 44px; margin-top: .4rem; padding: .75rem; border: 1px solid #94a3b8; border-radius: .4rem; font: inherit; }
        input:focus { outline: 3px solid #bfdbfe; border-color: #1d4ed8; }
        button, .identity-button { box-sizing: border-box; display: block; width: 100%; min-height: 44px; margin-top: 1.5rem; padding: .8rem; border: 0; border-radius: .4rem; background: #b91c1c; color: white; font: inherit; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; }
        button:focus-visible { outline: 3px solid #bfdbfe; outline-offset: 2px; }
        .identity-strip { margin: -1.25rem -1.25rem 1.5rem; padding: .7rem 1.25rem; border-radius: .75rem .75rem 0 0; background: #292524; color: #f5e7bd; font-size: .78rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; }
        .identity-button { background: #991b1b; }
        .divider { display: flex; align-items: center; gap: .75rem; margin: 1.25rem 0 0; color: #64748b; font-size: .8rem; }
        .divider::before, .divider::after { content: ''; height: 1px; flex: 1; background: #cbd5e1; }
        .error { margin-top: .5rem; color: #b91c1c; font-weight: 700; }
        .help { display: block; margin-top: 1rem; text-align: center; color: #1d4ed8; }
        @media (min-width: 30rem) { .identity-strip { margin: -2rem -2rem 1.5rem; padding-inline: 2rem; } }
    </style>
</head>
<body>
<main>
    <div class="identity-strip">MBFD Identity · Secure access</div>
    <h1>MBFD Hub</h1>
    @if (request()->query('session_expired') === '1')
        <p role="status">Your session has ended. Please sign in again. Unsaved changes were not submitted. Review the record after signing in.</p>
    @endif
    @if ($applicationLabel ?? null)
        <p>Sign in to continue to {{ $applicationLabel }}.</p>
    @endif
    @if ($identityLoginUrl ?? null)
        <p>Use MBFD Identity for secure department access.</p>
        <a class="identity-button" href="{{ $identityLoginUrl }}">Continue with MBFD Identity</a>
        <div class="divider" aria-hidden="true">Transition access</div>
    @else
        <p>Sign in with your Employee ID and MBFD Hub password.</p>
    @endif
    <form method="POST" action="{{ $loginAction ?? route('login.store') }}">
        @csrf
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" value="{{ old('employee_id') }}" required autofocus @error('employee_id') aria-invalid="true" aria-describedby="employee_id-error" @enderror>
        @error('employee_id')
            <div id="employee_id-error" class="error" role="alert">{{ $message }}</div>
        @enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" maxlength="4096" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
        @error('password')
            <div id="password-error" class="error" role="alert">{{ $message }}</div>
        @enderror

        <button type="submit">Sign in</button>
    </form>
    <a class="help" href="{{ route('password.request') }}">Forgot your password?</a>
</main>
</body>
</html>
