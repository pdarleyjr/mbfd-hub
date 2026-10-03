<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Station {{ $station->station_number }} Inventory Record</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #172b4d; }
        h1 { font-size: 19px; } table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px; border-bottom: 1px solid #dce3ec; text-align: left; }
        th { background: #eef3f9; } .notes { white-space: pre-wrap; }
    </style>
</head>
<body>
    <h1>MBFD Station {{ $station->station_number }} Inventory Record</h1>
    <p>{{ $employee->name }} · {{ $employee->employee_id }} · Shift {{ $shift }}<br>{{ $submittedAt->timezone('America/New_York')->format('M j, Y g:i A T') }}</p>
    <p>This record preserves the saved on-hand counts at submission.</p>
    <table>
        <thead><tr><th>Category</th><th>Item</th><th>SKU</th><th>On hand</th><th>Par</th></tr></thead>
        <tbody>
        @foreach ($items as $item)
            <tr><td>{{ $item['category'] }}</td><td>{{ $item['name'] }}</td><td>{{ $item['sku'] }}</td><td>{{ $item['quantity'] }} {{ $item['unit'] }}</td><td>{{ $item['par'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @if ($notes)<h2>Notes</h2><p class="notes">{{ $notes }}</p>@endif
</body>
</html>
