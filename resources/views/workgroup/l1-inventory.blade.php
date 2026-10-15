<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <x-hub.head />
    <title>Ladder 1 Inventory Modernization Dashboard</title>
    
    <script src="{{ asset('vendor/sheetjs-0.20.0/xlsx.full.min.js') }}"></script>

    @vite(['resources/css/app.css', 'resources/js/workgroup-l1-inventory.jsx'])
</head>
<body data-hub-ui="2" data-hub-portal="report" class="hub-report hub-report--l1-inventory">
    <div class="hub-report-navigation"><x-hub-header back-href="/workgroups/links" back-label="Back to Workgroup Links" max-width="max-w-7xl" /></div>
    <div id="root"></div>


    @include('components.hub-support-widget')
</body>
</html>
