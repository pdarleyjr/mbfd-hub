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
        .meta { font-size: 8pt; color: #475569; margin-top: 3px; }
        .meta strong { color: #1b2330; }
        table.roster { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.roster thead { display: table-header-group; }
        table.roster tr { page-break-inside: avoid; }
        table.roster th { background: #012766; color: #ffffff; font-size: 7pt; text-align: left; padding: 4px 4px; }
        table.roster td { padding: 3px 4px; border-bottom: 1px solid #dce2e8; vertical-align: top; word-wrap: break-word; }
        table.roster tbody tr:nth-child(even) td { background: #f3f5f8; }
        .empty { padding: 18px; text-align: center; color: #475569; }
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
                <div class="meta">
                    <strong>Generated:</strong> {{ $generatedAt }} &nbsp;|&nbsp;
                    <strong>Records:</strong> {{ number_format($count) }}
                </div>
                <div class="meta">
                    <strong>Scope:</strong> {{ $scope }} &nbsp;|&nbsp;
                    <strong>Filters / search:</strong> {{ $filters }}
                </div>
            </td>
        </tr>
    </table>

    <table class="roster">
        <thead>
            <tr>
                <th style="width: 5%;">Veh #</th>
                <th style="width: 14%;">Year / Make / Model</th>
                <th style="width: 6%;">Color</th>
                <th style="width: 7%;">Plate</th>
                <th style="width: 13%;">VIN / Serial</th>
                <th style="width: 12%;">Category / Class</th>
                <th style="width: 5%;">FY27 status</th>
                <th style="width: 5%;">Dept</th>
                <th style="width: 15%;">Assigned / Driver</th>
                <th style="width: 18%;">Last inspection</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['veh'] }}</td>
                    <td>{{ $row['ymm'] }}</td>
                    <td>{{ $row['color'] }}</td>
                    <td>{{ $row['plate'] }}</td>
                    <td>{{ $row['vin'] }}</td>
                    <td>{{ $row['category'] }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ $row['dept'] }}</td>
                    <td>{{ $row['assigned'] }}</td>
                    <td>{{ $row['inspection'] }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="empty">No vehicles match this export.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
