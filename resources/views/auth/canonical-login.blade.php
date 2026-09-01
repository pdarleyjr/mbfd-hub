<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MBFD Hub Login</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f1f5f9; color: #172033; }
        main { width: min(90vw, 26rem); padding: 2rem; border-radius: .75rem; background: white; box-shadow: 0 1rem 3rem rgba(15, 23, 42, .12); }
        h1 { margin: 0 0 .5rem; font-size: 1.75rem; }
        p { margin: 0 0 1.5rem; color: #475569; }
        label { display: block; margin-top: 1rem; font-weight: 700; }
        input { box-sizing: border-box; width: 100%; margin-top: .4rem; padding: .75rem; border: 1px solid #94a3b8; border-radius: .4rem; font: inherit; }
        input:focus { outline: 3px solid #bfdbfe; border-color: #1d4ed8; }
        button { width: 100%; margin-top: 1.5rem; padding: .8rem; border: 0; border-radius: .4rem; background: #b91c1c; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        .error { margin-top: .5rem; color: #b91c1c; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <h1>MBFD Hub</h1>
    <p>Sign in with your Employee ID and MBFD Hub password.</p>
    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" maxlength="64" autocomplete="username" value="{{ old('employee_id') }}" required autofocus>
        @error('employee_id')
            <div class="error" role="alert">{{ $message }}</div>
        @enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" maxlength="4096" autocomplete="current-password" required>
        @error('password')
            <div class="error" role="alert">{{ $message }}</div>
        @enderror

        <button type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
