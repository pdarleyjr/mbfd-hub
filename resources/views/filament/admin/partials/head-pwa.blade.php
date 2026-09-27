{{-- PWA head wiring — only emits the admin manifest link, theme color, and bootstrap JS --}}
<meta name="theme-color" content="#102A43">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MBFD Hub">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" sizes="180x180" href="/admin-pwa/icons/icon-192.png">
@auth
    <meta name="mbfd-admin-authenticated" content="true">
@endauth

@vite(['resources/js/admin-pwa/main.ts'])
