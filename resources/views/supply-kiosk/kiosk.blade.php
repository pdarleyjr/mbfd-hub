<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#012766">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Supply room">
    <title>Supply Room · Miami Beach Fire Department</title>
    @viteReactRefresh
    @vite('resources/js/supply-kiosk/main.tsx')
</head>
<body>
    <div id="supply-kiosk-root"></div>
    <noscript>Turn on JavaScript to use the supply room screen. Tell Captain 5 what you took until it is back.</noscript>
</body>
</html>
