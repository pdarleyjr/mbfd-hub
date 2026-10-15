<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Training Roster · Miami Beach Fire Department</title>
    @viteReactRefresh
    @vite('resources/js/training-roster/main.tsx')
</head>
<body>
    <div id="training-roster-root"></div>
    <noscript>Enable JavaScript to sign the training roster. Your instructor can assist with recording attendance.</noscript>
</body>
</html>
