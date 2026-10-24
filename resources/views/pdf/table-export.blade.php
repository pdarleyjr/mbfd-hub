<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 36pt 36pt 44pt 36pt; }
        body { font-family: DejaVu Sans, sans-serif; color: #172b4d; font-size: 8pt; }
        h1 { font-size: 15pt; margin: 0 0 4pt; }
        .meta { margin: 0 0 8pt; color: #44546a; font-size: 8pt; line-height: 1.4; }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid thead { display: table-header-group; }
        table.grid tr { page-break-inside: avoid; }
        table.grid th, table.grid td { border: 0.5pt solid #c3ccd8; padding: 3pt 4pt; text-align: left; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
        table.grid th { background: #e6ecf3; font-weight: bold; }
        table.grid tbody tr:nth-child(even) td { background: #f5f7fa; }
        table.record { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 0 0 8pt; page-break-inside: avoid; }
        table.record td { border: 0.5pt solid #c3ccd8; padding: 3pt 4pt; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; font-size: 8.5pt; }
        table.record td.label { background: #eef2f7; font-weight: bold; width: 14%; }
        table.record td.value { width: 36%; }
        table.record td.record-title { background: #e6ecf3; font-weight: bold; font-size: 9.5pt; }
        .empty { padding: 8pt 0; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        Miami Beach Fire Department &middot; {{ number_format($rowCount) }} {{ $rowCount === 1 ? 'record' : 'records' }} &middot; {{ $scopeLabel }}<br>
        Generated {{ $generated }} by {{ $generatedBy }}
        @if($filterSummary !== [])<br>Filters and search: {{ implode('; ', $filterSummary) }}@endif
        @if($sortSummary)<br>Sort: {{ $sortSummary }}@endif
        @if($shortened)<br>Some long text is shortened in this PDF. The Excel export has the full text.@endif
        @if($layout === 'blocks')<br>This table has many columns, so each record is printed as its own block.@endif
    </div>

    @if($rowCount === 0)
        <div class="empty">No records matched this export.</div>
    @elseif($layout === 'table')
        <table class="grid">
            <thead>
                <tr>@foreach($headers as $index => $header)<th style="width: {{ $widths[$index] }}%">{{ $header }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    @else
        @foreach($rows as $rowIndex => $row)
            <table class="record">
                <tr><td class="record-title" colspan="4">{{ $rowIndex + 1 }}. {{ $headers[0] }}: {{ $row[0] !== '' ? $row[0] : '(blank)' }}</td></tr>
                @foreach(array_chunk(array_keys($headers), 2) as $pair)
                    <tr>
                        @foreach($pair as $index)
                            <td class="label">{{ $headers[$index] }}</td><td class="value">{{ $row[$index] }}</td>
                        @endforeach
                        @if(count($pair) === 1)<td class="label"></td><td class="value"></td>@endif
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endif
</body>
</html>
