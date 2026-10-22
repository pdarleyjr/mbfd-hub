<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 32px 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #172b4d; font-size: 8px; }
        h1 { font-size: 17px; margin: 0 0 6px; }
        p { margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #d7dde5; padding: 5px; text-align: left; word-wrap: break-word; }
        th { background: #eaf0f7; }
        tbody tr:nth-child(even) { background: #f6f8fa; }
        .column-section { page-break-before: always; }
    </style>
</head>
<body>
    @foreach(array_chunk($headers, 7, true) as $section => $sectionHeaders)
        <div @class(['column-section' => $section > 0])>
            <h1>{{ $title }}</h1>
            <p>Miami Beach Fire Department · {{ count($rows) }} members · Scope: {{ $scope }} · {{ $generated }}@if(count($headers) > 7) · Column group {{ $section + 1 }}@endif</p>
            <table>
                <thead><tr><th style="width: 24px">Row</th>@foreach($sectionHeaders as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse($rows as $rowIndex => $row)
                        @php
                            $fragments = [];
                            foreach ($sectionHeaders as $index => $header) {
                                $value = $row[$index];
                                $fragments[$index] = mb_str_split(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value, 350) ?: [''];
                            }
                            $fragmentCount = max(array_map('count', $fragments));
                        @endphp
                        @for($fragment = 0; $fragment < $fragmentCount; $fragment++)
                            <tr><td>{{ $rowIndex + 1 }}{{ $fragment > 0 ? ' (cont.)' : '' }}</td>@foreach($sectionHeaders as $index => $header)<td>{{ $fragments[$index][$fragment] ?? '' }}</td>@endforeach</tr>
                        @endfor
                    @empty
                        <tr><td colspan="{{ count($sectionHeaders) + 1 }}">No members in this export.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>
