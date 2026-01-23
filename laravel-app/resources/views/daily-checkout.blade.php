<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MBFD Daily Checkout</title>
    @viteReactRefresh
    @vite(['resources/js/daily-checkout/src/main.tsx', 'resources/js/daily-checkout/src/index.css'])
</head>
<body>
    <div id="root"></div>
</body>
</html>