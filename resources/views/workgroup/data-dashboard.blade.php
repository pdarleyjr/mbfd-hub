<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workgroup Data Dashboard</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/workgroup-data-dashboard.jsx'])
</head>
<body data-hub-ui="2" data-hub-portal="report" class="hub-report hub-report--data-dashboard">
    <div class="hub-report-navigation"><x-hub-header back-href="/workgroups/links" back-label="Back to Workgroup Links" max-width="max-w-7xl" /></div>
    <div id="workgroup-data-dashboard"></div>
    @include('components.hub-support-widget')
</body>
</html>
