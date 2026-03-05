<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="theme-color" content="#0a0a0a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Pump Simulator | MBFD Training</title>
    <style>
        .pump-home-btn {
            position: fixed;
            top: max(12px, env(safe-area-inset-top));
            left: max(12px, env(safe-area-inset-left));
            z-index: 9999;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(185, 28, 28, 0.95);
            color: #ffffff;
            font: 600 14px/1.2 Inter, system-ui, -apple-system, sans-serif;
            text-decoration: none;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(4px);
        }

        .pump-home-btn:hover {
            background: rgba(153, 27, 27, 0.98);
        }

        .pump-home-btn:focus-visible {
            outline: 2px solid #ffffff;
            outline-offset: 2px;
        }
    </style>
    @vite(['resources/js/pump-simulator/main.tsx'])
</head>
<body style="margin:0;padding:0;background:#0a0a0a;overflow-x:hidden;">
    <a href="/" class="pump-home-btn" aria-label="Return to Home">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 12l9-9 9 9"></path>
            <path d="M9 21V9h6v12"></path>
        </svg>
        Home
    </a>
    <div id="pump-simulator-root"></div>
</body>
</html>
