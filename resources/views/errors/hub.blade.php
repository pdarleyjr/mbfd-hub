<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102A43">
    <title>{{ $code }} — {{ $title }} | MBFD Hub</title>
    <link rel="stylesheet" href="/css/hub-wallpaper-v1.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 24px; display: grid; place-items: center; background: #fff; color: #102a43; font: 16px/1.6 system-ui, sans-serif; }
        main { width: min(100%, 38rem); }
        .identity { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; font-weight: 650; }
        .identity img { width: 48px; height: 48px; }
        .code { margin: 0 0 8px; color: #475569; font-size: 14px; font-weight: 650; letter-spacing: .08em; }
        h1 { margin: 0; font-size: clamp(28px, 6vw, 40px); line-height: 1.2; letter-spacing: -.025em; }
        .message { margin: 20px 0 28px; color: #475569; }
        .notice { padding: 16px; border: 1px solid #fde68a; border-radius: 6px; background: #fffbeb; color: #92400e; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
        a { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 18px; border: 1px solid #dce2e8; border-radius: 6px; color: #1e4e8c; font-weight: 650; text-decoration: none; }
        a.primary { border-color: #1e4e8c; background: #1e4e8c; color: #fff; }
        a:hover { text-decoration: underline; text-underline-offset: 3px; }
        a:focus-visible { outline: 3px solid #1d4ed8; outline-offset: 3px; }
        footer { margin-top: 48px; padding-top: 20px; border-top: 1px solid #dce2e8; color: #475569; font-size: 13px; }
    </style>
</head>
<body>
    <main>
        <div class="identity"><img src="/images/mbfd_logo-256.png" alt="" width="48" height="48">MBFD Hub</div>
        <p class="code">ERROR {{ $code }}</p>
        <h1>{{ $title }}</h1>
        <p class="message">{{ $message }}</p>
        @if($code !== 404)
            <p class="notice">If this is an active incident, contact the duty officer directly.</p>
        @endif
        <div class="actions">
            <a class="primary" href="{{ url('/') }}">Return to Hub home</a>
            @if($code !== 404)
                <a href="{{ url()->current() }}">Try again</a>
            @endif
        </div>
        <footer>Miami Beach Fire Department · Support Services Division</footer>
    </main>
</body>
</html>
