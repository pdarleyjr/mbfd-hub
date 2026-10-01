<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ladder 1 Inventory Modernization Dashboard</title>
    
    <script src="{{ asset('vendor/sheetjs-0.20.0/xlsx.full.min.js') }}"></script>

    <style>
        body { background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .table-container { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); }
        .hub-report--l1-inventory #root > div > .bg-slate-900 { background: rgb(var(--hub-header)); }
        .hub-report--l1-inventory #root button.bg-blue-600 { min-height: 44px; background: rgb(var(--hub-action-primary)); }
        .hub-report--l1-inventory #root button.bg-blue-600:hover { background: rgb(var(--hub-action-primary-hover)); }
        .hub-report--l1-inventory #root button.bg-blue-600:focus-visible { outline: 3px solid rgb(var(--hub-surface)); outline-offset: 3px; }
        .hub-report--l1-inventory #root .bg-purple-100.text-purple-700 { background: rgb(var(--hub-surface-muted)); color: rgb(var(--hub-action-primary)); }
    </style>
    @vite(['resources/css/app.css', 'resources/js/workgroup-l1-inventory.jsx'])
</head>
<body data-hub-ui="2" data-hub-portal="report" class="hub-report hub-report--l1-inventory">
    <div class="hub-report-navigation"><x-hub-header back-href="/workgroups/links" back-label="Back to Workgroup Links" max-width="max-w-7xl" /></div>
    <div id="root"></div>


    @include('components.hub-support-widget')
</body>
</html>
