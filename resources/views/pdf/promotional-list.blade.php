<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 32px 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #172b4d; font-size: 8px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p { margin: 0 0 3px; }
        table.list { width: 100%; border-collapse: collapse; margin-top: 10px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #d7dde5; padding: 4px; text-align: left; word-wrap: break-word; }
        th { background: #eaf0f7; }
        tbody tr:nth-child(even) { background: #f6f8fa; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p>Miami Beach Fire Department - {{ $about['List type'] }} - Effective {{ $about['Effective date'] }} - Expires {{ $about['Expires on'] }} - Status: {{ $about['Status'] }}</p>
    <p>Source document: {{ $about['Source document'] }}</p>
    <p>{{ $about['Entries'] }} entries - Generated {{ $about['Generated (ET)'] }}</p>
    <table class="list">
        <thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">No entries on this list.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
