<!DOCTYPE html>
<html lang="en" data-hub-ui="2">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>{{ $code }} — {{ $title }} | MBFD Hub</title>
    <link rel="stylesheet" href="/css/hub-wallpaper-v1.css">
    {{-- Error pages must render even when the build is missing: a failed stylesheet lookup degrades to unstyled text. --}}
    @php
        try {
            $hubErrorStyles = app(\Illuminate\Foundation\Vite::class)('resources/css/app.css');
        } catch (\Throwable) {
            $hubErrorStyles = '';
        }
    @endphp
    {!! $hubErrorStyles !!}
    <style>
        .hub-error { min-height: 100vh; padding: 24px; display: grid; place-items: center; font-family: var(--hub-font-sans); }
        .hub-error__card { width: min(100%, 38rem); padding: clamp(20px, 5vw, 32px); }
        .hub-error__identity { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; color: rgb(var(--hub-header)); font-weight: 700; }
        .hub-error__identity img { width: 48px; height: 48px; }
        .hub-error__code { margin: 0 0 8px; color: rgb(var(--hub-n-600)); font-size: 14px; font-weight: 700; letter-spacing: .08em; }
        .hub-error h1 { margin: 0; color: rgb(var(--hub-header)); font-size: clamp(28px, 6vw, 40px); line-height: 1.2; letter-spacing: -.025em; }
        .hub-error__message { margin: 20px 0 28px; color: rgb(var(--hub-n-600)); line-height: 1.6; }
        .hub-error__actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
        .hub-error__footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid rgb(var(--hub-glass-border)); color: rgb(var(--hub-n-600)); font-size: 13px; }
    </style>
</head>
<body class="hub-error">
    <main class="hub-panel hub-error__card">
        <div class="hub-error__identity"><img src="/images/mbfd_logo-256.png" alt="" width="48" height="48">MBFD Hub</div>
        <p class="hub-error__code">ERROR {{ $code }}</p>
        <h1>{{ $title }}</h1>
        <p class="hub-error__message">{{ $message }}</p>
        @if($code !== 404)
            <p class="hub-callout hub-callout--warning p-4">If this is an active incident, contact the duty officer directly.</p>
        @endif
        <div class="hub-error__actions">
            <a class="hub-btn hub-btn--primary" href="{{ url('/') }}">Return to Hub home</a>
            @if($code !== 404)
                <a class="hub-btn hub-btn--secondary" href="{{ url()->current() }}">Try again</a>
            @endif
        </div>
        <div class="hub-error__footer">Miami Beach Fire Department · Support Services Division</div>
    </main>
</body>
</html>
