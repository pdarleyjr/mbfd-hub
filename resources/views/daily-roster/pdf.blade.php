<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MBFD Daily Roster {{ $roster['displayedDate'] }}</title>
    <style>
        @page { margin: 78px 30px 44px 30px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; line-height: 1.3; color: #111827; }
        .page-header { position: fixed; top: -66px; left: 0; right: 0; height: 56px; background: #14284b; color: #ffffff; padding: 6px 10px; }
        .page-header table { width: 100%; border-collapse: collapse; }
        .page-header td { vertical-align: middle; border: 0; padding: 0; }
        .page-header .logo { width: 50px; }
        .page-header img { height: 44px; width: 44px; }
        .page-header .title { font-size: 14px; font-weight: bold; }
        .page-header .sub { font-size: 8.5px; color: #cbd5e1; }
        .page-header .right { text-align: right; font-size: 8.5px; line-height: 1.4; }
        .page-footer { position: fixed; bottom: -30px; left: 0; right: 0; height: 20px; border-top: 1px solid #cbd5e1; padding-top: 3px; font-size: 7.5px; color: #475569; }
        .page-footer table { width: 100%; border-collapse: collapse; }
        .page-footer td { border: 0; padding: 0; }
        .page-footer .right { text-align: right; }
        .pagenum:before { content: counter(page); }
        .notice { margin: 0 0 6px; padding: 5px 8px; background: #fef3c7; border-left: 3px solid #b45309; font-weight: bold; }
        .facts { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .facts td { padding: 3px 6px; border: 1px solid #e5e7eb; width: 25%; }
        .facts .label { display: block; font-size: 7px; color: #475569; }
        .facts .value { font-weight: bold; }
        .section { margin-bottom: 7px; }
        .section-title { background: #e8edf5; border-left: 3px solid #14284b; color: #14284b; font-size: 9.5px; font-weight: bold; padding: 2px 6px; }
        .section-title .count { font-size: 7.5px; font-weight: normal; color: #475569; }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid th { background: #f1f5f9; text-align: left; padding: 2px 4px; border-bottom: 1px solid #cbd5e1; font-size: 7px; color: #334155; }
        table.grid td { padding: 2px 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; word-wrap: break-word; }
        table.grid tbody { page-break-inside: avoid; }
        .col-rank { width: 15%; }
        .col-id { width: 7%; }
        .col-status { width: 13%; }
        .col-time { width: 6.5%; }
        .certifications { display: block; font-size: 7px; color: #475569; }
        .source-marker { display: block; font-size: 7px; color: #475569; }
        .code { display: inline-block; padding: 0 3px; font-size: 8px; }
        .code--dashed { border: 1px dashed #566579; }
        .legend { margin-top: 8px; page-break-inside: avoid; }
        .legend-title { font-weight: bold; margin-bottom: 3px; }
        .legend-item { display: inline-block; margin: 0 10px 3px 0; }
        .legend-meaning { color: #475569; font-size: 7.5px; }
    </style>
</head>
<body>
    <div class="page-header">
        <table>
            <tr>
                @if($logo !== null)
                    <td class="logo"><img src="{{ $logo }}" alt=""></td>
                @endif
                <td>
                    <div class="title">Daily Roster</div>
                    <div class="sub">{{ implode(' · ', $roster['facts']['division']) }}</div>
                </td>
                <td class="right">
                    {{ $roster['displayedDateLabel'] }}<br>
                    {{ $roster['summary']['shift'] }}
                </td>
            </tr>
        </table>
    </div>
    <div class="page-footer">
        <table>
            <tr>
                <td>MBFD Hub. Personnel information for department use. Exported {{ $exportedAt }}.</td>
                <td class="right">Page <span class="pagenum"></span></td>
            </tr>
        </table>
    </div>

    @if($roster['notice'] !== null)
        <p class="notice">{{ $roster['notice'] }}</p>
    @endif

    <table class="facts">
        <tr>
            <td><span class="label">Duty window</span><span class="value">{{ $roster['facts']['window'] }}</span></td>
            <td><span class="label">Report generated</span><span class="value">{{ $roster['facts']['generated'] }}</span></td>
            <td><span class="label">Last updated</span><span class="value">{{ $roster['facts']['updated'] }}</span></td>
            <td><span class="label">Roster</span><span class="value">{{ $roster['summary']['sections'] }} sections, {{ $roster['summary']['entries'] }} entries, version {{ $roster['summary']['version'] }}</span></td>
        </tr>
    </table>

    @foreach($roster['sections'] as $section)
        <div class="section">
            <div class="section-title">{{ $section['title'] }} <span class="count">{{ $section['entryCount'] }} {{ $section['entryCount'] === 1 ? 'entry' : 'entries' }}</span></div>
            <table class="grid">
                <thead>
                    <tr>
                        <th class="col-rank">Rank</th>
                        <th class="col-id">ID</th>
                        <th>Member / Certifications</th>
                        <th class="col-status">Status</th>
                        <th class="col-time">From</th>
                        <th class="col-time">Through</th>
                        <th class="col-time">Hours</th>
                    </tr>
                </thead>
                @foreach($section['seats'] as $seat)
                    <tbody>
                        @foreach($seat as $row)
                            <tr>
                                <td>{{ $row['rank'] }}</td>
                                <td>{{ $row['employeeId'] }}</td>
                                <td>
                                    {{ $row['name'] }}
                                    @if($row['sourceMarker'] !== '')<span class="source-marker">TeleStaff shows {{ $row['sourceMarker'] }}</span>@endif
                                    @if($row['certifications'] !== [])<span class="certifications">{{ implode('/ ', $row['certifications']) }}</span>@endif
                                </td>
                                <td>@include('daily-roster._pdf_code', ['pill' => $row['pill']])</td>
                                <td>{{ $row['from'] }}</td>
                                <td>{{ $row['through'] }}</td>
                                <td>{{ $row['hours'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    @endforeach

    <div class="legend">
        <div class="legend-title">Status legend</div>
        @foreach($roster['legend'] as $pill)
            <span class="legend-item">@include('daily-roster._pdf_code', ['pill' => $pill]) <span class="legend-meaning">{{ $pill->meaning }}</span></span>
        @endforeach
    </div>
</body>
</html>
