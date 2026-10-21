<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MBFD Training Planning Proposal</title>
    <style>
        @page { margin: 32px 30px 48px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #18354b; }
        h1 { font-size: 17px; margin: 0 0 5px; }
        h2 { font-size: 12px; margin: 18px 0 5px; }
        p { margin: 4px 0; }
        .review { color: #805a14; }
        .meta { width: 100%; margin: 13px 0; border-top: 2px solid #18354b; }
        .meta td { padding: 4px 3px; vertical-align: top; }
        table.members { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .members th { background: #18354b; color: white; text-align: left; }
        .members th, .members td { padding: 6px 4px; vertical-align: top; overflow-wrap: break-word; }
        .members td { border-bottom: 1px solid #d9e0e5; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .footer { position: fixed; bottom: -27px; font-size: 7px; color: #52616c; }
        .page-number:after { content: counter(page); }
        .note { font-size: 8px; color: #52616c; }
    </style>
</head>
<body>
    <div class="footer">{{ $report['legend'] }} &nbsp; | &nbsp; Page <span class="page-number"></span></div>
    <h1>MBFD TRAINING - PROPOSED CLASS ATTENDANCE</h1>
    <p><strong>For Battalion Chief 300 review</strong></p>
    <table class="meta">
        <tr><td><strong>Course</strong><br>{{ $report['course_name'] }}</td><td><strong>Scope</strong><br>{{ $report['scope_label'] }}</td></tr>
        <tr><td><strong>Training date / shift</strong><br>{{ $report['schedule_date'] }} / {{ $report['shift'] }}</td><td><strong>Schedule generated</strong><br>{{ $report['printed_at'] }}</td></tr>
        <tr><td><strong>Prepared by</strong><br>{{ $report['author'] }}</td><td><strong>Proposal prepared</strong><br>{{ $report['generated_at'] }}</td></tr>
    </table>
    <p><strong>{{ $report['total'] }} unique proposed members</strong> &nbsp; | &nbsp; {{ $report['eligible_total'] }} assigned and eligible &nbsp; | &nbsp; {{ $report['unassigned_total'] }} unassigned &nbsp; | &nbsp; {{ $report['review_total'] }} need verification</p>
    <p class="note">Scheduled duty based on an uploaded snapshot. Confirm current operational availability. All class times are Eastern.</p>
    @foreach ($report['groups'] as $group)
        <h2>{{ $group['label'] }} - {{ count($group['rows']) }} proposed</h2>
        <p class="note">{{ $group['time'] }}</p>
        @if (count($group['rows']) === 0)
            <p class="note">No proposed attendees.</p>
        @else
            <table class="members">
                <thead><tr><th style="width:23%">Name / employee ID</th><th style="width:22%">Rank / published bid seat</th><th style="width:24%">Day-of-duty assignment</th><th style="width:31%">Planning status</th></tr></thead>
                <tbody>
                    @foreach ($group['rows'] as $row)
                        <tr><td>{{ $row['name'] }}<br>{{ $row['employee_id'] }}</td><td>{{ $row['rank'] }}<br>{{ $row['bid_position'] }}</td><td>{{ $row['station'] }}<br>{{ $row['apparatus'] }}</td><td>{{ $row['status'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
