<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 28px 44px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; color: #1b2330; }
        .header { width: 100%; border-bottom: 2px solid #012766; margin-bottom: 8px; padding-bottom: 6px; }
        .header td { vertical-align: middle; }
        .logo { width: 52px; height: 52px; }
        h1 { font-size: 15pt; margin: 0; color: #012766; }
        h2 { font-size: 10pt; margin: 14px 0 3px 0; color: #012766; }
        .meta { font-size: 8pt; color: #475569; margin-top: 2px; }
        .meta strong { color: #1b2330; }
        .note { font-size: 7.5pt; color: #475569; margin: 0 0 4px 0; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th { background: #012766; color: #ffffff; font-size: 7pt; text-align: left; padding: 4px 4px; }
        table.data td { padding: 3px 4px; border-bottom: 1px solid #dce2e8; vertical-align: top; word-wrap: break-word; }
        table.data tbody tr:nth-child(even) td { background: #f3f5f8; }
        .num { text-align: right; }
        .empty { padding: 12px; text-align: center; color: #475569; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            @if ($logo)
                <td style="width: 60px;"><img class="logo" src="{{ $logo }}" alt="MBFD"></td>
            @endif
            <td>
                <h1>{{ $title }}</h1>
                @foreach ($facts as [$label, $value])
                    <div class="meta"><strong>{{ $label }}:</strong> {{ $value }}</div>
                @endforeach
            </td>
        </tr>
    </table>

    @foreach ($sections as $section)
        @if (count($sections) > 1 || $section->heading !== $title)
            <h2>{{ $section->heading }}</h2>
        @endif
        @if ($section->note)
            <p class="note">{{ $section->note }}</p>
        @endif
        <table class="data">
            <thead>
                <tr>
                    @foreach ($section->headers as $index => $header)
                        <th class="{{ in_array($index, $section->numericColumns, true) ? 'num' : '' }}">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($section->rows as $row)
                    <tr>
                        @foreach (array_values($row) as $index => $cell)
                            <td class="{{ in_array($index, $section->numericColumns, true) ? 'num' : '' }}">{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(1, count($section->headers)) }}" class="empty">Nothing to show here.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach
</body>
</html>
