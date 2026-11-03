<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Pair supply room screen · Miami Beach Fire Department</title>
    <style>
        :root { color-scheme: light dark; --bg: #f4f5f7; --card: #ffffff; --text: #111827; --muted: #4b5563; --accent: #b91c1c; --accent-text: #ffffff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f172a; --card: #1e293b; --text: #f1f5f9; --muted: #cbd5e1; --accent: #ef4444; --accent-text: #0f172a; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text); font: 20px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 560px; background: var(--card); border-radius: 16px; padding: 32px 24px; box-shadow: 0 1px 3px rgb(0 0 0 / .12); }
        h1 { margin: 0 0 12px; font-size: 28px; line-height: 1.2; }
        p { margin: 0 0 20px; color: var(--muted); }
        button { width: 100%; min-height: 64px; border: 0; border-radius: 12px; background: var(--accent); color: var(--accent-text); font: inherit; font-weight: 700; cursor: pointer; }
        button:focus-visible { outline: 3px solid var(--text); outline-offset: 3px; }
    </style>
</head>
<body>
<main>
    @if ($device)
        <h1>Pair this screen</h1>
        <p>This makes this browser the EMS supply room screen "{{ $device->name }}". Members will take supplies on it without signing in, so only pair the screen that stays in the locked supply room.</p>
        <form method="POST" action="{{ url('/supply-kiosk/pair/'.$token) }}">
            @csrf
            <button type="submit">Pair this screen</button>
        </form>
    @else
        <h1>This pairing link has ended</h1>
        <p>Pairing links work once and for 30 minutes. Make a new one from Admin &gt; Medical Supply Room &gt; Pair supply room screen.</p>
    @endif
</main>
</body>
</html>
