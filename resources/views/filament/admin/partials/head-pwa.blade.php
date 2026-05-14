{{-- PWA head wiring — only emits the admin manifest link, theme color, and bootstrap JS --}}
<meta name="theme-color" content="#1e293b" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#FAFAF8" media="(prefers-color-scheme: light)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MBFD Admin">
<link rel="manifest" href="/admin-pwa/manifest.webmanifest" crossorigin="use-credentials">
<link rel="apple-touch-icon" sizes="180x180" href="/admin-pwa/icons/icon-192.png">

@vite(['resources/js/admin-pwa/main.ts'])
